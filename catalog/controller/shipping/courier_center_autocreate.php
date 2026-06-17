<?php
namespace Opencart\Catalog\Controller\Extension\Couriercenter\Shipping;

/**
 * Auto-create voucher on order-status change (port of the WooCommerce v1.3.3
 * feature "🤖 Αυτόματη Δημιουργία Voucher").
 *
 * Event: catalog/model/checkout/order/addHistory/after
 *   args = [order_id, order_status_id, comment, notify, override]
 *
 * When auto-create is enabled and an order moves into the configured trigger
 * status, a "next day" voucher is created automatically (with BOX NOW
 * auto-detection), unless an active voucher already exists. Everything is
 * wrapped in try/catch so a failure NEVER breaks the order-status change.
 */
class CourierCenterAutocreate extends \Opencart\System\Engine\Controller {

    public function autoCreate(string &$route, array &$args, mixed &$output): void {
        try {
            if ((string)$this->config->get('shipping_courier_center_auto_create_enabled') !== '1') {
                return;
            }
            $trigger = (int)$this->config->get('shipping_courier_center_auto_create_status_id');
            if ($trigger <= 0) {
                return;
            }

            $order_id   = (int)($args[0] ?? 0);
            $new_status = (int)($args[1] ?? 0);
            if ($order_id <= 0 || $new_status !== $trigger) {
                return;
            }

            // Skip if an active (non-voided) voucher already exists.
            $ex = $this->db->query(
                "SELECT `voucher_number`, `is_voided` FROM `" . DB_PREFIX . "cc_shipments`
                 WHERE `order_id` = '" . (int)$order_id . "' LIMIT 1"
            );
            $had_voided = false;
            if ($ex->num_rows) {
                if ($ex->row['voucher_number'] !== '' && (int)$ex->row['is_voided'] === 0) {
                    return; // already has an active voucher
                }
                $had_voided = ((int)$ex->row['is_voided'] === 1);
            }

            $this->load->model('checkout/order');
            $order = $this->model_checkout_order->getOrder($order_id);
            if (!$order) {
                return;
            }
            $products = $this->ccEnrichProducts($this->model_checkout_order->getProducts($order_id));

            // BOX NOW detection from the order's custom_field (set at checkout).
            // The catalog model returns custom_field already decoded as an array;
            // be tolerant of both array and JSON-string shapes.
            $cf = $order['custom_field'] ?? [];
            if (is_array($cf)) {
                $custom = $cf;
            } else {
                $custom = json_decode((string)$cf, true) ?: [];
            }
            $locker_id     = (string)($custom['cc_locker_id']     ?? '');
            $locker_code   = (string)($custom['cc_locker_code']   ?? '');
            $locker_name   = (string)($custom['cc_locker_name']   ?? '');
            $delivery_mode = (string)($custom['cc_delivery_mode'] ?? '');
            $boxnow        = ($locker_id !== '' || $delivery_mode === 'auto');

            require_once DIR_EXTENSION . 'couriercenter/library/CCOrderAdapter.php';
            require_once DIR_EXTENSION . 'couriercenter/library/CCCityScope.php';
            require_once DIR_EXTENSION . 'couriercenter/library/CCShipmentBuilder.php';
            require_once DIR_EXTENSION . 'couriercenter/library/CCApi.php';

            $adapter = new \Opencart\Extension\Couriercenter\Library\CCOrderAdapter($order, $products);

            // BOX NOW does not support cash-on-delivery — skip with a note.
            if ($boxnow && $adapter->is_cod()) {
                $this->ccNote($order_id, '⚠️ Αυτόματη δημιουργία voucher παραλείφθηκε: το BOX NOW δεν υποστηρίζει αντικαταβολή (COD).');
                return;
            }

            $builder = new \Opencart\Extension\Couriercenter\Library\CCShipmentBuilder($adapter, $this->buildSettings(), 1);

            // On a validation problem, add an order note explaining why the voucher
            // wasn't created (so the merchant sees it in the order timeline) — never
            // surface an error during the status change itself.
            $vs = $builder->validate_settings();
            if ($vs !== true) {
                $this->ccNote($order_id, '⚠️ Αυτόματη δημιουργία voucher παραλείφθηκε: ' . $vs);
                return;
            }
            $vo = $builder->validate_order();
            if ($vo !== true) {
                $this->ccNote($order_id, '⚠️ Αυτόματη δημιουργία voucher παραλείφθηκε: ' . $vo);
                return;
            }

            $payload = $builder->build_payload('next_day', $boxnow, 'none');
            if ($boxnow && $locker_id !== '') {
                $payload['LockerDeliveryInfo'] = ['Prefix' => 'ATH', 'Code' => $locker_id];
            }

            $result = $this->api()->create_shipment($payload);
            if (isset($result['success']) && $result['success'] === false) {
                $this->ccNote($order_id, '❌ Αυτόματη δημιουργία voucher απέτυχε: ' . ($result['error'] ?? 'άγνωστο σφάλμα'));
                return;
            }

            $voucher  = $result['VoucherNumber']  ?? $result['AwbNumber']  ?? $result['ShipmentNumber'] ?? '';
            $tracking = $result['TrackingNumber'] ?? $result['TrackingNo'] ?? ($result['TrackingNumbers'][0] ?? '');
            if ($tracking === '') {
                $tracking = $voucher;
            }
            if ($voucher === '') {
                $this->ccNote($order_id, '❌ Αυτόματη δημιουργία voucher απέτυχε: το API δεν επέστρεψε αριθμό voucher.');
                return;
            }

            // Assigned locker (BOX NOW) — best-effort.
            if ($boxnow && $locker_code === '') {
                $locker_code = (string)(
                    $result['AssignedLockerCode']
                    ?? $result['LockerCode']
                    ?? $result['DestinationLockerCode']
                    ?? $result['LockerDeliveryInfo']['Code']
                    ?? ''
                );
            }

            $this->saveShipment($order_id, [
                'voucher_number'  => $voucher,
                'tracking_number' => $tracking,
                'service_type'    => 'next_day',
                'is_boxnow'       => $boxnow ? 1 : 0,
                'locker_id'       => $locker_id,
                'locker_code'     => $locker_code,
                'locker_name'     => $locker_name,
                'reset_voided'    => $had_voided,
            ]);

            $this->ccNote($order_id, sprintf(
                '🤖 Αυτόματη δημιουργία voucher (αλλαγή status): %s | Υπηρεσία: Επόμενη Μέρα%s',
                $voucher,
                $boxnow ? ' + BOX NOW' : ''
            ));
        } catch (\Throwable $e) {
            // Never break the status change — log silently.
            error_log('CC auto-create voucher error: ' . $e->getMessage());
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function api(): \Opencart\Extension\Couriercenter\Library\CCApi {
        return new \Opencart\Extension\Couriercenter\Library\CCApi(
            (string)$this->config->get('shipping_courier_center_user_alias'),
            (string)$this->config->get('shipping_courier_center_credential_value'),
            (string)$this->config->get('shipping_courier_center_api_key'),
            (string)$this->config->get('shipping_courier_center_billing_account')
        );
    }

    private function buildSettings(): array {
        $postcode = (string)$this->config->get('shipping_courier_center_shipper_postcode');
        $station  = \Opencart\Extension\Couriercenter\Library\CCCityScope::get_station_for_postcode($postcode);
        return [
            'user_alias'       => (string)$this->config->get('shipping_courier_center_user_alias'),
            'credential_value' => (string)$this->config->get('shipping_courier_center_credential_value'),
            'api_key'          => (string)$this->config->get('shipping_courier_center_api_key'),
            'billing_account'  => (string)$this->config->get('shipping_courier_center_billing_account'),
            'shipper_name'     => (string)$this->config->get('shipping_courier_center_shipper_name'),
            'shipper_address'  => (string)$this->config->get('shipping_courier_center_shipper_address'),
            'shipper_postal'   => $postcode,
            'shipper_city'     => (string)$this->config->get('shipping_courier_center_shipper_city'),
            'shipper_phone'    => (string)$this->config->get('shipping_courier_center_shipper_phone'),
            'shipper_station'  => $station,
        ];
    }

    /** Insert/update the shipment row (catalog context — raw SQL). */
    private function saveShipment(int $order_id, array $d): void {
        $voucher  = $this->db->escape($d['voucher_number']  ?? '');
        $tracking = $this->db->escape($d['tracking_number'] ?? '');
        $service  = $this->db->escape($d['service_type']    ?? 'next_day');
        $boxnow   = (int)($d['is_boxnow'] ?? 0);
        $lid      = $this->db->escape($d['locker_id']   ?? '');
        $lcode    = $this->db->escape($d['locker_code'] ?? '');
        $lname    = $this->db->escape($d['locker_name'] ?? '');

        $exists = $this->db->query("SELECT `order_id` FROM `" . DB_PREFIX . "cc_shipments` WHERE `order_id` = '" . (int)$order_id . "' LIMIT 1");

        if ($exists->num_rows) {
            $reset = !empty($d['reset_voided'])
                ? "`is_voided` = 0, `is_final` = 0, `status_code` = '', `status_desc` = '',"
                : '';
            $this->db->query(
                "UPDATE `" . DB_PREFIX . "cc_shipments` SET
                    $reset
                    `voucher_number`  = '$voucher',
                    `tracking_number` = '$tracking',
                    `service_type`    = '$service',
                    `return_option`   = 'none',
                    `return_awb`      = '',
                    `is_boxnow`       = '$boxnow',
                    `locker_id`       = '$lid',
                    `locker_code`     = '$lcode',
                    `locker_name`     = '$lname'
                 WHERE `order_id` = '" . (int)$order_id . "'"
            );
        } else {
            $this->db->query(
                "INSERT INTO `" . DB_PREFIX . "cc_shipments`
                 (`order_id`, `voucher_number`, `tracking_number`, `service_type`,
                  `return_option`, `return_awb`, `is_boxnow`, `locker_id`, `locker_code`, `locker_name`)
                 VALUES ('" . (int)$order_id . "', '$voucher', '$tracking', '$service',
                         'none', '', '$boxnow', '$lid', '$lcode', '$lname')"
            );
        }
    }

    /** Enrich order products with real weight/dimensions from the product table. */
    private function ccEnrichProducts(array $products): array {
        foreach ($products as &$p) {
            $pid = (int)($p['product_id'] ?? 0);
            if ($pid <= 0) continue;
            $q = $this->db->query(
                "SELECT `weight`, `length`, `width`, `height` FROM `" . DB_PREFIX . "product`
                 WHERE `product_id` = '" . $pid . "' LIMIT 1"
            );
            if ($q->num_rows) {
                $p['weight'] = (float)$q->row['weight'];
                $p['length'] = (float)$q->row['length'];
                $p['width']  = (float)$q->row['width'];
                $p['height'] = (float)$q->row['height'];
            }
        }
        unset($p);
        return $products;
    }

    /** Add an order-history comment (raw SQL — never re-triggers addHistory). */
    private function ccNote(int $order_id, string $comment): void {
        $cur = $this->db->query("SELECT `order_status_id` FROM `" . DB_PREFIX . "order` WHERE `order_id` = '" . (int)$order_id . "' LIMIT 1");
        if (!$cur->num_rows) {
            return;
        }
        $status_id = (int)$cur->row['order_status_id'];
        if ($status_id <= 0) {
            return;
        }
        $this->db->query(
            "INSERT INTO `" . DB_PREFIX . "order_history` SET
                `order_id`        = '" . (int)$order_id . "',
                `order_status_id` = '" . (int)$status_id . "',
                `notify`          = '0',
                `comment`         = '" . $this->db->escape($comment) . "',
                `date_added`      = NOW()"
        );
    }
}
