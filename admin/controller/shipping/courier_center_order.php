<?php
namespace Opencart\Admin\Controller\Extension\Couriercenter\Shipping;

class CourierCenterOrder extends \Opencart\System\Engine\Controller {

    // Voucher actions (create/void/status/BOX NOW/print) live in courier_center.php,
    // which checks permissions and writes the order history. This controller only
    // renders the panel.
    public function orderPanel(string &$route, array &$args, mixed &$output): void {
        $order_id = (int)($this->request->get['order_id'] ?? 0);
        if (!$order_id) return;

        $this->load->model('extension/couriercenter/shipping/courier_center');
        $shipment = $this->model_extension_couriercenter_shipping_courier_center->getShipment($order_id);

        // BOX NOW locker data from catalog session (stored as order custom field)
        $locker_id   = '';
        $locker_name = '';
        $locker_code = '';
        $delivery_mode = '';

        // Read from oc_order_custom_field or session via DB lookup
        $q = $this->db->query(
            "SELECT `custom_field` FROM `" . DB_PREFIX . "order`
             WHERE `order_id` = '" . (int)$order_id . "' LIMIT 1"
        );
        if ($q->row) {
            $custom = json_decode($q->row['custom_field'] ?? '{}', true) ?: [];
            $locker_id     = $custom['cc_locker_id']     ?? '';
            $locker_name   = $custom['cc_locker_name']   ?? '';
            $locker_code   = $custom['cc_locker_code']   ?? '';
            $delivery_mode = $custom['cc_delivery_mode'] ?? '';
        }

        $data['order_id']       = $order_id;
        $data['shipment']       = $shipment;
        $data['locker_id']      = $locker_id;
        $data['locker_name']    = $locker_name;
        $data['locker_code']    = $locker_code;
        $data['delivery_mode']  = $delivery_mode;
        $data['user_token']     = $this->session->data['user_token'];
        $data['ajax_create']    = $this->url->link('extension/couriercenter/shipping/courier_center.createVoucher',   'user_token=' . $this->session->data['user_token'], true);
        $data['ajax_void']      = $this->url->link('extension/couriercenter/shipping/courier_center.voidShipment',    'user_token=' . $this->session->data['user_token'], true);
        $data['ajax_status']    = $this->url->link('extension/couriercenter/shipping/courier_center.updateStatus',    'user_token=' . $this->session->data['user_token'], true);
        $data['ajax_remove_bn'] = $this->url->link('extension/couriercenter/shipping/courier_center.removeBoxNow',    'user_token=' . $this->session->data['user_token'], true);
        $data['download_url']        = $this->url->link('extension/couriercenter/shipping/courier_center.downloadVoucher', 'user_token=' . $this->session->data['user_token'] . '&order_id=' . $order_id, true);
        $data['download_return_url'] = $this->url->link('extension/couriercenter/shipping/courier_center.downloadVoucher', 'user_token=' . $this->session->data['user_token'] . '&order_id=' . $order_id . '&type=return', true);
        $data['token_endpoint']      = HTTP_SERVER . 'cc_token.php';

        $output .= $this->load->view('extension/couriercenter/shipping/order_panel', $data);
    }
}
