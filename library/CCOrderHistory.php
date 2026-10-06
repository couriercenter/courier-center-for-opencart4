<?php
/**
 * Order history notes for the Courier Center extension (shared by OpenCart 3 and 4).
 * Raw SQL on purpose: going through the order model would fire the addOrderHistory
 * events again (auto-create voucher / tracking email).
 * PHP 7.2+ syntax.
 */

namespace Opencart\Extension\Couriercenter\Library;

class CCOrderHistory {

    /** @var object OpenCart DB (query(), escape()) */
    private $db;

    /** @var string table prefix (DB_PREFIX) */
    private $prefix;

    public function __construct($db, string $prefix) {
        $this->db = $db;
        $this->prefix = $prefix;
    }

    /**
     * Append a comment to the order's History tab. With $new_status_id > 0 (and different
     * from the current status) the order also moves to that status. The customer is never
     * notified. Abandoned checkouts (status 0) and unknown orders are left alone.
     *
     * @return bool true when a history row was written
     */
    public function add(int $order_id, string $comment, int $new_status_id = 0): bool {
        if ($order_id <= 0) {
            return false;
        }
        $cur = $this->db->query("SELECT `order_status_id` FROM `" . $this->prefix . "order` WHERE `order_id` = '" . $order_id . "' LIMIT 1");
        if (!$cur->num_rows) {
            return false;
        }
        $current = (int)$cur->row['order_status_id'];
        $status  = $new_status_id > 0 ? $new_status_id : $current;
        if ($status <= 0) {
            return false;
        }

        $this->db->query("INSERT INTO `" . $this->prefix . "order_history` SET `order_id` = '" . $order_id . "', `order_status_id` = '" . $status . "', `notify` = '0', `comment` = '" . $this->db->escape($comment) . "', `date_added` = NOW()");

        if ($new_status_id > 0 && $new_status_id !== $current) {
            $this->db->query("UPDATE `" . $this->prefix . "order` SET `order_status_id` = '" . $new_status_id . "', `date_modified` = NOW() WHERE `order_id` = '" . $order_id . "'");
        }
        return true;
    }
}
