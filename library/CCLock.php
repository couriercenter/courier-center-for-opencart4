<?php
/**
 * Short-lived named locks in the cc_locks table (shared by OpenCart 3 and 4, PHP 7.2+).
 * Used so that one order never gets two vouchers: double click, two tabs, bulk + auto-create.
 * Port of CC_Shipment_Builder::acquire_voucher_lock (WooCommerce): INSERT IGNORE + TTL takeover.
 */

namespace Opencart\Extension\Couriercenter\Library;

class CCLock {

    const TTL = 180;

    /** @var object */
    private $db;
    /** @var string */
    private $table;
    /** @var array name => token */
    private $held = [];

    public function __construct($db, string $prefix) {
        $this->db = $db;
        $this->table = $prefix . 'cc_locks';
    }

    /** @return bool false when another request holds a fresh lock with that name */
    public function acquire(string $name, int $now = 0): bool {
        $now = $now > 0 ? $now : time();
        $name = substr($name, 0, 64);
        $token = bin2hex(random_bytes(8));
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->db->query("INSERT IGNORE INTO `" . $this->table . "` SET `name` = '" . $this->db->escape($name) . "', `token` = '" . $token . "', `locked_at` = '" . $now . "'");
            $q = $this->db->query("SELECT `token`, `locked_at` FROM `" . $this->table . "` WHERE `name` = '" . $this->db->escape($name) . "' LIMIT 1");
            if (!$q->num_rows) {
                continue; // released in between: try again
            }
            if ((string)$q->row['token'] === $token) {
                $this->held[$name] = $token;
                return true;
            }
            if ($attempt > 0 || $now - (int)$q->row['locked_at'] < self::TTL) {
                return false;
            }
            // A request that died while holding it: take it over (only if nobody else did meanwhile).
            $this->db->query("DELETE FROM `" . $this->table . "` WHERE `name` = '" . $this->db->escape($name) . "' AND `token` = '" . $this->db->escape((string)$q->row['token']) . "'");
        }
        return false;
    }

    public function release(string $name): void {
        $name = substr($name, 0, 64);
        if (!isset($this->held[$name])) {
            return;
        }
        $this->db->query("DELETE FROM `" . $this->table . "` WHERE `name` = '" . $this->db->escape($name) . "' AND `token` = '" . $this->db->escape($this->held[$name]) . "'");
        unset($this->held[$name]);
    }

    public static function voucherName(int $order_id): string {
        return 'voucher_' . $order_id;
    }
}
