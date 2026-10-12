<?php
/**
 * Returns settings (shipping_courier_center_returns_* in OpenCart's setting table).
 * Port of CC_Returns_Settings (WooCommerce 1.6.0). Shared by OpenCart 3 and 4, PHP 7.2+.
 */

namespace Opencart\Extension\Couriercenter\Library;

class CCReturnsSettings {

    const P = 'shipping_courier_center_returns_';

    const DEFAULT_DROPOFF_TEXT = 'Συσκευάστε καλά το δέμα και παραδώστε το σε οποιοδήποτε κατάστημα Courier Center αναφέροντας τον αριθμό αποστολής επιστροφής.';
    const DEFAULT_BOXNOW_TEXT  = 'Συσκευάστε καλά το δέμα και παραδώστε το σε ένα BOX NOW locker χρησιμοποιώντας τον κωδικό BOX NOW επιστροφής.';
    const DEFAULT_PICKUP_TEXT  = 'Ο courier της Courier Center θα παραλάβει το δέμα από τη διεύθυνση που δηλώσατε, την ημερομηνία που επιλέξατε. Έχετε το δέμα συσκευασμένο και έτοιμο.';
    const WEIGHT_MIN = 0.1;
    const WEIGHT_MAX = 30.0;

    /** The setting keys (without prefix) the settings form posts. */
    const KEYS = ['enabled', 'require_approval', 'window_days', 'method_dropoff', 'method_pickup', 'method_boxnow', 'accounts', 'weight',
        'dropoff_instructions', 'pickup_instructions', 'boxnow_instructions', 'admin_notify', 'admin_emails',
        'email_received', 'email_created', 'email_rejected'];

    /** @var object */
    private $config;

    public function __construct($config) {
        $this->config = $config;
    }

    private function raw(string $key) {
        return $this->config->get(self::P . $key);
    }

    private function flag(string $key, string $default): bool {
        $v = $this->raw($key);
        return (string)($v === null || $v === '' ? $default : $v) === '1';
    }

    private function text(string $key): string {
        $v = $this->raw($key);
        return is_array($v) || $v === null ? '' : html_entity_decode((string)$v, ENT_QUOTES, 'UTF-8');
    }

    public function enabled(): bool {
        return $this->flag('enabled', '0');
    }

    public function requireApproval(): bool {
        return $this->flag('require_approval', '1');
    }

    /** Days after the order was completed; 0 = no limit. */
    public function windowDays(): int {
        $v = $this->raw('window_days');
        return ($v === null || $v === '') ? 14 : max(0, (int)$v);
    }

    /** Enabled methods in METHODS_ORDER. */
    public function enabledMethods(): array {
        $on = [
            CCReturnsRules::METHOD_DROPOFF => $this->flag('method_dropoff', '1'),
            CCReturnsRules::METHOD_PICKUP  => $this->flag('method_pickup', '0'),
            CCReturnsRules::METHOD_BOXNOW  => $this->flag('method_boxnow', '0'),
        ];
        $out = [];
        foreach (CCReturnsRules::METHODS_ORDER as $m) {
            if (!empty($on[$m])) {
                $out[] = $m;
            }
        }
        return $out;
    }

    /** Parcel weight in kg, 0.1-30 (invalid → 1). */
    public function weight(): float {
        return self::clampWeight($this->raw('weight'));
    }

    public function dropoffInstructions(): string {
        $t = $this->text('dropoff_instructions');
        return trim($t) === '' ? self::DEFAULT_DROPOFF_TEXT : $t;
    }

    public function boxnowInstructions(): string {
        $t = $this->text('boxnow_instructions');
        return trim($t) === '' ? self::DEFAULT_BOXNOW_TEXT : $t;
    }

    public function pickupInstructions(): string {
        $t = $this->text('pickup_instructions');
        return trim($t) === '' ? self::DEFAULT_PICKUP_TEXT : $t;
    }

    /** The customer instructions of a method (account page, "created" email). */
    public function instructionsFor(string $method): string {
        if ($method === CCReturnsRules::METHOD_BOXNOW) {
            return $this->boxnowInstructions();
        }
        if ($method === CCReturnsRules::METHOD_PICKUP) {
            return $this->pickupInstructions();
        }
        return $this->dropoffInstructions();
    }

    /** Customer email of an event (received | created | rejected) is on (default yes) - like WooCommerce's Emails settings. */
    public function customerEmailEnabled(string $event): bool {
        return $this->flag('email_' . $event, '1');
    }

    /** Email «Νέο αίτημα επιστροφής» to the shop (default on). */
    public function adminNotify(): bool {
        return $this->flag('admin_notify', '1');
    }

    /**
     * Recipients of the shop email: the saved list, else $shop_email.
     * @return string[]
     */
    public function adminEmails(string $shop_email): array {
        $out = self::splitEmails($this->text('admin_emails'));
        if (!$out) {
            $out = self::splitEmails($shop_email);
        }
        return $out;
    }

    /** @return string[] the valid addresses of a comma / semicolon / space separated list */
    public static function splitEmails(string $list): array {
        $out = [];
        foreach (preg_split('/[\s,;]+/', $list) as $e) {
            $e = trim($e);
            if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL) && !in_array($e, $out, true)) {
                $out[] = $e;
            }
        }
        return $out;
    }

    /** @return array[] rows ['original' => ..., 'return' => ...] */
    public function accountRows(): array {
        $rows = $this->raw('accounts');
        if (is_string($rows)) {
            $rows = json_decode(html_entity_decode($rows, ENT_QUOTES, 'UTF-8'), true);
        }
        $clean = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row) && !empty($row['original']) && !empty($row['return'])) {
                $clean[] = ['original' => trim((string)$row['original']), 'return' => trim((string)$row['return'])];
            }
        }
        return $clean;
    }

    /** The returns account mapped to the original shipper code ('' when none). */
    public function returnAccountFor(string $original_code): string {
        foreach ($this->accountRows() as $row) {
            if (CCReturnsRules::codes_equal($row['original'], $original_code)) {
                return $row['return'];
            }
        }
        return '';
    }

    public static function clampWeight($raw): float {
        $raw = is_string($raw) ? str_replace(',', '.', trim($raw)) : $raw;
        if (!is_numeric($raw) || (float)$raw <= 0) {
            return 1.0;
        }
        return (float)min(self::WEIGHT_MAX, max(self::WEIGHT_MIN, (float)$raw));
    }

    /**
     * Clean the posted account-mapping rows. An empty returns code drops the row silently.
     * @param string[] $known_codes pickup point codes (main + sub-codes)
     * @return array ['rows' => array[], 'errors' => string[]]
     */
    public static function sanitizeAccounts($rows, array $known_codes): array {
        $clean = [];
        $errors = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $original = trim(strip_tags(html_entity_decode((string)($row['original'] ?? ''), ENT_QUOTES, 'UTF-8')));
            $return = trim(strip_tags(html_entity_decode((string)($row['return'] ?? ''), ENT_QUOTES, 'UTF-8')));
            if ($return === '') {
                continue;
            }
            $canonical = '';
            foreach ($known_codes as $known) {
                if (CCReturnsRules::codes_equal($known, $original)) {
                    $canonical = (string)$known;
                    break;
                }
            }
            if ($canonical === '') {
                $errors[] = sprintf('Ο αρχικός κωδικός «%s» δεν αντιστοιχεί σε γνωστό σημείο αποστολής και η γραμμή αγνοήθηκε.', $original);
                continue;
            }
            if (!CCReturnsRules::is_valid_account_code($return)) {
                $errors[] = sprintf('Ο κωδικός επιστροφών «%s» δεν έχει έγκυρη μορφή (π.χ. 100-0120-0001) και η γραμμή αγνοήθηκε.', $return);
                continue;
            }
            foreach ($clean as $existing) {
                if (CCReturnsRules::codes_equal($existing['original'], $canonical)) {
                    $errors[] = sprintf('Ο αρχικός κωδικός %s υπάρχει δύο φορές· κρατήθηκε μόνο η πρώτη γραμμή.', $canonical);
                    continue 2;
                }
            }
            $clean[] = ['original' => $canonical, 'return' => $return];
        }
        return ['rows' => $clean, 'errors' => $errors];
    }

    /**
     * Normalise the posted returns settings in place (flags '0'/'1', days, weight, account rows).
     * @return string[] warnings for the admin
     */
    public static function sanitizePost(array &$post, array $known_codes): array {
        // A field the form did not send gets the safe default (approval on, Drop Off on), never "off".
        foreach (['enabled' => '0', 'require_approval' => '1', 'method_dropoff' => '1', 'method_pickup' => '0', 'method_boxnow' => '0', 'admin_notify' => '1', 'email_received' => '1', 'email_created' => '1', 'email_rejected' => '1'] as $f => $default) {
            if (!array_key_exists(self::P . $f, $post)) {
                $post[self::P . $f] = $default;
                continue;
            }
            $post[self::P . $f] = !empty($post[self::P . $f]) && (string)$post[self::P . $f] !== '0' ? '1' : '0';
        }
        $post[self::P . 'window_days'] = (string)max(0, (int)($post[self::P . 'window_days'] ?? 14));
        $post[self::P . 'weight'] = (string)self::clampWeight($post[self::P . 'weight'] ?? 1);
        $res = self::sanitizeAccounts($post[self::P . 'accounts'] ?? [], $known_codes);
        $post[self::P . 'accounts'] = $res['rows'];
        $errors = $res['errors'];
        $raw = trim(strip_tags(html_entity_decode((string)($post[self::P . 'admin_emails'] ?? ''), ENT_QUOTES, 'UTF-8')));
        $emails = self::splitEmails($raw);
        $post[self::P . 'admin_emails'] = implode(', ', $emails);
        if ($raw !== '' && count($emails) !== count(array_unique(array_filter(preg_split('/[\s,;]+/', $raw))))) {
            $errors[] = 'Κάποιες διευθύνσεις email για το «Νέο αίτημα επιστροφής» δεν ήταν έγκυρες και αφαιρέθηκαν.';
        }
        return $errors;
    }
}
