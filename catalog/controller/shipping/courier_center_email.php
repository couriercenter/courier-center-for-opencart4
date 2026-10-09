<?php
namespace Opencart\Catalog\Controller\Extension\Couriercenter\Shipping;

require_once DIR_EXTENSION . 'couriercenter/library/oc4/loader.php';

use Opencart\Extension\Couriercenter\Library\CCMailContent;

/**
 * Tracking inside the shop's own customer emails (like the WooCommerce plugin): the AWB and the tracking
 * link go into the order confirmation (mail/order_invoice) and every status email (mail/order_history)
 * of an order with an active voucher. No separate email any more.
 */
class CourierCenterEmail extends \Opencart\System\Engine\Controller {
    use \Opencart\Extension\Couriercenter\Library\Oc4\CCOc4Controller;

    /** Event catalog/view/mail/order_invoice/after: before the first table (order details). */
    public function orderInvoice(string &$route, array &$args, mixed &$output): void {
        $block = $this->block($args);
        if ($block === '' || !is_string($output)) {
            return;
        }
        $pos = stripos($output, '<table');
        if ($pos === false) {
            $pos = strripos($output, '</body>');
        }
        $output = $pos === false ? $output . $block : substr($output, 0, $pos) . $block . substr($output, $pos);
    }

    /** Event catalog/view/mail/order_history/after: before the footer text. */
    public function orderHistory(string &$route, array &$args, mixed &$output): void {
        $block = $this->block($args);
        if ($block === '' || !is_string($output)) {
            return;
        }
        $footer = (string)($args['text_footer'] ?? '');
        $pos = $footer !== '' ? strrpos($output, $footer) : false;
        $output = $pos === false ? $output . '<br/>' . $block : substr($output, 0, $pos) . $block . substr($output, $pos);
    }

    private function block(array $data): string {
        try {
            if ((string)$this->config->get('shipping_courier_center_email_tracking_enabled') === '0') {
                return '';
            }
            $order_id = (int)($data['order_id'] ?? 0);
            if ($order_id <= 0) {
                return '';
            }
            $shipment = $this->ccService()->getShipment($order_id);
            foreach (['voucher_number', 'tracking_number', 'boxnow_parcel_id'] as $k) {
                if (isset($shipment[$k])) {
                    $shipment[$k] = $this->ccText($shipment[$k]);
                }
            }
            return CCMailContent::trackingHtml($shipment, $this->ccText($this->config->get('shipping_courier_center_tracking_url')));
        } catch (\Throwable $e) {
            $this->log->write('Courier Center tracking in email: ' . $e->getMessage());
            return '';
        }
    }
}
