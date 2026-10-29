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

    /** @var bool|null does the DB connection store 4-byte UTF-8 (emoji)? null = not asked yet */
    private $utf8mb4;

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

        $this->db->query("INSERT INTO `" . $this->prefix . "order_history` SET `order_id` = '" . $order_id . "', `order_status_id` = '" . $status . "', `notify` = '0', `comment` = '" . $this->db->escape($this->storable($comment)) . "', `date_added` = NOW()");

        if ($new_status_id > 0 && $new_status_id !== $current) {
            $this->db->query("UPDATE `" . $this->prefix . "order` SET `order_status_id` = '" . $new_status_id . "', `date_modified` = NOW() WHERE `order_id` = '" . $order_id . "'");
        }
        return true;
    }

    /**
     * OpenCart 3 connects with utf8 (3 bytes per character): MySQL stores every emoji as "????".
     * There the emoji (and the space after it) are left out; OpenCart 4 (utf8mb4) keeps them.
     */
    private function storable(string $comment): string {
        if ($this->utf8mb4 === null) {
            $q = $this->db->query("SELECT @@character_set_connection AS cs");
            $this->utf8mb4 = isset($q->row['cs']) && strpos((string)$q->row['cs'], 'utf8mb4') === 0;
        }
        if ($this->utf8mb4) {
            return $comment;
        }
        $clean = preg_replace('/[\x{10000}-\x{10FFFF}]\x{FE0F}? ?/u', '', $comment);
        return $clean === null ? $comment : $clean;
    }
}
