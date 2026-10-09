<?php
/** Read-only config (store 0) for CLI scripts, where OpenCart's Config is not loaded. */

namespace Opencart\Extension\Couriercenter\Library\Oc4;

class CCOc4ConfigSnapshot {
    private array $data = [];

    public function __construct($db, string $prefix) {
        foreach ($db->query("SELECT `key`, `value`, `serialized` FROM `" . $prefix . "setting` WHERE `store_id` = '0'")->rows as $row) {
            $this->data[$row['key']] = !empty($row['serialized']) ? json_decode($row['value'], true) : $row['value'];
        }
    }

    public function get(string $key) {
        return $this->data[$key] ?? null;
    }
}
