<?php
/**
 * Returns service: eligibility, customer submission, approve / reject, creation of the return
 * shipment with an atomic claim (status "creating") and verification through GetShipmentDetails
 * (Identifier = reference token in Reference2) whenever the outcome is uncertain. The order's
 * normal shipment (cc_shipments, status, return AWB option) is never touched.
 * Port of CC_Returns_Service (WooCommerce 1.6.0). Shared by OpenCart 3 and 4, PHP 7.2+.
 *
 * Results: ['ok' => true, ...] or ['ok' => false, 'code' => string, 'error' => string].
 */

namespace Opencart\Extension\Couriercenter\Library;

class CCReturnsService {

    const P = 'shipping_courier_center_';
    const REASON_MAX = 500;
    const ERROR_MAX = 300;

    const MSG_FORBIDDEN   = 'Μη εξουσιοδοτημένη πρόσβαση στην παραγγελία.';
    const MSG_NOT_PENDING = 'Το αίτημα έχει ήδη εξεταστεί.';
    const MSG_BUSY        = 'Η δημιουργία είναι ήδη σε εξέλιξη ή έχει ολοκληρωθεί.';
    const MSG_NO_RSN      = 'Το API δεν επέστρεψε κωδικό BOX NOW επιστροφής';
    const MSG_RECHECK     = 'Η αποστολή δεν βρέθηκε ακόμη στο API· θα γίνει νέος έλεγχος (αυτόματα ή με «Έλεγχος ύπαρξης αποστολής») πριν από οποιαδήποτε νέα δημιουργία.';

    /** @var object */
    private $db;
    /** @var string */
    private $prefix;
    /** @var object */
    private $config;
    /** @var CCApi */
    private $api;
    /** @var CCReturnsRepo */
    private $repo;
    /** @var CCReturnsSettings */
    private $settings;
    /** @var callable|null function (string $event, int $id): events received / created / rejected */
    private $notifier;
    /** @var callable|null function (string $level, string $message) */
    private $logger;

    public function __construct($db, string $prefix, $config, $api = null, $notifier = null, $logger = null) {
        $this->db = $db;
        $this->prefix = $prefix;
        $this->config = $config;
        $this->api = $api !== null ? $api : new CCApi($this->setting('user_alias'), $this->setting('credential_value'), $this->setting('api_key'), $this->setting('billing_account'));
        CCDbSchema::ensureOnce($db, $prefix);
        $this->repo = new CCReturnsRepo($db, $prefix);
        $this->settings = new CCReturnsSettings($config);
        $this->notifier = $notifier;
        $this->logger = $logger;
    }

    public function repo(): CCReturnsRepo {
        return $this->repo;
    }

    public function settings(): CCReturnsSettings {
        return $this->settings;
    }

    public function api(): CCApi {
        return $this->api;
    }

    private function setting(string $key): string {
        $v = $this->config->get(self::P . $key);
        return is_array($v) || $v === null ? '' : html_entity_decode((string)$v, ENT_QUOTES, 'UTF-8');
    }

    private static function fail_result(string $code, string $error): array {
        return ['ok' => false, 'code' => $code, 'error' => $error];
    }

    // ── Order data (same tables in OpenCart 3 and 4) ─────────────────────

    public function getOrder(int $order_id): array {
        $q = $this->db->query("SELECT * FROM `" . $this->prefix . "order` WHERE `order_id` = '" . $order_id . "' LIMIT 1");
        return $q->num_rows ? $q->row : [];
    }

    /** order_product_id => row (product_id, name, model, quantity) */
    public function orderItems(int $order_id): array {
        $out = [];
        foreach ($this->db->query("SELECT `order_product_id`, `product_id`, `name`, `model`, `quantity` FROM `" . $this->prefix . "order_product` WHERE `order_id` = '" . $order_id . "' ORDER BY `order_product_id`")->rows as $r) {
            $out[(int)$r['order_product_id']] = $r;
        }
        return $out;
    }

    private function shipment(int $order_id): array {
        $q = $this->db->query("SELECT * FROM `" . $this->prefix . "cc_shipments` WHERE `order_id` = '" . $order_id . "' LIMIT 1");
        return $q->num_rows ? $q->row : [];
    }

    /** @return int[] the shop's complete statuses */
    private function completeStatuses(): array {
        $v = $this->config->get('config_complete_status');
        return array_values(array_filter(array_map('intval', is_array($v) ? $v : [])));
    }

    /** When the order last moved to a complete status (order history), else its date_modified. */
    private function completedAt(array $order): int {
        $ids = $this->completeStatuses();
        if ($ids) {
            $q = $this->db->query("SELECT MAX(`date_added`) AS d FROM `" . $this->prefix . "order_history` WHERE `order_id` = '" . (int)$order['order_id'] . "' AND `order_status_id` IN (" . implode(',', $ids) . ")");
            if ($q->num_rows && !empty($q->row['d'])) {
                return (int)strtotime((string)$q->row['d']);
            }
        }
        return (int)strtotime((string)($order['date_modified'] ?? ''));
    }

    // ── Eligibility / quantities ─────────────────────────────────────────

    /** Owner, returns enabled, complete status, active AWB, within the return window. */
    public function checkEligibility(array $order, int $customer_id): array {
        // The owner first: nothing else is revealed for someone else's order.
        if ($customer_id <= 0 || !$order || (int)($order['customer_id'] ?? 0) !== $customer_id) {
            return self::fail_result('forbidden', self::MSG_FORBIDDEN);
        }
        if (!$this->settings->enabled() || !$this->settings->enabledMethods()) {
            return self::fail_result('disabled', 'Οι επιστροφές δεν είναι διαθέσιμες.');
        }
        if (!in_array((int)$order['order_status_id'], $this->completeStatuses(), true)) {
            return self::fail_result('not_completed', 'Η επιστροφή είναι διαθέσιμη μόνο για ολοκληρωμένες παραγγελίες.');
        }
        $s = $this->shipment((int)$order['order_id']);
        if (trim((string)($s['voucher_number'] ?? '')) === '' || !empty($s['is_voided'])) {
            return self::fail_result('no_shipment', 'Η παραγγελία δεν έχει ενεργή αποστολή Courier Center.');
        }
        $days = $this->settings->windowDays();
        if ($days > 0) {
            $at = $this->completedAt($order);
            if ($at > 0 && time() - $at > $days * 86400) {
                return self::fail_result('expired', 'Η προθεσμία επιστροφής για αυτή την παραγγελία έχει λήξει.');
            }
        }
        return ['ok' => true];
    }

    /** order_product_id => ['name', 'model', 'ordered', 'available']: ordered − quantities in requests that were not rejected. */
    public function availableItems(array $order): array {
        $reserved = [];
        foreach ($this->repo->forOrder((int)$order['order_id']) as $req) {
            if ($req['status'] === CCReturnsRules::ST_REJECTED) {
                continue;
            }
            foreach ($req['items'] as $line) {
                $iid = (int)($line['item_id'] ?? 0);
                $reserved[$iid] = ($reserved[$iid] ?? 0) + (int)($line['qty'] ?? 0);
            }
        }
        $out = [];
        foreach ($this->orderItems((int)$order['order_id']) as $iid => $item) {
            $ordered = (int)$item['quantity'];
            $out[$iid] = [
                'name'      => html_entity_decode((string)$item['name'], ENT_QUOTES, 'UTF-8'),
                'model'     => html_entity_decode((string)$item['model'], ENT_QUOTES, 'UTF-8'),
                'ordered'   => $ordered,
                'available' => max(0, $ordered - ($reserved[$iid] ?? 0)),
            ];
        }
        return $out;
    }

    /** Shipper data the form shows: name, address, city, postcode, phone, email of the order. */
    public function customerDefaults(array $order): array {
        $a = new CCOrderAdapter($order);
        $phone = CCReturnsRules::normalize_phone($a->get_billing_phone());
        return [
            'name'     => trim($a->get_billing_first_name() . ' ' . $a->get_billing_last_name()),
            'address'  => trim($a->get_billing_address_1() . ' ' . $a->get_billing_address_2()),
            'city'     => $a->get_billing_city(),
            'postcode' => $a->get_billing_postcode(),
            'mobile'   => CCReturnsRules::is_gr_mobile($phone) ? $phone : '',
            'landline' => CCReturnsRules::is_gr_landline($phone) ? $phone : '',
            'email'    => (string)($order['email'] ?? ''),
        ];
    }

    // ── Submit / decide ──────────────────────────────────────────────────

    /**
     * $data: request_uid, method, items[order_product_id => qty], reason, name, mobile, landline, locker_size.
     * The same request_uid again (double click / refresh) returns the existing id.
     * @return array ['ok' => true, 'id' => int] | error
     */
    public function submit(array $order, int $customer_id, array $data): array {
        $uid = (string)($data['request_uid'] ?? '');
        if (preg_match('/^[A-Za-z0-9_-]{8,32}$/D', $uid) !== 1) {
            return self::fail_result('invalid_request', 'Μη έγκυρο αίτημα. Ανανεώστε τη σελίδα και δοκιμάστε ξανά.');
        }
        $existing = $this->existingForUid($uid, $order, $customer_id);
        if ($existing !== null) {
            return $existing;
        }
        $ok = $this->checkEligibility($order, $customer_id);
        if (!$ok['ok']) {
            return $ok;
        }
        $method = (string)($data['method'] ?? '');
        if (!in_array($method, $this->settings->enabledMethods(), true)) {
            return self::fail_result('method_unavailable', 'Η επιλεγμένη μέθοδος επιστροφής δεν είναι διαθέσιμη.');
        }
        $items = $this->validateItems($order, $data['items'] ?? []);
        if (isset($items['error'])) {
            return self::fail_result($items['code'], $items['error']);
        }
        $reason = trim(strip_tags(html_entity_decode((string)($data['reason'] ?? ''), ENT_QUOTES, 'UTF-8')));
        if (self::len($reason) > self::REASON_MAX) {
            return self::fail_result('reason_length', 'Η αιτιολογία μπορεί να έχει έως 500 χαρακτήρες.');
        }
        $name = trim((string)preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode((string)($data['name'] ?? ''), ENT_QUOTES, 'UTF-8'))));
        if ($name === '') {
            return self::fail_result('missing_name', 'Συμπληρώστε το ονοματεπώνυμο του αποστολέα.');
        }
        $phone = CCReturnsRules::pick_phone($method, (string)($data['mobile'] ?? ''), (string)($data['landline'] ?? ''));
        if (isset($phone['error'])) {
            return self::fail_result('phone', $phone['error']);
        }
        $locker_size = '';
        if ($method === CCReturnsRules::METHOD_BOXNOW) {
            $locker_size = (string)($data['locker_size'] ?? '');
            if (CCReturnsRules::locker_reference($locker_size) === '') {
                return self::fail_result('locker_size', 'Επιλέξτε μέγεθος BOX NOW locker (Μικρό, Μεσαίο ή Μεγάλο).');
            }
        }

        $c = $this->customerDefaults($order);
        $ship = $this->shipment((int)$order['order_id']);
        $point = trim((string)($ship['pickup_point'] ?? ''));
        $row = [
            'request_uid'           => $uid,
            'reference_token'       => self::newToken(),
            'order_id'              => (int)$order['order_id'],
            'customer_id'           => $customer_id,
            'status'                => CCReturnsRules::ST_PENDING,
            'method'                => $method,
            'items'                 => $items,
            'reason'                => $reason,
            'original_awb'          => trim((string)($ship['voucher_number'] ?? '')),
            'original_shipper_code' => $point !== '' ? $point : $this->setting('billing_account'),
            'shipper_details'       => ['name' => $name, 'address' => $c['address'], 'city' => $c['city'], 'postcode' => $c['postcode'], 'phone' => $phone['phone'], 'email' => $c['email']],
            'locker_size'           => $locker_size,
            'locker_reference'      => CCReturnsRules::locker_reference($locker_size),
        ];
        $id = $this->repo->insert($row);
        if (!is_int($id)) {
            // A parallel request with the same uid won the race.
            $existing = $this->existingForUid($uid, $order, $customer_id);
            if ($existing !== null) {
                return $existing;
            }
            if ($id === 'duplicate') {
                $row['reference_token'] = self::newToken(); // token collision (very unlikely): once more
                $id = $this->repo->insert($row);
            }
            if (!is_int($id)) {
                $existing = $this->existingForUid($uid, $order, $customer_id);
                if ($existing !== null) {
                    return $existing;
                }
                $this->log('error', sprintf('Επιστροφή: αποτυχία καταχώρισης (παραγγελία %d, %s): %s', $row['order_id'], $method, $id));
                return self::fail_result('db', 'Δεν ήταν δυνατή η καταχώριση του αιτήματος. Δοκιμάστε ξανά.');
            }
        }
        $this->log('info', sprintf('Επιστροφή #%d: νέο αίτημα (παραγγελία %d, %s).', $id, $row['order_id'], $method));
        $this->note($row, sprintf('↩️ Αίτημα επιστροφής #%d από τον πελάτη (%s).', $id, CCReturnsRules::method_label($method)));

        if ($this->settings->requireApproval()) {
            $this->notify('received', $id);
            return ['ok' => true, 'id' => $id];
        }
        // Without approval: create first. On success the customer only gets "created", otherwise "received".
        if ($this->repo->transition($id, [CCReturnsRules::ST_PENDING], CCReturnsRules::ST_APPROVED, ['approved_at' => CCReturnsRepo::now()])) {
            $this->create($id);
        }
        $fresh = $this->repo->get($id);
        if (!$fresh || $fresh['status'] !== CCReturnsRules::ST_CREATED) {
            $this->notify('received', $id);
        }
        return ['ok' => true, 'id' => $id];
    }

    /** pending_approval → approved → create(). */
    public function approve(int $id, int $admin_id): array {
        $ok = $this->repo->transition($id, [CCReturnsRules::ST_PENDING], CCReturnsRules::ST_APPROVED, ['decided_by' => $admin_id, 'approved_at' => CCReturnsRepo::now()]);
        if (!$ok) {
            return self::fail_result('not_pending', self::MSG_NOT_PENDING);
        }
        $this->log('info', sprintf('Επιστροφή #%d: εγκρίθηκε.', $id));
        return $this->create($id);
    }

    /** pending_approval | approved | creation_failed → rejected. */
    public function reject(int $id, int $admin_id): array {
        $ok = $this->repo->transition($id, [CCReturnsRules::ST_PENDING, CCReturnsRules::ST_APPROVED, CCReturnsRules::ST_FAILED], CCReturnsRules::ST_REJECTED, ['decided_by' => $admin_id]);
        if (!$ok) {
            return self::fail_result('not_pending', self::MSG_NOT_PENDING);
        }
        $row = $this->repo->get($id);
        $this->log('info', sprintf('Επιστροφή #%d: απορρίφθηκε.', $id));
        if ($row) {
            $this->note($row, sprintf('↩️ Επιστροφή #%d: απορρίφθηκε.', $id));
        }
        $this->notify('rejected', $id);
        return ['ok' => true];
    }

    // ── Creation ─────────────────────────────────────────────────────────

    /**
     * approved | creation_failed → creating (atomic claim) → API.
     * - needs_verification: ONLY verify(); never a second create in the same call.
     * - creation_failed after an attempt: look the token up first (an earlier timeout may have
     *   succeeded later). Found → created; "No shipments found" → retry; other error → needs_verification.
     * @return array ['ok' => true, 'row' => array] | error
     */
    public function create(int $id): array {
        $this->releaseStaleLocks();
        $row = $this->repo->get($id);
        if (!$row) {
            return self::fail_result('not_found', 'Το αίτημα επιστροφής δεν βρέθηκε.');
        }
        if ($row['status'] === CCReturnsRules::ST_VERIFY) {
            return $this->verify($id);
        }
        if ($row['status'] === CCReturnsRules::ST_FAILED && (int)$row['attempts'] > 0) {
            $checked = $this->recheckBeforeRetry($row);
            if ($checked !== null) {
                return $checked;
            }
        }
        $claimed = $this->repo->transition($id, [CCReturnsRules::ST_APPROVED, CCReturnsRules::ST_FAILED], CCReturnsRules::ST_CREATING, ['locked_at' => CCReturnsRepo::nowGmt(), 'attempts' => '+1']);
        if (!$claimed) {
            return self::fail_result('busy', self::MSG_BUSY);
        }
        return $this->runCreate($this->repo->get($id));
    }

    /**
     * Look the shipment up by reference token (needs_verification only). "Not found" within LOCK_TTL
     * of the uncertain attempt is not final (a gateway timeout may return while the API still creates).
     */
    public function verify(int $id): array {
        $row = $this->repo->get($id);
        if (!$row || $row['status'] !== CCReturnsRules::ST_VERIFY) {
            return self::fail_result('not_verifiable', 'Το αίτημα δεν βρίσκεται σε κατάσταση ελέγχου.');
        }
        $look = $this->lookupToken($row);
        if ($look['state'] === 'found') {
            return $this->markCreated($row, [CCReturnsRules::ST_VERIFY], $look['fields'], $look['warnings']);
        }
        if ($look['state'] === 'none') {
            if (self::attemptIsRecent($row)) {
                return $this->recheckLater($row);
            }
            return $this->fail($row, $look['message'], [CCReturnsRules::ST_VERIFY]);
        }
        return $this->stillUnverified($row, $look['message']);
    }

    /** creating with locked_at older than LOCK_TTL → needs_verification (the request that held it died). */
    public function releaseStaleLocks(): int {
        $cutoff = gmdate('Y-m-d H:i:s', time() - CCReturnsRepo::LOCK_TTL);
        $count = 0;
        foreach ($this->repo->query(['status' => CCReturnsRules::ST_CREATING, 'limit' => 200]) as $row) {
            if (!empty($row['locked_at']) && $row['locked_at'] >= $cutoff) {
                continue;
            }
            if ($this->repo->transition($row['id'], [CCReturnsRules::ST_CREATING], CCReturnsRules::ST_VERIFY, ['locked_at' => null])) {
                $count++;
                $this->log('warning', sprintf('Επιστροφή #%d (παραγγελία %d, %s): ληγμένο κλείδωμα δημιουργίας → έλεγχος ύπαρξης.', $row['id'], $row['order_id'], $row['method']));
            }
        }
        return $count;
    }

    private function runCreate(array $row): array {
        // The original shipment: its real sender (pickup point) comes from the API.
        $details = $this->api->get_shipment_details($row['original_awb']);
        if (!self::detailsOk($details)) {
            return $this->fail($row, 'Αποτυχία ανάκτησης αρχικής αποστολής: ' . self::apiErrorText($details));
        }
        $info = null;
        foreach ($details['ShipmentDetails'] as $detail) {
            $candidate = (isset($detail['ShipmentInfo']) && is_array($detail['ShipmentInfo'])) ? $detail['ShipmentInfo'] : [];
            if (trim((string)($candidate['ShipmentAwb'] ?? '')) === $row['original_awb']) {
                $info = $candidate;
                break;
            }
        }
        if ($info === null) {
            $first = reset($details['ShipmentDetails']);
            $info = (isset($first['ShipmentInfo']) && is_array($first['ShipmentInfo'])) ? $first['ShipmentInfo'] : [];
        }
        $api_code = trim((string)($info['Shipper']['Code'] ?? ''));
        $point = '';
        foreach (CCPickupPoints::fromConfig($this->config)->all() as $p) {
            if ((string)$p['code'] !== '' && CCReturnsRules::codes_equal($p['code'], $api_code)) {
                $point = (string)$p['code'];
                break;
            }
        }
        if ($point === '') {
            return $this->fail($row, sprintf('Ο αποστολέας της αρχικής αποστολής (%s) δεν είναι σημείο αποστολής του καταστήματος.', $api_code));
        }
        if (!CCReturnsRules::codes_equal($point, $row['original_shipper_code'])) {
            $this->log('warning', sprintf('Επιστροφή #%d (παραγγελία %d, %s): ο αποστολέας της αρχικής στο API (%s) διαφέρει από το σημείο της παραγγελίας (%s)· ισχύει του API.', $row['id'], $row['order_id'], $row['method'], $point, $row['original_shipper_code']));
        }
        $mapped = $this->settings->returnAccountFor($point);
        $fields = [
            'original_shipper_code' => $point,
            'return_account_code'   => $mapped !== '' ? $mapped : $point,
            'requestor_account'     => $this->setting('billing_account'),
        ];
        $this->repo->update($row['id'], $fields);
        $row = array_merge($row, $fields);

        $payload = CCReturnPayload::build($row, [
            'UserAlias'       => $this->setting('user_alias'),
            'CredentialValue' => $this->setting('credential_value'),
            'ApiKey'          => $this->setting('api_key'),
        ], $this->settings->weight());
        if (isset($payload['error'])) {
            return $this->fail($row, $payload['error']);
        }

        $res = $this->api->create_shipment($payload);
        if (isset($res['success']) && $res['success'] === false) {
            if (!empty($res['exception'])) {
                return $this->toVerification($row, 'Αβέβαιο αποτέλεσμα δημιουργίας: ' . (string)($res['error'] ?? ''));
            }
            return $this->fail($row, (string)($res['error'] ?? 'Άγνωστο σφάλμα από το API'));
        }
        $awb = trim((string)($res['ShipmentNumber'] ?? ''));
        if (!empty($res['ShipmentAlreadyExist'])) {
            return $this->toVerification($row, 'Το API απάντησε ότι η αποστολή υπάρχει ήδη· έλεγχος ύπαρξης.');
        }
        if ($awb === '') {
            return $this->toVerification($row, 'Το API δεν επέστρεψε αριθμό αποστολής· έλεγχος ύπαρξης.');
        }
        return $this->markCreated($row, [CCReturnsRules::ST_CREATING], [
            'return_awb'              => $awb,
            'return_shipment_number'  => (string)($res['ReturnShipmentNumber'] ?? ''),
            'inbound_shipment_number' => (string)($res['InboundShipmentNumber'] ?? ''),
            'shipment_master_id'      => (string)($res['ShipmentMasterId'] ?? ''),
        ]);
    }

    /** @return array state 'found' (fields, warnings) | 'none' (message) | 'error' (message) */
    private function lookupToken(array $row): array {
        $res = $this->api->get_shipment_details($row['reference_token']);
        if (isset($res['success']) && $res['success'] === false) {
            return ['state' => 'error', 'message' => (string)($res['error'] ?? 'Μη έγκυρη απάντηση του API')];
        }
        if (!self::detailsOk($res)) {
            $msg = self::apiErrorText($res);
            if (stripos($msg, 'No shipments found') === false) {
                return ['state' => 'error', 'message' => $msg];
            }
            return ['state' => 'none', 'message' => 'Δεν βρέθηκε αποστολή για το αίτημα· μπορείτε να δοκιμάσετε ξανά τη δημιουργία.'];
        }
        $alive = [];
        foreach ($res['ShipmentDetails'] as $detail) {
            $info = (isset($detail['ShipmentInfo']) && is_array($detail['ShipmentInfo'])) ? $detail['ShipmentInfo'] : [];
            if ((string)($info['ShipmentAwb'] ?? '') !== '' && (int)($info['CollectionStatus'] ?? 0) !== CCReturnsRules::COLLECTION_CANCELLED) {
                $alive[] = $info;
            }
        }
        if (!$alive) {
            return ['state' => 'none', 'message' => 'Βρέθηκαν μόνο ακυρωμένες αποστολές για το αίτημα· μπορείτε να δοκιμάσετε ξανά τη δημιουργία.'];
        }
        $info = $alive[0];
        $warnings = count($alive) > 1 ? [sprintf('Βρέθηκαν %d αποστολές με το ίδιο token', count($alive))] : [];
        return [
            'state'    => 'found',
            'warnings' => $warnings,
            'fields'   => [
                'return_awb'              => (string)$info['ShipmentAwb'],
                // The API writes the BOX NOW return code into Reference4.
                'return_shipment_number'  => $row['method'] === CCReturnsRules::METHOD_BOXNOW ? (string)($info['Reference4'] ?? '') : '',
                'inbound_shipment_number' => (string)($info['InboundAwb'] ?? ''),
                'shipment_master_id'      => (string)($info['ShipmentId'] ?? ''),
            ],
        ];
    }

    /** @return array|null null = no shipment, go on with create */
    private function recheckBeforeRetry(array $row) {
        $look = $this->lookupToken($row);
        if ($look['state'] === 'found') {
            return $this->markCreated($row, [CCReturnsRules::ST_FAILED], $look['fields'], $look['warnings']);
        }
        if ($look['state'] === 'none') {
            return null;
        }
        $text = self::clip('Ο έλεγχος ύπαρξης πριν από την επανάληψη δεν ολοκληρώθηκε: ' . $look['message']);
        if ($this->repo->transition($row['id'], [CCReturnsRules::ST_FAILED], CCReturnsRules::ST_VERIFY, ['last_error' => $text, 'locked_at' => null])) {
            $this->log('warning', sprintf('Επιστροφή #%d (παραγγελία %d, %s): %s', $row['id'], $row['order_id'], $row['method'], $text));
        }
        return self::fail_result('unverified', $text);
    }

    private function markCreated(array $row, array $from, array $fields, array $warnings = []): array {
        if ($row['method'] === CCReturnsRules::METHOD_BOXNOW && $fields['return_shipment_number'] === '') {
            $warnings[] = self::MSG_NO_RSN;
        }
        $fields['locked_at'] = null;
        $fields['shipment_created_at'] = CCReturnsRepo::now();
        $fields['last_error'] = implode(' · ', $warnings);
        if ($this->repo->transition($row['id'], $from, CCReturnsRules::ST_CREATED, $fields)) {
            $this->note($row, sprintf('↩️ Επιστροφή #%d: AWB %s (%s)', $row['id'], $fields['return_awb'], CCReturnsRules::method_label($row['method']))
                . ($fields['return_shipment_number'] !== '' ? ' | Κωδικός BOX NOW: ' . $fields['return_shipment_number'] : ''));
            $this->log('info', sprintf('Επιστροφή #%d (παραγγελία %d, %s): δημιουργήθηκε αποστολή.', $row['id'], $row['order_id'], $row['method']));
            foreach ($warnings as $w) {
                $this->log('warning', sprintf('Επιστροφή #%d (παραγγελία %d, %s): %s', $row['id'], $row['order_id'], $row['method'], $w));
                if ($w === self::MSG_NO_RSN) {
                    $this->note($row, sprintf('↩️ Επιστροφή #%d: %s', $row['id'], $w));
                }
            }
            $this->notify('created', $row['id']);
            return ['ok' => true, 'row' => $this->repo->get($row['id'])];
        }
        $fresh = $this->repo->get($row['id']);
        $status = $fresh ? $fresh['status'] : '';
        if (in_array(CCReturnsRules::ST_CREATING, $from, true)) {
            $this->log('error', sprintf('Επιστροφή #%d (παραγγελία %d, %s): η αποστολή AWB %s%s δημιουργήθηκε στο API αλλά το αίτημα είχε ήδη αλλάξει κατάσταση (%s) και δεν αποθηκεύτηκε — έλεγχος για διπλό AWB.',
                $row['id'], $row['order_id'], $row['method'], $fields['return_awb'], $fields['return_shipment_number'] !== '' ? ' / BOX NOW ' . $fields['return_shipment_number'] : '', $status));
        }
        if ($fresh && $status === CCReturnsRules::ST_CREATED) {
            return ['ok' => true, 'row' => $fresh];
        }
        return self::fail_result('conflict', 'Η κατάσταση του αιτήματος άλλαξε κατά τη δημιουργία· ελέγξτε το αίτημα.');
    }

    private function fail(array $row, string $message, array $from = [CCReturnsRules::ST_CREATING]): array {
        $message = self::clip($message);
        if ($this->repo->transition($row['id'], $from, CCReturnsRules::ST_FAILED, ['last_error' => $message, 'locked_at' => null])) {
            $this->note($row, sprintf('↩️ Επιστροφή #%d: αποτυχία δημιουργίας — %s', $row['id'], $message));
            $this->log('error', sprintf('Επιστροφή #%d (παραγγελία %d, %s): αποτυχία δημιουργίας — %s', $row['id'], $row['order_id'], $row['method'], $message));
        }
        return self::fail_result('creation_failed', $message);
    }

    /** creating → needs_verification, then verify() at once (never a second create). locked_at is kept. */
    private function toVerification(array $row, string $message): array {
        $message = self::clip($message);
        $this->repo->transition($row['id'], [CCReturnsRules::ST_CREATING], CCReturnsRules::ST_VERIFY, ['last_error' => $message]);
        $this->log('warning', sprintf('Επιστροφή #%d (παραγγελία %d, %s): %s', $row['id'], $row['order_id'], $row['method'], $message));
        return $this->verify($row['id']);
    }

    private static function attemptIsRecent(array $row): bool {
        if (empty($row['locked_at'])) {
            return false;
        }
        return $row['locked_at'] >= gmdate('Y-m-d H:i:s', time() - CCReturnsRepo::LOCK_TTL);
    }

    private function recheckLater(array $row): array {
        $prev = trim((string)$row['last_error']);
        if (strpos($prev, self::MSG_RECHECK) !== false) {
            $text = $prev;
        } else {
            $prev = self::clip($prev);
            $text = ($prev !== '' ? $prev . ' · ' : '') . self::MSG_RECHECK;
        }
        $this->repo->transition($row['id'], [CCReturnsRules::ST_VERIFY], CCReturnsRules::ST_VERIFY, ['last_error' => $text]);
        $this->log('warning', sprintf('Επιστροφή #%d (παραγγελία %d, %s): η αποστολή δεν βρέθηκε ακόμη μετά από αβέβαιη δημιουργία· μένει σε έλεγχο.', $row['id'], $row['order_id'], $row['method']));
        return self::fail_result('unverified', $text);
    }

    private function stillUnverified(array $row, string $message): array {
        $text = self::clip('Ο έλεγχος ύπαρξης της αποστολής δεν ολοκληρώθηκε: ' . $message);
        $this->repo->transition($row['id'], [CCReturnsRules::ST_VERIFY], CCReturnsRules::ST_VERIFY, ['last_error' => $text]);
        $this->log('warning', sprintf('Επιστροφή #%d (παραγγελία %d, %s): %s', $row['id'], $row['order_id'], $row['method'], $text));
        return self::fail_result('unverified', $text);
    }

    /** @return array|null the existing request of this uid (same order + customer), error for someone else's, null if none */
    private function existingForUid(string $uid, array $order, int $customer_id) {
        $row = $this->repo->getByUid($uid);
        if (!$row) {
            return null;
        }
        if ((int)$row['order_id'] !== (int)($order['order_id'] ?? 0) || (int)$row['customer_id'] !== $customer_id) {
            return self::fail_result('forbidden', self::MSG_FORBIDDEN);
        }
        return ['ok' => true, 'id' => (int)$row['id'], 'existing' => true];
    }

    /** @return array lines [{item_id, product_id, name, qty}] with qty > 0 | ['error', 'code'] */
    private function validateItems(array $order, $requested): array {
        if (!is_array($requested)) {
            return ['code' => 'no_items', 'error' => 'Επιλέξτε τουλάχιστον ένα τεμάχιο για επιστροφή.'];
        }
        $available = $this->availableItems($order);
        $items = $this->orderItems((int)$order['order_id']);
        $lines = [];
        foreach ($requested as $item_id => $qty) {
            $item_id = (int)$item_id;
            if (!isset($available[$item_id], $items[$item_id])) {
                return ['code' => 'invalid_item', 'error' => 'Μη έγκυρο προϊόν στο αίτημα επιστροφής.'];
            }
            $raw = is_int($qty) ? (string)$qty : (is_string($qty) ? trim($qty) : '');
            if ($raw === '' || !ctype_digit($raw)) {
                return ['code' => 'invalid_qty', 'error' => 'Οι ποσότητες πρέπει να είναι ακέραιοι αριθμοί από 0 και πάνω.'];
            }
            $qty = (int)$raw;
            if ($qty > $available[$item_id]['available']) {
                return ['code' => 'qty_exceeds', 'error' => sprintf('Η ποσότητα για «%s» ξεπερνά τη διαθέσιμη (%d).', $available[$item_id]['name'], $available[$item_id]['available'])];
            }
            if ($qty > 0) {
                $lines[] = ['item_id' => $item_id, 'product_id' => (int)$items[$item_id]['product_id'], 'name' => $available[$item_id]['name'], 'qty' => $qty];
            }
        }
        if (!$lines) {
            return ['code' => 'no_items', 'error' => 'Επιλέξτε τουλάχιστον ένα τεμάχιο για επιστροφή.'];
        }
        return $lines;
    }

    private static function detailsOk($res): bool {
        return is_array($res) && ($res['Result'] ?? '') === 'Success' && !empty($res['ShipmentDetails']) && is_array($res['ShipmentDetails']);
    }

    private static function apiErrorText($res): string {
        if (!is_array($res)) {
            return 'Μη έγκυρη απάντηση του API';
        }
        if (isset($res['success']) && $res['success'] === false) {
            return (string)($res['error'] ?? 'Σφάλμα API');
        }
        if (!empty($res['Errors'][0]['Message'])) {
            return (string)$res['Errors'][0]['Message'];
        }
        if (($res['Result'] ?? '') === 'Success') {
            return 'No shipments found';
        }
        return 'Άγνωστο σφάλμα από το API';
    }

    private static function newToken(): string {
        return 'RR' . strtoupper(bin2hex(random_bytes(6)));
    }

    private static function len(string $s): int {
        return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
    }

    private static function clip(string $text): string {
        if (self::len($text) <= self::ERROR_MAX) {
            return $text;
        }
        return (function_exists('mb_substr') ? mb_substr($text, 0, self::ERROR_MAX, 'UTF-8') : substr($text, 0, self::ERROR_MAX)) . '…';
    }

    private function note(array $row, string $text): void {
        (new CCOrderHistory($this->db, $this->prefix))->add((int)$row['order_id'], $text);
    }

    private function notify(string $event, int $id): void {
        if ($this->notifier !== null) {
            try {
                call_user_func($this->notifier, $event, $id);
            } catch (\Throwable $e) {
                $this->log('error', sprintf('Επιστροφή #%d: αποτυχία ειδοποίησης %s — %s', $id, $event, self::clip($e->getMessage())));
            }
        }
    }

    /** Only request / order ids, method and API error text - never customer data or credentials. */
    private function log(string $level, string $message): void {
        if ($this->logger !== null) {
            call_user_func($this->logger, $level, $message);
        }
    }
}
