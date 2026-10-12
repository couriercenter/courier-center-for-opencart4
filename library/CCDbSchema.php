<?php
/**
 * Database tables of the Courier Center extension (shared by OpenCart 3 and 4, PHP 7.2+):
 * cc_shipments (one voucher per order), cc_locks (voucher-creation locks) and
 * cc_return_requests (customer returns). MySQL 5.5-safe: no DATETIME DEFAULT CURRENT_TIMESTAMP.
 */

namespace Opencart\Extension\Couriercenter\Library;

class CCDbSchema {

    /** Bump when a table/column is added: ensureOnce() then re-runs ensure() once. */
    const VERSION = '3'; // 3: returns origin / notify_customer / Pickup date and window

    /** The setting row that remembers VERSION (same row in OpenCart 3 and 4). */
    const STATE_CODE = 'courier_center_state';
    const STATE_KEY  = 'shipping_courier_center_schema_version';

    /** @return array table => [column => definition] (+ '__keys' => string[]) */
    public static function tables(): array {
        return [
            'cc_shipments' => [
                'id'                => "INT(11) NOT NULL AUTO_INCREMENT",
                'order_id'          => "INT(11) NOT NULL",
                'voucher_number'    => "VARCHAR(64) DEFAULT NULL",
                'tracking_number'   => "VARCHAR(64) DEFAULT NULL",
                'service_type'      => "VARCHAR(32) DEFAULT NULL",
                'return_option'     => "VARCHAR(16) NOT NULL DEFAULT 'none'",
                'return_awb'        => "VARCHAR(64) DEFAULT NULL",
                'is_boxnow'         => "TINYINT(1) NOT NULL DEFAULT 0",
                'locker_id'         => "VARCHAR(64) DEFAULT NULL",
                'locker_code'       => "VARCHAR(64) DEFAULT NULL",
                'locker_name'       => "VARCHAR(255) DEFAULT NULL",
                'status_code'       => "VARCHAR(8) DEFAULT NULL",
                'status_desc'       => "VARCHAR(255) DEFAULT NULL",
                'is_voided'         => "TINYINT(1) NOT NULL DEFAULT 0",
                'is_final'          => "TINYINT(1) NOT NULL DEFAULT 0",
                'last_checked_at'   => "INT(11) DEFAULT NULL",
                'status_updated_at' => "DATETIME DEFAULT NULL",
                'created_at'        => "DATETIME DEFAULT NULL",
                // 1.1.0
                'pickup_point'      => "VARCHAR(32) DEFAULT NULL",
                'requestor_account' => "VARCHAR(32) DEFAULT NULL",
                'boxnow_parcel_id'  => "VARCHAR(64) DEFAULT NULL",
                '__keys'            => ['PRIMARY KEY (`id`)', 'UNIQUE KEY `order_id` (`order_id`)'],
            ],
            'cc_locks' => [
                'name'       => "VARCHAR(64) NOT NULL",
                'token'      => "VARCHAR(64) NOT NULL",
                'locked_at'  => "INT(11) NOT NULL",
                '__keys'     => ['PRIMARY KEY (`name`)'],
            ],
            'cc_return_requests' => [
                'id'                      => "INT(11) NOT NULL AUTO_INCREMENT",
                'request_uid'             => "VARCHAR(32) NOT NULL",
                'reference_token'         => "VARCHAR(20) NOT NULL",
                'order_id'                => "INT(11) NOT NULL",
                'customer_id'             => "INT(11) NOT NULL DEFAULT 0",
                'status'                  => "VARCHAR(24) NOT NULL",
                'method'                  => "VARCHAR(32) NOT NULL",
                'items'                   => "MEDIUMTEXT NOT NULL",
                'reason'                  => "TEXT NULL",
                'origin'                  => "VARCHAR(10) NOT NULL DEFAULT 'customer'",
                'notify_customer'         => "TINYINT(1) NOT NULL DEFAULT 1",
                'original_awb'            => "VARCHAR(32) NOT NULL DEFAULT ''",
                'original_shipper_code'   => "VARCHAR(32) NOT NULL DEFAULT ''",
                'return_account_code'     => "VARCHAR(32) NOT NULL DEFAULT ''",
                'requestor_account'       => "VARCHAR(32) NOT NULL DEFAULT ''",
                'shipper_details'         => "MEDIUMTEXT NULL",
                'pickup_date'             => "DATE NULL",
                'pickup_time_from'        => "VARCHAR(5) NULL",
                'pickup_time_to'          => "VARCHAR(5) NULL",
                'locker_size'             => "VARCHAR(10) NOT NULL DEFAULT ''",
                'locker_reference'        => "VARCHAR(4) NOT NULL DEFAULT ''",
                'return_awb'              => "VARCHAR(32) NOT NULL DEFAULT ''",
                'return_shipment_number'  => "VARCHAR(32) NOT NULL DEFAULT ''",
                'inbound_shipment_number' => "VARCHAR(64) NOT NULL DEFAULT ''",
                'shipment_master_id'      => "VARCHAR(64) NOT NULL DEFAULT ''",
                'shipment_status'         => "VARCHAR(10) NOT NULL DEFAULT ''",
                'shipment_status_desc'    => "VARCHAR(100) NOT NULL DEFAULT ''",
                'collection_status'       => "VARCHAR(10) NOT NULL DEFAULT ''",
                'display_status'          => "VARCHAR(100) NOT NULL DEFAULT ''",
                'tracking_final'          => "TINYINT(1) NOT NULL DEFAULT 0",
                'last_error'              => "TEXT NULL",
                'attempts'                => "INT(11) NOT NULL DEFAULT 0",
                'locked_at'               => "DATETIME NULL",
                'decided_by'              => "INT(11) NOT NULL DEFAULT 0",
                'created_at'              => "DATETIME NOT NULL",
                'updated_at'              => "DATETIME NOT NULL",
                'approved_at'             => "DATETIME NULL",
                'shipment_created_at'     => "DATETIME NULL",
                'last_tracking_at'        => "DATETIME NULL",
                '__keys'                  => ['PRIMARY KEY (`id`)', 'UNIQUE KEY `request_uid` (`request_uid`)',
                    'UNIQUE KEY `reference_token` (`reference_token`)', 'KEY `order_id` (`order_id`)', 'KEY `status` (`status`)'],
            ],
        ];
    }

    /** Columns of one table (without the '__keys' entry). */
    public static function columns(string $table): array {
        $t = self::tables()[$table] ?? [];
        unset($t['__keys']);
        return $t;
    }

    /**
     * Create missing tables and add missing columns. Idempotent.
     * @return string[] what was done
     */
    public static function ensure($db, string $prefix): array {
        $done = [];
        foreach (self::tables() as $name => $def) {
            $table = $prefix . $name;
            $keys = $def['__keys'];
            unset($def['__keys']);
            if (!$db->query("SHOW TABLES LIKE '" . $db->escape($table) . "'")->num_rows) {
                $cols = [];
                foreach ($def as $col => $sql) {
                    $cols[] = '`' . $col . '` ' . $sql;
                }
                $db->query("CREATE TABLE IF NOT EXISTS `" . $table . "` (" . implode(', ', array_merge($cols, $keys)) . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                $done[] = 'table created: ' . $name;
                continue;
            }
            $have = [];
            foreach ($db->query("SHOW COLUMNS FROM `" . $table . "`")->rows as $row) {
                $have[strtolower($row['Field'])] = true;
            }
            foreach ($def as $col => $sql) {
                if (!isset($have[$col]) && strpos($sql, 'AUTO_INCREMENT') === false) {
                    $db->query("ALTER TABLE `" . $table . "` ADD `" . $col . "` " . $sql);
                    $done[] = 'column added: ' . $name . '.' . $col;
                }
            }
        }
        self::setVersion($db, $prefix);
        return $done;
    }

    /** ensure() only when the stored schema version differs (cheap check for every request). */
    public static function ensureOnce($db, string $prefix): array {
        $q = $db->query("SELECT `value` FROM `" . $prefix . "setting` WHERE `store_id` = '0' AND `key` = '" . self::STATE_KEY . "' LIMIT 1");
        if ($q->num_rows && (string)$q->row['value'] === self::VERSION) {
            return [];
        }
        return self::ensure($db, $prefix);
    }

    private static function setVersion($db, string $prefix): void {
        $db->query("DELETE FROM `" . $prefix . "setting` WHERE `store_id` = '0' AND `key` = '" . self::STATE_KEY . "'");
        $db->query("INSERT INTO `" . $prefix . "setting` SET `store_id` = '0', `code` = '" . self::STATE_CODE . "', `key` = '" . self::STATE_KEY . "', `value` = '" . self::VERSION . "', `serialized` = '0'");
    }
}
