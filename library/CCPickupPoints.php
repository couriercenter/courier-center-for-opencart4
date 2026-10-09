<?php
/**
 * Pickup points (sub-codes / υποκωδικοί) of the merchant: the main account ("Έδρα") plus any
 * sub-code declared in the settings. Each voucher is sent from one point: Shipper = the point's
 * code, Requestor = the point itself or the main code (billing choice per sub-code).
 * Port of CC_Pickup_Points (WooCommerce 1.5.0). Shared by the OpenCart 3 and 4 extensions, PHP 7.2+.
 */

namespace Opencart\Extension\Couriercenter\Library;

class CCPickupPoints {

    const SETTING_PREFIX = 'shipping_courier_center_';

    /** @var array */
    private $main;
    /** @var array[] */
    private $subs;
    /** @var string */
    private $default;

    /**
     * @param array $s billing_account, shipper_name, shipper_address, shipper_postcode, shipper_city,
     *                 shipper_phone, pickup_points (rows or JSON), default_pickup_point
     */
    public function __construct(array $s) {
        $code = trim((string)($s['billing_account'] ?? ''));
        $this->main = [
            'code'      => $code,
            'label'     => 'Έδρα',
            'requestor' => $code,
            'bill_to'   => 'self',
            'name'      => (string)($s['shipper_name'] ?? ''),
            'address'   => (string)($s['shipper_address'] ?? ''),
            'postal'    => (string)($s['shipper_postcode'] ?? ''),
            'city'      => (string)($s['shipper_city'] ?? ''),
            'phone'     => (string)($s['shipper_phone'] ?? ''),
            'is_main'   => true,
        ];
        $rows = $s['pickup_points'] ?? [];
        if (is_string($rows)) {
            $rows = json_decode($rows, true);
        }
        $this->subs = $this->buildSubs(is_array($rows) ? $rows : []);
        $this->default = trim((string)($s['default_pickup_point'] ?? ''));
    }

    /** From OpenCart's Config: strings are stored HTML-escaped, so they are decoded here. */
    public static function fromConfig($config): self {
        $s = [];
        foreach (['billing_account', 'shipper_name', 'shipper_address', 'shipper_postcode', 'shipper_city', 'shipper_phone', 'pickup_points', 'default_pickup_point'] as $k) {
            $s[$k] = self::decode($config->get(self::SETTING_PREFIX . $k));
        }
        return new self($s);
    }

    public function main(): array {
        return $this->main;
    }

    /** @return array[] */
    public function subs(): array {
        return $this->subs;
    }

    /** @return array[] the main point first */
    public function all(): array {
        return array_merge([$this->main], $this->subs);
    }

    public function hasSubs(): bool {
        return !empty($this->subs);
    }

    /** @return array|null */
    public function get(string $code) {
        $code = trim($code);
        if ($code === '') {
            return null;
        }
        foreach ($this->all() as $p) {
            if ($p['code'] === $code) {
                return $p;
            }
        }
        return null;
    }

    /** The configured default point when it still exists, else the main code. */
    public function defaultCode(): string {
        if ($this->default !== '' && $this->get($this->default) !== null) {
            return $this->default;
        }
        return $this->main['code'];
    }

    public static function label(array $point): string {
        return $point['label'] . ' (' . $point['code'] . ')';
    }

    /**
     * Clean the rows posted by the settings form.
     * @return array ['rows' => array[], 'errors' => string[]]
     */
    public static function sanitizeRows($rows, string $main_code): array {
        $clean = [];
        $errors = [];
        $seen = [];
        $main_code = trim($main_code);
        if (!is_array($rows)) {
            return ['rows' => [], 'errors' => []];
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = self::text($row['code'] ?? '');
            if ($code === '') {
                continue;
            }
            if ($code === $main_code) {
                $errors[] = sprintf('Ο υποκωδικός %s είναι ίδιος με τον κεντρικό κωδικό και αγνοήθηκε.', $code);
                continue;
            }
            if (isset($seen[$code])) {
                $errors[] = sprintf('Ο υποκωδικός %s υπάρχει δύο φορές· κρατήθηκε μόνο η πρώτη γραμμή.', $code);
                continue;
            }
            $seen[$code] = true;
            $label = self::text($row['label'] ?? '');
            $line = [
                'code'    => $code,
                'label'   => $label === '' ? $code : $label,
                'bill_to' => (($row['bill_to'] ?? '') === 'main') ? 'main' : 'self',
            ];
            foreach (['name', 'address', 'postal', 'city', 'phone'] as $field) {
                $line[$field] = self::text($row[$field] ?? '');
            }
            $clean[] = $line;
        }
        return ['rows' => $clean, 'errors' => $errors];
    }

    private function buildSubs(array $rows): array {
        $main_code = $this->main['code'];
        $points = [];
        $seen = [];
        foreach ($rows as $row) {
            if (!is_array($row) || trim((string)($row['code'] ?? '')) === '') {
                continue;
            }
            $code = trim((string)$row['code']);
            // The main code (written later by the main autofill) and duplicates are ignored.
            if (($main_code !== '' && $code === $main_code) || isset($seen[$code])) {
                continue;
            }
            $seen[$code] = true;
            $bill_to = (($row['bill_to'] ?? '') === 'main') ? 'main' : 'self';
            $label = trim((string)($row['label'] ?? ''));
            $points[] = [
                'code'      => $code,
                'label'     => $label === '' ? $code : $label,
                // Billing to the main account only when there is a main account.
                'requestor' => ($bill_to === 'main' && $main_code !== '') ? $main_code : $code,
                'bill_to'   => $bill_to,
                'name'      => (string)($row['name'] ?? ''),
                'address'   => (string)($row['address'] ?? ''),
                'postal'    => (string)($row['postal'] ?? ''),
                'city'      => (string)($row['city'] ?? ''),
                'phone'     => (string)($row['phone'] ?? ''),
                'is_main'   => false,
            ];
        }
        return $points;
    }

    /** One-line plain text (no tags, no control characters). */
    private static function text($v): string {
        $v = html_entity_decode((string)$v, ENT_QUOTES, 'UTF-8');
        $v = strip_tags($v);
        return trim((string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v));
    }

    private static function decode($v) {
        if (is_array($v)) {
            foreach ($v as $k => $x) {
                $v[$k] = self::decode($x);
            }
            return $v;
        }
        return $v === null ? '' : html_entity_decode((string)$v, ENT_QUOTES, 'UTF-8');
    }
}
