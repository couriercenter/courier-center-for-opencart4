<?php
namespace Opencart\Catalog\Controller\Extension\Couriercenter\Shipping;

require_once DIR_EXTENSION . 'couriercenter/library/oc4/loader.php';

use Opencart\Extension\Couriercenter\Library\CCVoucherService;

/**
 * Auto-create voucher on order-status change (event catalog/model/checkout/order/addHistory/after, sort 50).
 * Same voucher code as the admin (shared CCVoucherService: lock, default pickup point, BOX NOW detection).
 * A failure NEVER breaks the status change.
 */
class CourierCenterAutocreate extends \Opencart\System\Engine\Controller {
    use \Opencart\Extension\Couriercenter\Library\Oc4\CCOc4Controller;

    /** $args = [order_id, order_status_id, comment, notify, override] */
    public function autoCreate(string &$route, array &$args, mixed &$output): void {
        try {
            if ((string)$this->config->get('shipping_courier_center_auto_create_enabled') !== '1') {
                return;
            }
            $trigger = (int)$this->config->get('shipping_courier_center_auto_create_status_id');
            $order_id = (int)($args[0] ?? 0);
            if ($trigger <= 0 || $order_id <= 0 || (int)($args[1] ?? 0) !== $trigger) {
                return;
            }
            $svc = $this->ccService();
            if (CCVoucherService::isActive($svc->getShipment($order_id))) {
                return;
            }
            $this->load->model('checkout/order');
            $order = $this->model_checkout_order->getOrder($order_id);
            if (!$order) {
                return;
            }
            if (!$svc->isHandledOrder($order)) {
                $svc->history()->add($order_id, '⚠️ Αυτόματη δημιουργία voucher παραλείφθηκε: η μέθοδος αποστολής δεν διαχειρίζεται από το plugin.');
                return;
            }
            $res = $svc->createForOrder($order, array_merge(['service_type' => 'next_day', 'mode' => 'auto'], CCVoucherService::boxnowFromOrder($order)));
            if (empty($res['success']) && ($res['stage'] ?? '') !== 'exists') {
                $prefix = in_array($res['stage'] ?? '', ['api', 'busy'], true) ? '❌ Αυτόματη δημιουργία voucher απέτυχε: ' : '⚠️ Αυτόματη δημιουργία voucher παραλείφθηκε: ';
                $svc->history()->add($order_id, $prefix . ($res['error'] ?? 'Άγνωστο σφάλμα'));
            }
        } catch (\Throwable $e) {
            $this->log->write('Courier Center auto-create voucher: ' . $e->getMessage());
        }
    }
}
