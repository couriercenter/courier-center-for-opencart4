<?php
/**
 * Voucher creation / void for the Courier Center extension: ONE implementation for the order
 * panel, the bulk actions and the auto-create event, shared by OpenCart 3 and 4 (PHP 7.2+).
 * - per-order lock (cc_locks) + re-check inside it: one order never gets two vouchers
 * - pickup points (sub-codes): Shipper = point, Requestor = billing code of the point
 * - BOX NOW Parcel ID (OutboundShipmentNumber) stored with the voucher
 */

namespace Opencart\Extension\Couriercenter\Library;

class CCVoucherService {
    const P = 'shipping_courier_center_';

    /** @var object */
    private $db;
    /** @var object */
    private $config;
    /** @var string */
    private $prefix;
    /** @var CCApi|null */
    private $api;
    /** @var array */
    private $class_cache = [];
    /** @var CCPickupPoints|null */
    private $points;
    /** @var CCLock|null */
    private $lock;

    /** @param object $db OpenCart DB  @param object $config OpenCart Config  @param CCApi|null $api tests inject a fake */
    public function __construct($db, $config, $prefix, $api = null, $lock = null) {
        $this->db = $db;
        $this->config = $config;
        $this->prefix = (string)$prefix;
        $this->api = $api;
        $this->lock = $lock;
    }

    /** Setting value; strings are HTML-decoded (OpenCart stores form input escaped). */
    public function setting($key) {
        $v = $this->config->get(self::P . $key);
        if ($v === null) {
            return '';
        }
        return is_array($v) ? $v : html_entity_decode((string)$v, ENT_QUOTES, 'UTF-8');
    }

    public function api() {
        if ($this->api === null) {
            $this->api = new CCApi((string)$this->setting('user_alias'), (string)$this->setting('credential_value'), (string)$this->setting('api_key'), (string)$this->setting('billing_account'));
        }
        return $this->api;
    }

    public function points(): CCPickupPoints {
        if ($this->points === null) {
            $this->points = CCPickupPoints::fromConfig($this->config);
        }
        return $this->points;
    }

    public function lock(): CCLock {
        if ($this->lock === null) {
            $this->lock = new CCLock($this->db, $this->prefix);
        }
        return $this->lock;
    }

    public function builderSettings() {
        $postcode = (string)$this->setting('shipper_postcode');
        return [
            'user_alias'       => (string)$this->setting('user_alias'),
            'credential_value' => (string)$this->setting('credential_value'),
            'api_key'          => (string)$this->setting('api_key'),
            'billing_account'  => (string)$this->setting('billing_account'),
            'shipper_name'     => (string)$this->setting('shipper_name'),
            'shipper_address'  => (string)$this->setting('shipper_address'),
            'shipper_postal'   => $postcode,
            'shipper_city'     => (string)$this->setting('shipper_city'),
            'shipper_phone'    => (string)$this->setting('shipper_phone'),
            'shipper_station'  => CCCityScope::get_station_for_postcode($postcode),
        ];
    }

    /**
     * The order's shipping extension code: "courier_center.courier_center" -> "courier_center".
     * OpenCart 3 and 4.0.0-4.0.1 keep it in shipping_code; 4.0.2+ in shipping_method['code'] (array or JSON).
     */
    public static function shippingCode(array $order) {
        $full = (string)($order['shipping_code'] ?? '');
        if ($full === '') {
            $sm = self::shippingMethodArray($order);
            $full = (string)($sm['code'] ?? '');
        }
        $dot = strpos($full, '.');
        return $dot === false ? $full : substr($full, 0, $dot);
    }

    /** Shipping method title as plain text (OpenCart 3: string; 4.0.2+: ['name' => ...]). */
    public static function shippingName(array $order) {
        $sm = self::shippingMethodArray($order);
        $name = $sm ? (string)($sm['name'] ?? '') : (string)($order['shipping_method'] ?? '');
        return html_entity_decode($name, ENT_QUOTES, 'UTF-8');
    }

    private static function shippingMethodArray(array $order) {
        $sm = $order['shipping_method'] ?? '';
        if (is_array($sm)) {
            return $sm;
        }
        $sm = (string)$sm;
        if ($sm !== '' && $sm[0] === '{') {
            $d = json_decode($sm, true);
            if (is_array($d)) {
                return $d;
            }
        }
        return [];
    }

    public function handledMethods() {
        $h = $this->setting('handled_shipping_methods');
        if (!is_array($h)) {
            return [];
        }
        return array_values(array_filter(array_map('strval', $h), 'strlen'));
    }

    /** Does the extension manage this order's shipping method? Empty setting = every order. */
    public function isHandledOrder(array $order) {
        $handled = $this->handledMethods();
        return empty($handled) || in_array(self::shippingCode($order), $handled, true);
    }

    /** BOX NOW choice saved at checkout in the order's custom_field (array or JSON string). */
    public static function boxnowFromOrder(array $order) {
        $cf = $order['custom_field'] ?? [];
        if (!is_array($cf)) {
            $cf = json_decode((string)$cf, true);
            if (!is_array($cf)) {
                $cf = [];
            }
        }
        $locker_id = (string)($cf['cc_locker_id'] ?? '');
        if ($locker_id === '' && (string)($cf['cc_delivery_mode'] ?? '') !== 'auto') {
            return [];
        }
        return [
            'boxnow'      => true,
            'locker_id'   => $locker_id,
            'locker_code' => (string)($cf['cc_locker_code'] ?? ''),
            'locker_name' => (string)($cf['cc_locker_name'] ?? ''),
        ];
    }

    public function orderProducts($order_id) {
        return $this->db->query("SELECT `product_id`, `quantity` FROM `" . $this->prefix . "order_product` WHERE `order_id` = '" . (int)$order_id . "'")->rows;
    }

    /** Weight in kg and dimensions in cm from the product table (order_product has none). */
    public function enrichProducts(array $products) {
        foreach ($products as $i => $p) {
            $pid = (int)($p['product_id'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            $q = $this->db->query("SELECT `weight`, `weight_class_id`, `length`, `width`, `height`, `length_class_id` FROM `" . $this->prefix . "product` WHERE `product_id` = '" . $pid . "' LIMIT 1");
            if (!$q->num_rows) {
                continue;
            }
            $wc = (int)$q->row['weight_class_id'];
            $lc = (int)$q->row['length_class_id'];
            $products[$i]['weight'] = $this->convertClass($q->row['weight'], $wc, 'weight_class', 'kg');
            $products[$i]['length'] = $this->convertClass($q->row['length'], $lc, 'length_class', 'cm');
            $products[$i]['width']  = $this->convertClass($q->row['width'], $lc, 'length_class', 'cm');
            $products[$i]['height'] = $this->convertClass($q->row['height'], $lc, 'length_class', 'cm');
        }
        return $products;
    }

    /** Convert from an OpenCart weight/length class to kg / cm; raw value when a class is unknown. */
    public function convertClass($value, $from_class_id, $table, $unit) {
        $value = (float)$value;
        $from_class_id = (int)$from_class_id;
        if ($value <= 0 || $from_class_id <= 0) {
            return $value;
        }
        $key = $table . '|' . $unit;
        if (!array_key_exists($key, $this->class_cache)) {
            $r = $this->db->query("SELECT c.`" . $table . "_id` AS id, c.`value` AS val FROM `" . $this->prefix . $table . "` c JOIN `" . $this->prefix . $table . "_description` d ON c.`" . $table . "_id` = d.`" . $table . "_id` WHERE LOWER(TRIM(d.`unit`)) = '" . $this->db->escape($unit) . "' LIMIT 1");
            $this->class_cache[$key] = $r->num_rows ? ['id' => (int)$r->row['id'], 'val' => (float)$r->row['val']] : null;
        }
        $target = $this->class_cache[$key];
        if (!$target || $from_class_id === $target['id']) {
            return $value;
        }
        $f = $this->db->query("SELECT `value` FROM `" . $this->prefix . $table . "` WHERE `" . $table . "_id` = '" . $from_class_id . "' LIMIT 1");
        $from_val = $f->num_rows ? (float)$f->row['value'] : 0.0;
        return $from_val > 0 ? $value * ($target['val'] / $from_val) : $value;
    }

    public function getShipment($order_id) {
        $q = $this->db->query("SELECT * FROM `" . $this->prefix . "cc_shipments` WHERE `order_id` = '" . (int)$order_id . "' LIMIT 1");
        return $q->num_rows ? $q->row : [];
    }

    public static function isActive(array $shipment) {
        return !empty($shipment['voucher_number']) && empty($shipment['is_voided']);
    }

    /** Store a NEW voucher. The row always starts active, without leftovers of a voided one. */
    public function saveShipment($order_id, array $d) {
        $order_id = (int)$order_id;
        $set = [];
        foreach (['voucher_number' => '', 'tracking_number' => '', 'service_type' => 'next_day', 'return_option' => 'none',
            'return_awb' => '', 'locker_id' => '', 'locker_code' => '', 'locker_name' => '',
            'pickup_point' => '', 'requestor_account' => '', 'boxnow_parcel_id' => ''] as $col => $default) {
            $set[] = '`' . $col . "` = '" . $this->db->escape((string)($d[$col] ?? $default)) . "'";
        }
        $set[] = "`is_boxnow` = '" . (!empty($d['is_boxnow']) ? 1 : 0) . "'";
        $set[] = "`is_voided` = '0'";
        $set[] = "`is_final` = '0'";
        $set[] = "`status_code` = ''";
        $set[] = "`status_desc` = ''";
        $set[] = '`last_checked_at` = NULL';
        $set[] = '`status_updated_at` = NULL';
        $set[] = '`created_at` = NOW()';
        if ($this->db->query("SELECT `id` FROM `" . $this->prefix . "cc_shipments` WHERE `order_id` = '" . $order_id . "' LIMIT 1")->num_rows) {
            $this->db->query("UPDATE `" . $this->prefix . "cc_shipments` SET " . implode(', ', $set) . " WHERE `order_id` = '" . $order_id . "'");
        } else {
            $this->db->query("INSERT INTO `" . $this->prefix . "cc_shipments` SET `order_id` = '" . $order_id . "', " . implode(', ', $set));
        }
    }

    public function history() {
        return new CCOrderHistory($this->db, $this->prefix);
    }

    public function tracker() {
        return new CCStatusTracker($this->db, $this->prefix, $this->api(), (int)$this->setting('auto_complete_status_id'), $this->statusSets());
    }

    /**
     * Order statuses for the tracker: "active" (pending + processing: an order still on its way)
     * and "complete" (config_complete_status). Cancelled / refunded / ... are in neither.
     */
    public function statusSets() {
        $active = [(int)$this->config->get('config_order_status_id')];
        foreach (['config_processing_status', 'config_complete_status'] as $key) {
            $v = $this->config->get($key);
            $ids = array_values(array_filter(array_map('intval', is_array($v) ? $v : []), function ($x) {
                return $x > 0;
            }));
            if ($key === 'config_processing_status') {
                $active = array_merge($active, $ids);
            } else {
                $complete = $ids;
            }
        }
        $complete = isset($complete) ? $complete : [];
        $active = array_values(array_diff(array_unique(array_filter($active)), $complete));
        return ['active' => $active, 'complete' => $complete];
    }

    /**
     * Create a voucher for one order.
     * @param array $order OpenCart getOrder() row (admin or catalog model, OpenCart 3 or 4)
     * @param array $opts  service_type, parcel_count, return_option, boxnow, locker_id, locker_code, locker_name,
     *                     pickup_point ('' = default point),
     *                     mode: 'manual' (order panel, default) | 'bulk' | 'auto' (status-change event)
     * @return array ['success'=>true,'voucher','tracking','return_awb','parcel_id','pickup_point']
     *             | ['success'=>false,'error','stage'=>'validation'|'api'|'busy'|'exists']
     */
    public function createForOrder(array $order, array $opts) {
        $order_id = (int)($order['order_id'] ?? 0);
        if ($order_id <= 0) {
            return ['success' => false, 'error' => 'Παραγγελία δε βρέθηκε', 'stage' => 'validation'];
        }
        CCDbSchema::ensureOnce($this->db, $this->prefix); // files updated without re-install: new tables / columns
        $lock = $this->lock();
        $name = CCLock::voucherName($order_id);
        if (!$lock->acquire($name)) {
            return ['success' => false, 'stage' => 'busy', 'error' => 'Η δημιουργία voucher για αυτή την παραγγελία είναι ήδη σε εξέλιξη (άλλο παράθυρο ή αυτόματη/μαζική δημιουργία). Περίμενε λίγα δευτερόλεπτα και ανανέωσε τη σελίδα.'];
        }
        try {
            // Re-check INSIDE the lock: another request may have finished a voucher meanwhile.
            $existing = $this->getShipment($order_id);
            if (self::isActive($existing)) {
                return ['success' => false, 'stage' => 'exists', 'error' => 'Η παραγγελία έχει ήδη ενεργό voucher (' . $existing['voucher_number'] . '). Ακύρωσέ το πρώτα.'];
            }
            return $this->createLocked($order, $opts);
        } finally {
            $lock->release($name);
        }
    }

    private function createLocked(array $order, array $opts) {
        $order_id = (int)$order['order_id'];
        $codes = CCShipmentBuilder::SERVICE_CODES;
        $service = (string)($opts['service_type'] ?? 'next_day');
        if (!isset($codes[$service])) {
            $service = 'next_day';
        }
        $parcels = max(1, min(99, (int)($opts['parcel_count'] ?? 1)));
        $return_option = (string)($opts['return_option'] ?? 'none');
        if (!in_array($return_option, ['none', 'optional', 'mandatory'], true)) {
            $return_option = 'none';
        }
        $boxnow = !empty($opts['boxnow']);
        $locker_id = trim((string)($opts['locker_id'] ?? ''));
        $locker_code = trim((string)($opts['locker_code'] ?? ''));
        $locker_name = trim((string)($opts['locker_name'] ?? ''));
        $mode = (string)($opts['mode'] ?? 'manual');

        $points = $this->points();
        $code = trim((string)($opts['pickup_point'] ?? ''));
        if ($code === '') {
            $code = $points->defaultCode();
        }
        $point = $points->get($code);
        if ($point === null && $code !== '') {
            return $this->failed($order_id, $mode, 'validation', 'Το σημείο αποστολής ' . $code . ' δεν υπάρχει στις ρυθμίσεις.');
        }

        $adapter = new CCOrderAdapter($order, $this->enrichProducts($this->orderProducts($order_id)));
        if ($boxnow && $adapter->is_cod()) {
            return $this->failed($order_id, $mode, 'validation', 'Το BOX NOW δεν υποστηρίζει αντικαταβολή. Άλλαξε τρόπο πληρωμής ή απενεργοποίησε το BOX NOW Locker.');
        }
        $builder = new CCShipmentBuilder($adapter, $this->builderSettings(), $parcels);
        if ($point !== null && empty($point['is_main'])) {
            $builder->use_pickup_point($point);
        }
        foreach ([$builder->validate_settings(), $builder->validate_order()] as $check) {
            if ($check !== true) {
                return $this->failed($order_id, $mode, 'validation', (string)$check);
            }
        }

        $payload = $builder->build_payload($service, $boxnow, $return_option);
        if ($boxnow && $locker_id !== '') {
            $payload['LockerDeliveryInfo'] = ['Prefix' => 'ATH', 'Code' => $locker_id];
        }

        $result = $this->api()->create_shipment($payload);
        if (isset($result['success']) && $result['success'] === false) {
            $error = (string)($result['error'] ?? 'Άγνωστο σφάλμα από το API');
            if (!empty($result['exception'])) {
                $error .= ' — Το αποτέλεσμα είναι αβέβαιο: έλεγξε στο Courier Center αν δημιουργήθηκε αποστολή πριν ξαναδοκιμάσεις.';
            }
            return $this->failed($order_id, $mode, 'api', $error);
        }

        $voucher = (string)($result['VoucherNumber'] ?? $result['AwbNumber'] ?? $result['ShipmentNumber'] ?? '');
        $tracking = (string)($result['TrackingNumber'] ?? $result['TrackingNo'] ?? ($result['TrackingNumbers'][0] ?? ''));
        if ($tracking === '') {
            $tracking = $voucher;
        }
        if ($voucher === '') {
            return $this->failed($order_id, $mode, 'api', 'Το API δεν επέστρεψε αριθμό voucher');
        }
        $return_awb = '';
        if ($return_option !== 'none') {
            $return_awb = (string)($result['ReturnShipmentNumber'] ?? $result['ReturnAWB'] ?? $result['ReturnVoucherNumber']
                ?? ($result['ReturnTrackingNumbers'][0] ?? null) ?? ($result['TrackingNumbers'][1] ?? ''));
        }
        $parcel_id = $boxnow ? trim((string)($result['OutboundShipmentNumber'] ?? '')) : '';

        // BOX NOW: "no locker found" fallback (door-to-door) or the locker the carrier assigned.
        $note_extra = '';
        if ($boxnow) {
            $contractor_note = (string)($result['ContractorResultNote'] ?? '');
            if (stripos($contractor_note, 'No locker found') !== false) {
                $note_extra = ' | ⚠️ Δεν βρέθηκε locker — αποστέλλεται door-to-door';
            } else {
                $assigned = (string)($result['AssignedLockerCode'] ?? $result['LockerCode'] ?? $result['DestinationLockerCode'] ?? ($result['LockerDeliveryInfo']['Code'] ?? ''));
                if ($assigned === '' && $contractor_note !== '' && preg_match('/locker[:\s#]*(\w+)/i', $contractor_note, $m)) {
                    $assigned = $m[1];
                }
                if ($assigned !== '') {
                    $note_extra = ' | 📦 Locker: ' . $assigned;
                    if ($locker_id !== '' && $assigned !== $locker_id && $assigned !== $locker_code) {
                        $note_extra .= ' (ζητήθηκε: ' . ($locker_code !== '' ? $locker_code : $locker_id) . ')';
                    }
                    if ($locker_code === '') {
                        $locker_code = $assigned;
                    }
                }
            }
            if ($parcel_id !== '') {
                $note_extra .= ' | Parcel ID: ' . $parcel_id;
            }
        }

        $shipper = $builder->shipper_account();
        $requestor = $builder->requestor_account();
        $this->saveShipment($order_id, [
            'voucher_number' => $voucher, 'tracking_number' => $tracking, 'service_type' => $service,
            'return_option' => $return_option, 'return_awb' => $return_awb, 'is_boxnow' => $boxnow ? 1 : 0,
            'locker_id' => $locker_id, 'locker_code' => $locker_code, 'locker_name' => $locker_name,
            'pickup_point' => $shipper, 'requestor_account' => $requestor, 'boxnow_parcel_id' => $parcel_id,
        ]);

        if ($points->hasSubs() && $point !== null) {
            $note_extra .= ' | Σημείο: ' . CCPickupPoints::label($point) . ($requestor !== $shipper ? ' — Χρέωση: ' . $requestor : '');
        }
        if ($mode === 'auto') {
            $note = '🤖 Αυτόματη δημιουργία voucher (αλλαγή status): ' . $voucher . ' | Υπηρεσία: ' . self::serviceLabel($service) . ($boxnow ? ' + BOX NOW' : '');
        } else {
            $note = '✅ Courier Center voucher: ' . $voucher . ' | Υπηρεσία: ' . self::serviceLabel($service)
                . ($boxnow ? ' + BOX NOW' : '') . ($return_option !== 'none' ? ' + Επιστροφικό' : '');
            if ($return_awb !== '') {
                $note .= ' | Return AWB: ' . $return_awb;
            }
        }
        $this->history()->add($order_id, $note . $note_extra);

        return ['success' => true, 'voucher' => $voucher, 'tracking' => $tracking, 'return_awb' => $return_awb,
            'parcel_id' => $parcel_id, 'pickup_point' => $shipper];
    }

    /** Failure result; manual and bulk API failures also leave a note on the order (auto: the caller does). */
    private function failed($order_id, $mode, $stage, $error) {
        if ($stage === 'api' && $mode !== 'auto') {
            $this->history()->add((int)$order_id, '❌ Αποτυχία δημιουργίας voucher' . ($mode === 'bulk' ? ' [Bulk]' : '') . ': ' . $error);
        }
        return ['success' => false, 'error' => $error, 'stage' => $stage];
    }

    /**
     * Void the order's active voucher.
     * @return array ['success'=>true,'voucher'] | ['success'=>false,'error']
     */
    public function voidForOrder($order_id) {
        $order_id = (int)$order_id;
        $shipment = $this->getShipment($order_id);
        if (empty($shipment['voucher_number'])) {
            return ['success' => false, 'error' => 'Δεν υπάρχει αποστολή για ακύρωση'];
        }
        if (!empty($shipment['is_voided'])) {
            return ['success' => false, 'error' => 'Η αποστολή έχει ήδη ακυρωθεί.'];
        }
        $result = $this->api()->void_shipment((string)$shipment['voucher_number']);
        if ($result !== true) {
            $error = is_array($result) && isset($result['error']) ? (string)$result['error'] : 'Αποτυχία ακύρωσης';
            $this->history()->add($order_id, '❌ Αποτυχία ακύρωσης AWB ' . $shipment['voucher_number'] . ': ' . $error);
            return ['success' => false, 'error' => $error];
        }
        $this->db->query("UPDATE `" . $this->prefix . "cc_shipments` SET `is_voided` = 1 WHERE `order_id` = '" . $order_id . "'");
        $this->history()->add($order_id, '❌ Courier Center: Αποστολή ακυρώθηκε — AWB ' . $shipment['voucher_number']);
        return ['success' => true, 'voucher' => (string)$shipment['voucher_number']];
    }

    public static function serviceLabel($service) {
        $labels = ['next_day' => 'Επόμενη Μέρα', 'same_day_3h' => 'Αυθημερόν 3ω', 'same_day_5h' => 'Αυθημερόν 5ω'];
        return isset($labels[$service]) ? $labels[$service] : (string)$service;
    }
}
