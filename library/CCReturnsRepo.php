<?php
/**
 * Returns: cc_return_requests repository with atomic status transitions
 * (UPDATE ... WHERE status IN (...)) so two requests never process the same return.
 * Port of CC_Returns_Repo (WooCommerce 1.6.0). Shared by OpenCart 3 and 4, PHP 7.2+.
 * Dates are the shop's local time, except locked_at (GMT: immune to DST jumps).
 */

namespace Opencart\Extension\Couriercenter\Library;

class CCReturnsRepo {

    const LOCK_TTL = 180;

    /** Columns a caller may write (id never). */
    const COLUMNS = [
        'request_uid', 'reference_token', 'order_id', 'customer_id', 'status', 'method', 'items', 'reason',
        'original_awb', 'original_shipper_code', 'return_account_code', 'requestor_account', 'shipper_details',
        'locker_size', 'locker_reference',
        'return_awb', 'return_shipment_number', 'inbound_shipment_number', 'shipment_master_id',
        'shipment_status', 'shipment_status_desc', 'collection_status', 'display_status', 'tracking_final',
        'last_error', 'attempts', 'locked_at', 'decided_by',
        'created_at', 'updated_at', 'approved_at', 'shipment_created_at', 'last_tracking_at',
    ];
    const JSON_COLUMNS = ['items', 'shipper_details'];
    const INT_COLUMNS = ['id', 'order_id', 'customer_id', 'attempts', 'tracking_final', 'decided_by'];

    /** @var object */
    private $db;
    /** @var string */
    private $table;

    public function __construct($db, string $prefix) {
        $this->db = $db;
        $this->table = $prefix . 'cc_return_requests';
    }

    public static function now(): string {
        return date('Y-m-d H:i:s');
    }

    public static function nowGmt(): string {
        return gmdate('Y-m-d H:i:s');
    }

    /**
     * @return int|string id, or 'duplicate' (same request_uid / reference_token) / 'db_error'
     */
    public function insert(array $row) {
        $row = $this->prepare($row);
        $row['created_at'] = self::now();
        $row['updated_at'] = $row['created_at'];
        if (!array_key_exists('items', $row)) {
            $row['items'] = '[]';
        }
        $set = [];
        foreach ($row as $col => $val) {
            $set[] = $this->assign($col, $val);
        }
        try {
            $this->db->query("INSERT INTO `" . $this->table . "` SET " . implode(', ', $set));
        } catch (\Exception $e) {
            $msg = $e->getMessage();
            return (strpos($msg, '1062') !== false || stripos($msg, 'Duplicate') !== false) ? 'duplicate' : 'db_error';
        }
        return (int)$this->db->getLastId();
    }

    public function get(int $id) {
        $q = $this->db->query("SELECT * FROM `" . $this->table . "` WHERE `id` = '" . $id . "' LIMIT 1");
        return $q->num_rows ? self::hydrate($q->row) : null;
    }

    public function getByUid(string $uid) {
        $q = $this->db->query("SELECT * FROM `" . $this->table . "` WHERE `request_uid` = '" . $this->db->escape($uid) . "' LIMIT 1");
        return $q->num_rows ? self::hydrate($q->row) : null;
    }

    /** Every request of an order, newest first. */
    public function forOrder(int $order_id): array {
        $rows = $this->db->query("SELECT * FROM `" . $this->table . "` WHERE `order_id` = '" . $order_id . "' ORDER BY `id` DESC")->rows;
        return array_map([__CLASS__, 'hydrate'], $rows);
    }

    /** Update whitelisted columns (always updated_at). null → NULL; attempts '+1' → attempts + 1. */
    public function update(int $id, array $fields): bool {
        $set = $this->buildSet($fields);
        if ($set === '') {
            return false;
        }
        $this->db->query("UPDATE `" . $this->table . "` SET " . $set . " WHERE `id` = '" . $id . "'");
        return true;
    }

    /**
     * Atomic transition: UPDATE ... SET status = $to [, $fields] WHERE id AND status IN ($from).
     * @return bool true only when exactly that row changed (else it was not in $from)
     */
    public function transition(int $id, array $from, string $to, array $fields = []): bool {
        $from = array_values(array_filter(array_map('strval', $from), 'strlen'));
        if (!$from) {
            return false;
        }
        unset($fields['status']);
        $set = $this->buildSet(array_merge(['status' => $to], $fields));
        $in = [];
        foreach ($from as $f) {
            $in[] = "'" . $this->db->escape($f) . "'";
        }
        $this->db->query("UPDATE `" . $this->table . "` SET " . $set . " WHERE `id` = '" . $id . "' AND `status` IN (" . implode(',', $in) . ")");
        return (int)$this->db->countAffected() === 1;
    }

    /**
     * List: status (string|array), method, order_id, search (order id / AWB), limit (20), offset (0),
     * order (ASC|DESC by id, default DESC).
     */
    public function query(array $args): array {
        $order = (isset($args['order']) && strtoupper((string)$args['order']) === 'ASC') ? 'ASC' : 'DESC';
        $limit = isset($args['limit']) ? max(1, (int)$args['limit']) : 20;
        $offset = isset($args['offset']) ? max(0, (int)$args['offset']) : 0;
        $rows = $this->db->query("SELECT * FROM `" . $this->table . "`" . $this->where($args) . " ORDER BY `id` " . $order . " LIMIT " . $offset . ", " . $limit)->rows;
        return array_map([__CLASS__, 'hydrate'], $rows);
    }

    public function count(array $args): int {
        $q = $this->db->query("SELECT COUNT(*) AS n FROM `" . $this->table . "`" . $this->where($args));
        return (int)($q->row['n'] ?? 0);
    }

    /** status => count */
    public function countByStatus(): array {
        $out = [];
        foreach ($this->db->query("SELECT `status`, COUNT(*) AS n FROM `" . $this->table . "` GROUP BY `status`")->rows as $r) {
            $out[$r['status']] = (int)$r['n'];
        }
        return $out;
    }

    /** ids of created returns to track: not final, not checked during the last $recheck seconds. */
    public function dueForTracking(int $limit, int $recheck = 3600): array {
        $cutoff = date('Y-m-d H:i:s', time() - $recheck);
        $rows = $this->db->query("SELECT `id` FROM `" . $this->table . "` WHERE `status` = '" . CCReturnsRules::ST_CREATED . "' AND `tracking_final` = 0 AND `return_awb` <> ''"
            . " AND (`last_tracking_at` IS NULL OR `last_tracking_at` < '" . $cutoff . "') ORDER BY (`last_tracking_at` IS NULL) DESC, `last_tracking_at` ASC, `id` ASC LIMIT " . max(1, $limit))->rows;
        return array_map('intval', array_column($rows, 'id'));
    }

    private function where(array $args): string {
        $w = [];
        if (isset($args['status']) && $args['status'] !== '' && $args['status'] !== []) {
            $in = [];
            foreach ((array)$args['status'] as $s) {
                $in[] = "'" . $this->db->escape((string)$s) . "'";
            }
            $w[] = "`status` IN (" . implode(',', $in) . ")";
        }
        if (!empty($args['method'])) {
            $w[] = "`method` = '" . $this->db->escape((string)$args['method']) . "'";
        }
        if (!empty($args['order_id'])) {
            $w[] = "`order_id` = '" . (int)$args['order_id'] . "'";
        }
        if (isset($args['search']) && trim((string)$args['search']) !== '') {
            $s = $this->db->escape(trim((string)$args['search']));
            $w[] = "(`order_id` = '" . (int)$args['search'] . "' OR `original_awb` = '" . $s . "' OR `return_awb` = '" . $s . "' OR `return_shipment_number` = '" . $s . "')";
        }
        return $w ? ' WHERE ' . implode(' AND ', $w) : '';
    }

    private function prepare(array $fields): array {
        $out = [];
        foreach ($fields as $col => $val) {
            if (!in_array($col, self::COLUMNS, true)) {
                continue;
            }
            if (in_array($col, self::JSON_COLUMNS, true) && is_array($val)) {
                $val = json_encode($val, JSON_UNESCAPED_UNICODE);
            }
            $out[$col] = $val;
        }
        return $out;
    }

    private function buildSet(array $fields): string {
        $fields = $this->prepare($fields);
        $fields['updated_at'] = self::now();
        $parts = [];
        foreach ($fields as $col => $val) {
            $parts[] = $this->assign($col, $val);
        }
        return implode(', ', $parts);
    }

    private function assign(string $col, $val): string {
        if ($val === null) {
            return '`' . $col . '` = NULL';
        }
        if ($col === 'attempts' && $val === '+1') {
            return '`attempts` = `attempts` + 1';
        }
        return '`' . $col . "` = '" . $this->db->escape((string)$val) . "'";
    }

    public static function hydrate(array $row): array {
        foreach (self::JSON_COLUMNS as $col) {
            $dec = isset($row[$col]) ? json_decode((string)$row[$col], true) : null;
            $row[$col] = is_array($dec) ? $dec : [];
        }
        foreach (self::INT_COLUMNS as $col) {
            if (isset($row[$col])) {
                $row[$col] = (int)$row[$col];
            }
        }
        return $row;
    }
}
