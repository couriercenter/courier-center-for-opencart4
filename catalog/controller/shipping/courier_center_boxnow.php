<?php
namespace Opencart\Catalog\Controller\Extension\Couriercenter\Shipping;

/**
 * BOX NOW checkout widget (storefront).
 *
 * - widget()      injects the locker-picker into the checkout page
 *                 (event: catalog/view/checkout/checkout/after)
 * - saveSession() persists the customer's choice in the catalog session (AJAX)
 * - saveToOrder() writes the chosen locker into oc_order.custom_field when the
 *                 order is created (event: catalog/model/checkout/order/addOrder/after)
 *                 using the same keys the admin order panel already reads.
 */
class CourierCenterBoxnow extends \Opencart\System\Engine\Controller {

    const PARTNER_ID = 10853;

    public function widget(string &$route, array &$data, mixed &$output): void {
        if ((string)$this->config->get('shipping_courier_center_boxnow_enabled') !== '1') {
            return;
        }
        // Only on the checkout page output, and never twice.
        if (strpos((string)$output, 'id="checkout-checkout"') === false) {
            return;
        }
        if (strpos((string)$output, 'cc-boxnow-wrap') !== false) {
            return;
        }

        $sel = $this->session->data['cc_boxnow'] ?? [];

        // Which shipping methods show the widget (merchant setting; default CC).
        $allowed = $this->config->get('shipping_courier_center_boxnow_shipping_methods');
        if (!is_array($allowed) || empty($allowed)) {
            $allowed = ['courier_center'];
        }

        $widget_data = [
            'partner_id'           => self::PARTNER_ID,
            'save_url'             => $this->url->link('extension/couriercenter/shipping/courier_center_boxnow.saveSession', 'language=' . $this->config->get('config_language'), true),
            'sel_selected'         => !empty($sel['selected']),
            'sel_mode'             => (string)($sel['delivery_mode'] ?? ''),
            'sel_locker_id'        => (string)($sel['locker_id'] ?? ''),
            'sel_locker_name'      => (string)($sel['locker_name'] ?? ''),
            'allowed_shipping_json'=> json_encode(array_values($allowed)),
        ];

        $html = $this->load->view('extension/couriercenter/shipping/boxnow_widget', $widget_data);

        // Inject just before </body> so the trailing script runs; the script
        // relocates the widget to sit under the shipping-method block.
        if (stripos((string)$output, '</body>') !== false) {
            $output = preg_replace('/<\/body>/i', $html . '</body>', (string)$output, 1);
        } else {
            $output .= $html;
        }
    }

    public function saveSession(): void {
        $this->response->addHeader('Content-Type: application/json');

        $selected    = !empty($this->request->post['selected']);
        $mode        = (string)($this->request->post['delivery_mode'] ?? '');
        $locker_id   = trim((string)($this->request->post['locker_id']   ?? ''));
        $locker_code = trim((string)($this->request->post['locker_code'] ?? ''));
        $locker_name = trim((string)($this->request->post['locker_name'] ?? ''));

        if (!$selected) {
            unset($this->session->data['cc_boxnow']);
        } else {
            $this->session->data['cc_boxnow'] = [
                'selected'      => true,
                'delivery_mode' => in_array($mode, ['auto', 'pick'], true) ? $mode : '',
                'locker_id'     => $locker_id,
                'locker_code'   => $locker_code !== '' ? $locker_code : $locker_id,
                'locker_name'   => $locker_name,
            ];
        }

        $this->response->setOutput(json_encode(['success' => true]));
    }

    public function saveToOrder(string &$route, array &$args, mixed &$output): void {
        $order_id = (int)$output;
        if ($order_id <= 0) {
            return;
        }

        $sel = $this->session->data['cc_boxnow'] ?? [];
        if (empty($sel['selected']) || empty($sel['delivery_mode'])) {
            return;
        }

        $q = $this->db->query("SELECT `custom_field` FROM `" . DB_PREFIX . "order` WHERE `order_id` = '" . (int)$order_id . "' LIMIT 1");
        if (!$q->num_rows) {
            return;
        }

        $custom = json_decode($q->row['custom_field'] ?? '{}', true);
        if (!is_array($custom)) {
            $custom = [];
        }
        $custom['cc_delivery_mode'] = (string)$sel['delivery_mode'];
        $custom['cc_locker_id']     = (string)($sel['locker_id'] ?? '');
        $custom['cc_locker_code']   = (string)($sel['locker_code'] ?? '');
        $custom['cc_locker_name']   = (string)($sel['locker_name'] ?? '');

        $this->db->query(
            "UPDATE `" . DB_PREFIX . "order` SET `custom_field` = '" . $this->db->escape(json_encode($custom)) . "'
             WHERE `order_id` = '" . (int)$order_id . "'"
        );

        // Clear so a subsequent order doesn't inherit a stale selection.
        unset($this->session->data['cc_boxnow']);
    }
}
