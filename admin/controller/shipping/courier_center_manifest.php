<?php
namespace Opencart\Admin\Controller\Extension\Couriercenter\Shipping;

require_once DIR_EXTENSION . 'couriercenter/library/oc4/loader.php';

use Opencart\Extension\Couriercenter\Library\CCPickupPoints;
use Opencart\Extension\Couriercenter\Library\Oc4\CCOc4Setup;

/** Daily pickup manifest (Courier Center), optionally per pickup point (CustomerAccount). */
class CourierCenterManifest extends \Opencart\System\Engine\Controller {
    use \Opencart\Extension\Couriercenter\Library\Oc4\CCOc4Controller;

    const ROUTE = 'extension/couriercenter/shipping/courier_center_manifest';

    public function index(): void {
        $this->document->setTitle('Manifest Παραλαβής — Courier Center');
        $today = date('Y-m-d');
        $selected = $this->validDate(trim((string)($this->request->get['manifest_date'] ?? $today)), $today);
        $points = CCPickupPoints::fromConfig($this->config);
        $account = $this->knownAccount($points, (string)($this->request->get['account'] ?? ''));
        $point_options = [];
        if ($points->hasSubs()) {
            foreach ($points->all() as $pt) {
                $point_options[] = ['code' => $pt['code'], 'label' => CCPickupPoints::label($pt)];
            }
        }
        $main = $points->main()['code'];
        $sql = "SELECT COUNT(*) AS total FROM `" . DB_PREFIX . "cc_shipments` WHERE DATE(`created_at`) = '" . $this->db->escape($selected) . "' AND `voucher_number` != '' AND `is_voided` = 0";
        if ($account !== '') {
            $a = $this->db->escape($account);
            // The main point also counts the vouchers made before pickup points existed.
            $sql .= $account === $main ? " AND (`pickup_point` = '" . $a . "' OR `pickup_point` = '' OR `pickup_point` IS NULL)" : " AND `pickup_point` = '" . $a . "'";
        }
        $count = (int)($this->db->query($sql)->row['total'] ?? 0);
        $args = 'date=' . $selected . ($account !== '' ? '&account=' . urlencode($account) : '');

        $data = [
            'selected_date'     => $selected,
            'selected_account'  => $account,
            'point_options'     => $point_options,
            'today'             => $today,
            'tomorrow'          => date('Y-m-d', strtotime('+1 day')),
            'yesterday'         => date('Y-m-d', strtotime('-1 day')),
            'day_before'        => date('Y-m-d', strtotime('-2 days')),
            'vouchers_count'    => $count,
            'greek_date'        => $this->formatGreekDate($selected),
            'user_token'        => $this->session->data['user_token'],
            'route'             => self::ROUTE,
            'url_download'      => $this->ccLink(self::ROUTE . '.download', $args),
            'url_download_save' => $this->ccLink(self::ROUTE . '.download', $args . '&save=1'),
            'url_self'          => $this->ccLink(self::ROUTE, $account !== '' ? 'account=' . urlencode($account) : ''),
            'url_settings'      => $this->ccLink(CCOc4Setup::BASE_ROUTE),
        ];
        $data['breadcrumbs'] = [
            ['text' => 'Αρχική', 'href' => $this->ccLink('common/dashboard')],
            ['text' => 'Courier Center', 'href' => $data['url_settings']],
            ['text' => 'Manifest Παραλαβής', 'href' => $this->ccLink(self::ROUTE)],
        ];
        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');
        $this->response->setOutput($this->load->view('extension/couriercenter/shipping/manifest', $data));
    }

    public function download(): void {
        $date = $this->validDate(trim((string)($this->request->get['date'] ?? '')), date('Y-m-d'));
        $svc = $this->ccService();
        $account = $this->knownAccount($svc->points(), (string)($this->request->get['account'] ?? ''));
        $pdf = $svc->api()->get_manifest($date, $account);
        if (is_array($pdf)) {
            $this->ccTextError(500, 'Σφάλμα λήψης manifest: ' . ($pdf['error'] ?? 'Άγνωστο σφάλμα'));
        }
        $this->ccSendPdf($pdf, 'manifest-' . $date . ($account !== '' ? '-' . $account : '') . '.pdf', !empty($this->request->get['save']) ? 'attachment' : 'inline');
    }

    private function validDate(string $date, string $fallback): string {
        $d = \DateTime::createFromFormat('Y-m-d', $date);
        return ($d && $d->format('Y-m-d') === $date) ? $date : $fallback;
    }

    /** A pickup point code the shop knows, else '' (= all points, no filter). */
    private function knownAccount(CCPickupPoints $points, string $raw): string {
        $raw = trim($this->ccText($raw));
        return ($raw !== '' && $points->hasSubs() && $points->get($raw) !== null) ? $raw : '';
    }

    private function formatGreekDate(string $date): string {
        $months = ['01' => 'Ιανουαρίου', '02' => 'Φεβρουαρίου', '03' => 'Μαρτίου', '04' => 'Απριλίου', '05' => 'Μαΐου', '06' => 'Ιουνίου',
            '07' => 'Ιουλίου', '08' => 'Αυγούστου', '09' => 'Σεπτεμβρίου', '10' => 'Οκτωβρίου', '11' => 'Νοεμβρίου', '12' => 'Δεκεμβρίου'];
        $parts = explode('-', $date);
        return count($parts) === 3 ? (int)$parts[2] . ' ' . ($months[$parts[1]] ?? $parts[1]) . ' ' . $parts[0] : $date;
    }
}
