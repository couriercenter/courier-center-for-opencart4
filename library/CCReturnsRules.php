<?php
/**
 * Returns: pure rules (no DB, no API) - method / status constants, phone rules, BOX NOW locker
 * sizes, account-code comparison and the «ΑΣΚΟΠΗ ΠΡΟΣΕΛΕΥΣΗ» display rule.
 * Port of CC_Returns_Rules (WooCommerce 1.6.0). Shared by OpenCart 3 and 4, PHP 7.2+.
 * Errors are returned as ['error' => message].
 */

namespace Opencart\Extension\Couriercenter\Library;

class CCReturnsRules {

    const METHOD_DROPOFF = 'COURIER_CENTER_DROPOFF';
    const METHOD_PICKUP  = 'COURIER_CENTER_PICKUP';
    const METHOD_BOXNOW  = 'BOXNOW';
    const METHODS_ORDER  = [self::METHOD_DROPOFF, self::METHOD_PICKUP, self::METHOD_BOXNOW];

    const ST_PENDING  = 'pending_approval';
    const ST_APPROVED = 'approved';
    const ST_CREATING = 'creating';
    const ST_VERIFY   = 'needs_verification';
    const ST_CREATED  = 'shipment_created';
    const ST_FAILED   = 'creation_failed';
    const ST_REJECTED = 'rejected';

    /** Drop Off shipper (fixed values, confirmed on the API). */
    const DROPOFF_SHIPPER = ['ZipCode' => '91919', 'Area' => 'DROPOFF', 'City' => 'DROPOFF'];

    /** UNCONFIRMED for every return: values of the tested BOX NOW payload. */
    const BOXNOW_SHIPPER = ['Address' => 'BOX NOW ATH', 'ZipCode' => '99981'];

    const LOCKER_SIZES = [
        'small'  => ['reference' => '1', 'label' => 'Μικρό', 'dims' => '8 × 45 × 60 cm'],
        'medium' => ['reference' => '2', 'label' => 'Μεσαίο', 'dims' => '17 × 45 × 60 cm'],
        'large'  => ['reference' => '3', 'label' => 'Μεγάλο', 'dims' => '36 × 45 × 60 cm'],
    ];

    const COLLECTION_COMPLETED = 10;
    const COLLECTION_CANCELLED = 14;

    const ASKOPI_STATUS = 'ΑΣΚΟΠΗ ΠΡΟΣΕΛΕΥΣΗ';

    const MSG_PHONE  = 'Για τη δημιουργία της επιστροφής απαιτείται τουλάχιστον ένας έγκυρος αριθμός τηλεφώνου (σταθερό ή κινητό).';
    const MSG_MOBILE = 'Για επιστροφή μέσω BOX NOW απαιτείται έγκυρο ελληνικό κινητό τηλέφωνο (69XXXXXXXX).';

    /** Digits only, without the +30 / 0030 prefix. */
    public static function normalize_phone($raw): string {
        $d = (string)preg_replace('/\D+/', '', (string)$raw);
        if (strpos($d, '0030') === 0) {
            $d = substr($d, 4);
        } elseif (strpos($d, '30') === 0 && strlen($d) === 12) {
            $d = substr($d, 2);
        }
        return $d;
    }

    public static function is_gr_mobile($raw): bool {
        return preg_match('/^69\d{8}$/D', (string)$raw) === 1;
    }

    public static function is_gr_landline($raw): bool {
        return preg_match('/^2\d{9}$/D', (string)$raw) === 1;
    }

    /**
     * Drop Off / Pickup: mobile or landline (mobile preferred); BOX NOW: mobile only.
     * @return array ['phone' => string] | ['error' => string]
     */
    public static function pick_phone(string $method, $mobile, $landline): array {
        $m = self::normalize_phone($mobile);
        $l = self::normalize_phone($landline);
        if ($method === self::METHOD_BOXNOW) {
            return self::is_gr_mobile($m) ? ['phone' => $m] : (self::is_gr_mobile($l) ? ['phone' => $l] : ['error' => self::MSG_MOBILE]);
        }
        foreach ([[$m, 'mobile'], [$l, 'landline'], [$m, 'landline'], [$l, 'mobile']] as $try) {
            if ($try[1] === 'mobile' ? self::is_gr_mobile($try[0]) : self::is_gr_landline($try[0])) {
                return ['phone' => $try[0]];
            }
        }
        return ['error' => self::MSG_PHONE];
    }

    /** The locker reference of a size ('' when unknown). */
    public static function locker_reference($size): string {
        $size = (string)$size;
        return isset(self::LOCKER_SIZES[$size]) ? self::LOCKER_SIZES[$size]['reference'] : '';
    }

    /** Account codes equal regardless of zero padding: 100-0120-0001 == 100-120-0001. */
    public static function codes_equal($a, $b): bool {
        $pa = explode('-', trim((string)$a));
        $pb = explode('-', trim((string)$b));
        if (count($pa) !== 3 || count($pb) !== 3) {
            return false;
        }
        for ($i = 0; $i < 3; $i++) {
            if (!ctype_digit($pa[$i]) || !ctype_digit($pb[$i]) || (int)$pa[$i] !== (int)$pb[$i]) {
                return false;
            }
        }
        return true;
    }

    public static function is_valid_account_code($code): bool {
        return preg_match('/^\d{3}-\d{3,4}-\d{4}$/D', trim((string)$code)) === 1;
    }

    /** Upper case, no accents / diaeresis, Latin look-alikes → Greek. mbstring only (intl may be missing). */
    private static function fold($text): string {
        $s = function_exists('mb_strtoupper') ? mb_strtoupper((string)$text, 'UTF-8') : strtoupper((string)$text);
        return strtr($s, [
            "\xCC\x81" => '', "\xCC\x88" => '', // combining acute / diaeresis
            'Ά' => 'Α', 'Έ' => 'Ε', 'Ή' => 'Η', 'Ί' => 'Ι', 'Ό' => 'Ο', 'Ύ' => 'Υ', 'Ώ' => 'Ω', 'Ϊ' => 'Ι', 'Ϋ' => 'Υ',
            'ά' => 'Α', 'έ' => 'Ε', 'ή' => 'Η', 'ί' => 'Ι', 'ό' => 'Ο', 'ύ' => 'Υ', 'ώ' => 'Ω', 'ϊ' => 'Ι', 'ϋ' => 'Υ', 'ΐ' => 'Ι', 'ΰ' => 'Υ',
            'A' => 'Α', 'B' => 'Β', 'E' => 'Ε', 'H' => 'Η', 'I' => 'Ι', 'K' => 'Κ', 'M' => 'Μ', 'N' => 'Ν',
            'O' => 'Ο', 'P' => 'Ρ', 'T' => 'Τ', 'X' => 'Χ', 'Y' => 'Υ', 'Z' => 'Ζ',
        ]);
    }

    public static function contains_askopi($text): bool {
        if ($text === null || (string)$text === '') {
            return false;
        }
        return strpos(self::fold($text), 'ΑΣΚΟΠΗ') !== false;
    }

    /**
     * Displayed status: «ΑΣΚΟΠΗ ΠΡΟΣΕΛΕΥΣΗ» when the collection completed (CollectionStatus 10) and a
     * Reference contains ΑΣΚΟΠΗ; otherwise ShipmentStatusDesc, as for normal shipments.
     */
    public static function display_status($shipment_status, $shipment_status_desc, $collection_status, array $refs): string {
        if ((string)$collection_status !== '' && (int)$collection_status === self::COLLECTION_COMPLETED) {
            foreach ($refs as $r) {
                if (self::contains_askopi($r)) {
                    return self::ASKOPI_STATUS;
                }
            }
        }
        return (string)$shipment_status_desc;
    }

    /** The first enabled method in METHODS_ORDER ('' when none). */
    public static function default_method(array $enabled): string {
        foreach (self::METHODS_ORDER as $m) {
            if (in_array($m, $enabled, true)) {
                return $m;
            }
        }
        return '';
    }

    public static function status_label(string $status): string {
        $labels = [
            self::ST_PENDING  => 'Σε αναμονή έγκρισης',
            self::ST_APPROVED => 'Εγκρίθηκε',
            self::ST_CREATING => 'Δημιουργία σε εξέλιξη',
            self::ST_VERIFY   => 'Σε έλεγχο',
            self::ST_CREATED  => 'Δημιουργήθηκε αποστολή',
            self::ST_FAILED   => 'Αποτυχία δημιουργίας',
            self::ST_REJECTED => 'Απορρίφθηκε',
        ];
        return $labels[$status] ?? $status;
    }

    /** Customer wording: the internal creation states show as one. */
    public static function customer_status_label(string $status): string {
        $preparing = 'Εγκρίθηκε – η αποστολή επιστροφής ετοιμάζεται';
        $labels = [
            self::ST_PENDING  => 'Σε αναμονή έγκρισης',
            self::ST_CREATED  => 'Δημιουργήθηκε αποστολή',
            self::ST_REJECTED => 'Απορρίφθηκε',
        ];
        return $labels[$status] ?? $preparing;
    }

    public static function method_label(string $method): string {
        $labels = [
            self::METHOD_DROPOFF => 'Παράδοση σε κατάστημα Courier Center',
            self::METHOD_PICKUP  => 'Παραλαβή από διεύθυνση (Courier Center)',
            self::METHOD_BOXNOW  => 'BOX NOW locker',
        ];
        return $labels[$method] ?? $method;
    }

    /**
     * Success texts after creation (customer page + email): [text, value, is_code] rows.
     * Without a BOX NOW code (or size) that line is left out.
     */
    public static function creation_parts(array $row): array {
        $parts = [['Η επιστροφή σας δημιουργήθηκε επιτυχώς!', '', false]];
        if (($row['method'] ?? '') === self::METHOD_BOXNOW) {
            $parts[] = ['Μπορείτε να παραδώσετε το δέμα σας σε ένα BOX NOW locker που σας εξυπηρετεί.', '', false];
            if ((string)($row['return_shipment_number'] ?? '') !== '') {
                $parts[] = ['Κωδικός BOX NOW επιστροφής: ', (string)$row['return_shipment_number'], true];
            }
            $parts[] = ['Αριθμός αποστολής Courier Center: ', (string)($row['return_awb'] ?? ''), false];
            $size = (string)($row['locker_size'] ?? '');
            if (isset(self::LOCKER_SIZES[$size])) {
                $parts[] = ['Επιλεγμένο μέγεθος: ', self::LOCKER_SIZES[$size]['label'], false];
            }
        } else {
            $parts[] = ['Μπορείτε να παραδώσετε το δέμα σας σε κατάστημα Courier Center.', '', false];
            $parts[] = ['Αριθμός αποστολής επιστροφής: ', (string)($row['return_awb'] ?? ''), false];
        }
        return $parts;
    }
}
