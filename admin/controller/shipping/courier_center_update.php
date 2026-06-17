<?php
namespace Opencart\Admin\Controller\Extension\Couriercenter\Shipping;

/**
 * GitHub-based auto-updater (WooCommerce-style "update available" + one click).
 *
 * Set GITHUB_REPO below to "owner/repo" to enable it. Then publish GitHub
 * Releases tagged like "v1.0.1". The version is read from install.json.
 *
 *  - check()  compares install.json version with the latest GitHub release.
 *  - apply()  downloads the release zip, BACKS UP the current files, copies the
 *             new files over extension/couriercenter/, and rolls back on error.
 */
class CourierCenterUpdate extends \Opencart\System\Engine\Controller {

    // Repo that hosts the releases for the auto-updater.
    // Empty string = updater disabled (no checks happen).
    const GITHUB_REPO = 'couriercenter/courier-center-for-opencart4';

    private function currentVersion(): string {
        $f = DIR_EXTENSION . 'couriercenter/install.json';
        if (is_file($f)) {
            $j = json_decode((string)file_get_contents($f), true);
            if (!empty($j['version'])) return (string)$j['version'];
        }
        return '0.0.0';
    }

    public function check(): void {
        $this->response->addHeader('Content-Type: application/json');
        if (!$this->user->hasPermission('modify', 'extension/couriercenter/shipping/courier_center')) {
            $this->response->setOutput(json_encode(['success' => false, 'error' => 'No permission']));
            return;
        }
        if (self::GITHUB_REPO === '') {
            $this->response->setOutput(json_encode(['success' => true, 'enabled' => false, 'current' => $this->currentVersion()]));
            return;
        }

        $rel = $this->fetchLatestRelease();
        if (isset($rel['error'])) {
            $this->response->setOutput(json_encode(['success' => false, 'error' => $rel['error']]));
            return;
        }

        $current = $this->currentVersion();
        $latest  = ltrim((string)($rel['tag_name'] ?? ''), 'vV');
        $update  = ($latest !== '' && version_compare($latest, $current, '>'));

        $this->response->setOutput(json_encode([
            'success'          => true,
            'enabled'          => true,
            'current'          => $current,
            'latest'           => $latest,
            'update_available' => $update,
            'notes'            => mb_substr((string)($rel['body'] ?? ''), 0, 1200),
        ]));
    }

    public function apply(): void {
        @set_time_limit(0);
        $this->response->addHeader('Content-Type: application/json');
        if (!$this->user->hasPermission('modify', 'extension/couriercenter/shipping/courier_center')) {
            $this->response->setOutput(json_encode(['success' => false, 'error' => 'No permission']));
            return;
        }
        if (self::GITHUB_REPO === '') {
            $this->response->setOutput(json_encode(['success' => false, 'error' => 'Ο updater δεν έχει ρυθμιστεί (GITHUB_REPO κενό).']));
            return;
        }

        try {
            $rel    = $this->fetchLatestRelease();
            if (isset($rel['error'])) throw new \Exception($rel['error']);
            $latest  = ltrim((string)($rel['tag_name'] ?? ''), 'vV');
            $current = $this->currentVersion();
            if (!($latest !== '' && version_compare($latest, $current, '>'))) {
                throw new \Exception('Δεν υπάρχει νεότερη έκδοση (τρέχουσα: ' . $current . ').');
            }

            // Prefer an attached .zip asset; otherwise the source zipball.
            $zip_url = '';
            foreach (($rel['assets'] ?? []) as $a) {
                if (!empty($a['browser_download_url']) && stripos((string)$a['name'], '.zip') !== false) {
                    $zip_url = (string)$a['browser_download_url'];
                    break;
                }
            }
            if ($zip_url === '') $zip_url = (string)($rel['zipball_url'] ?? '');
            if ($zip_url === '') throw new \Exception('Δεν βρέθηκε zip στο release.');

            $tmp = DIR_STORAGE . 'cc_update/';
            $this->rrmdir($tmp);
            mkdir($tmp, 0755, true);

            $zip_file = $tmp . 'release.zip';
            $this->download($zip_url, $zip_file);

            $za = new \ZipArchive();
            if ($za->open($zip_file) !== true) throw new \Exception('Μη έγκυρο zip αρχείο.');
            $za->extractTo($tmp . 'extracted/');
            $za->close();

            $src = $this->findPayload($tmp . 'extracted/');
            if (!$src) throw new \Exception('Δεν βρέθηκαν τα αρχεία του extension μέσα στο zip.');

            $dest   = rtrim(DIR_EXTENSION, '/') . '/couriercenter';
            $backup = DIR_STORAGE . 'cc_backup_' . date('YmdHis');
            $this->rcopy($dest, $backup);

            try {
                $this->rcopy($src, $dest);
            } catch (\Throwable $e) {
                $this->rrmdir($dest);
                $this->rcopy($backup, $dest);
                throw new \Exception('Αποτυχία αντιγραφής — έγινε επαναφορά από backup. ' . $e->getMessage());
            }

            $this->rrmdir($tmp);

            $this->response->setOutput(json_encode([
                'success' => true,
                'version' => $latest,
                'message' => 'Ενημερώθηκε σε έκδοση ' . $latest . '. Αν προστέθηκαν νέα events, τρέξε μία φορά το setup.php. Backup: ' . basename($backup),
            ]));
        } catch (\Throwable $e) {
            $this->response->setOutput(json_encode(['success' => false, 'error' => $e->getMessage()]));
        }
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function fetchLatestRelease(): array {
        $url = 'https://api.github.com/repos/' . self::GITHUB_REPO . '/releases/latest';
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => ['User-Agent: CourierCenter-OC-Updater', 'Accept: application/vnd.github+json'],
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err)         return ['error' => 'Σφάλμα σύνδεσης GitHub: ' . $err];
        if ($code === 404) return ['error' => 'Δεν βρέθηκε release (404). Δημοσίευσε πρώτα ένα Release στο GitHub.'];
        if ($code !== 200) return ['error' => 'GitHub HTTP ' . $code];

        $j = json_decode((string)$body, true);
        return is_array($j) ? $j : ['error' => 'Μη έγκυρη απάντηση GitHub.'];
    }

    private function download(string $url, string $dest): void {
        $fp = fopen($dest, 'w');
        if (!$fp) throw new \Exception('Δεν μπορώ να γράψω στο storage.');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 180,
            CURLOPT_HTTPHEADER     => ['User-Agent: CourierCenter-OC-Updater'],
        ]);
        $ok  = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fp);
        if (!$ok) throw new \Exception('Αποτυχία λήψης: ' . $err);
    }

    /** Find the folder that holds admin/ + catalog/ inside the extracted zip. */
    private function findPayload(string $dir): ?string {
        $dir = rtrim($dir, '/');
        if (is_dir($dir . '/admin') && is_dir($dir . '/catalog')) return $dir;
        foreach (glob($dir . '/*', GLOB_ONLYDIR) ?: [] as $d) {
            if (is_dir($d . '/admin') && is_dir($d . '/catalog')) return $d;
            if (is_dir($d . '/extension/couriercenter/admin')) return $d . '/extension/couriercenter';
            foreach (glob($d . '/*', GLOB_ONLYDIR) ?: [] as $d2) {
                if (is_dir($d2 . '/admin') && is_dir($d2 . '/catalog')) return $d2;
            }
        }
        return null;
    }

    private function rcopy(string $src, string $dst): void {
        if (is_file($src)) { if (!copy($src, $dst)) throw new \Exception('copy failed: ' . basename($src)); return; }
        if (!is_dir($dst) && !mkdir($dst, 0755, true) && !is_dir($dst)) throw new \Exception('mkdir failed: ' . $dst);
        foreach (scandir($src) as $f) {
            if ($f === '.' || $f === '..') continue;
            $this->rcopy($src . '/' . $f, $dst . '/' . $f);
        }
    }

    private function rrmdir(string $dir): void {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..') continue;
            $p = $dir . '/' . $f;
            is_dir($p) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
