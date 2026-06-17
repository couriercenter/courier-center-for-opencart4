<?php
namespace Opencart\Admin\Model\Extension\Couriercenter\Shipping;

class CourierCenter extends \Opencart\System\Engine\Model {

    public function install(): void {
        $sql = file_get_contents(DIR_EXTENSION . 'couriercenter/install.sql');
        $this->db->query($sql);
    }

    public function uninstall(): void {
        $this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "cc_shipments`");
    }

    public function getShipment(int $order_id): array {
        $q = $this->db->query(
            "SELECT * FROM `" . DB_PREFIX . "cc_shipments`
             WHERE `order_id` = '" . (int)$order_id . "' LIMIT 1"
        );
        return $q->row ?: [];
    }

    public function getShipmentsByOrderIds(array $order_ids): array {
        if (empty($order_ids)) return [];
        $ids = implode(',', array_map('intval', $order_ids));
        $q   = $this->db->query(
            "SELECT * FROM `" . DB_PREFIX . "cc_shipments`
             WHERE `order_id` IN ($ids)"
        );
        $result = [];
        foreach ($q->rows as $row) {
            $result[$row['order_id']] = $row;
        }
        return $result;
    }

    public function saveShipment(int $order_id, array $data): void {
        $existing = $this->getShipment($order_id);

        $voucher        = $this->db->escape($data['voucher_number']  ?? '');
        $tracking       = $this->db->escape($data['tracking_number'] ?? '');
        $service        = $this->db->escape($data['service_type']    ?? '');
        $return_option  = $this->db->escape($data['return_option']   ?? 'none');
        $return_awb     = $this->db->escape($data['return_awb']      ?? '');
        $is_boxnow      = (int)($data['is_boxnow']    ?? 0);
        $locker_id      = $this->db->escape($data['locker_id']    ?? '');
        $locker_code    = $this->db->escape($data['locker_code']  ?? '');
        $locker_name    = $this->db->escape($data['locker_name']  ?? '');

        if ($existing) {
            $this->db->query(
                "UPDATE `" . DB_PREFIX . "cc_shipments`
                 SET `voucher_number`  = '$voucher',
                     `tracking_number` = '$tracking',
                     `service_type`    = '$service',
                     `return_option`   = '$return_option',
                     `return_awb`      = '$return_awb',
                     `is_boxnow`       = '$is_boxnow',
                     `locker_id`       = '$locker_id',
                     `locker_code`     = '$locker_code',
                     `locker_name`     = '$locker_name'
                 WHERE `order_id` = '" . (int)$order_id . "'"
            );
        } else {
            $this->db->query(
                "INSERT INTO `" . DB_PREFIX . "cc_shipments`
                 (`order_id`, `voucher_number`, `tracking_number`, `service_type`,
                  `return_option`, `return_awb`, `is_boxnow`, `locker_id`, `locker_code`, `locker_name`)
                 VALUES ('" . (int)$order_id . "', '$voucher', '$tracking', '$service',
                         '$return_option', '$return_awb', '$is_boxnow', '$locker_id', '$locker_code', '$locker_name')"
            );
        }
    }

    public function voidShipment(int $order_id): void {
        $this->db->query(
            "UPDATE `" . DB_PREFIX . "cc_shipments`
             SET `is_voided` = 1 WHERE `order_id` = '" . (int)$order_id . "'"
        );
    }

    public function removeBoxNow(int $order_id): void {
        $this->db->query(
            "UPDATE `" . DB_PREFIX . "cc_shipments`
             SET `is_boxnow` = 0, `locker_id` = '', `locker_code` = '', `locker_name` = ''
             WHERE `order_id` = '" . (int)$order_id . "'"
        );
    }

    public function updateStatus(int $order_id, string $code, string $desc, bool $is_final): void {
        $this->db->query(
            "UPDATE `" . DB_PREFIX . "cc_shipments`
             SET `status_code`       = '" . $this->db->escape($code) . "',
                 `status_desc`       = '" . $this->db->escape($desc) . "',
                 `is_final`          = '" . (int)$is_final . "',
                 `last_checked_at`   = '" . time() . "',
                 `status_updated_at` = NOW()
             WHERE `order_id` = '" . (int)$order_id . "'"
        );
    }

    public function getActiveShipments(): array {
        $q = $this->db->query(
            "SELECT * FROM `" . DB_PREFIX . "cc_shipments`
             WHERE `is_voided` = 0
               AND `is_final`  = 0
               AND `voucher_number` != ''"
        );
        return $q->rows;
    }

    public function countShipmentsForDate(string $date): int {
        $q = $this->db->query(
            "SELECT COUNT(*) AS total FROM `" . DB_PREFIX . "cc_shipments`
             WHERE DATE(`created_at`) = '" . $this->db->escape($date) . "'
               AND `voucher_number` != ''
               AND `is_voided` = 0"
        );
        return (int)($q->row['total'] ?? 0);
    }
}
