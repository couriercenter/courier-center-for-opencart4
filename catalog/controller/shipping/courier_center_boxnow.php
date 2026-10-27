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
            'default_selected'     => ((string)$this->config->get('shipping_courier_center_boxnow_default_selected') === '1'),
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
        // The BOX NOW map SDK returns the address HTML-encoded (e.g. "&amp;").
        // Decode it once so the stored/displayed locker name reads cleanly.
        $locker_name = trim(html_entity_decode((string)($this->request->post['locker_name'] ?? ''), ENT_QUOTES, 'UTF-8'));

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

        $fields = $this->selectionFields($args[0] ?? []);
        if (!$fields) {
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

        $this->db->query(
            "UPDATE `" . DB_PREFIX . "order` SET `custom_field` = '" . $this->db->escape(json_encode(array_merge($custom, $fields))) . "'
             WHERE `order_id` = '" . (int)$order_id . "'"
        );
        // NOT cleared here: OpenCart 4 rewrites the same order (editOrder) whenever the customer changes
        // something before "Confirm" and keepOnEdit() needs the choice. clearSession() runs on checkout/success.
    }

    /**
     * Event catalog/model/checkout/order/editOrder/before: $args[0] = order_id, $args[1] = order data.
     * editOrder resets custom_field from the customer data. Checkout: write the current choice (it may have
     * been ticked or unticked meanwhile). Admin edit (api/...): keep the stored choice.
     */
    public function keepOnEdit(string &$route, array &$args): void {
        $order_id = (int)($args[0] ?? 0);
        if ($order_id <= 0 || !isset($args[1]) || !is_array($args[1])) {
            return;
        }
        $custom = $args[1]['custom_field'] ?? [];
        if (!is_array($custom)) {
            $custom = json_decode((string)$custom, true) ?: [];
        }
        $keys = ['cc_delivery_mode', 'cc_locker_id', 'cc_locker_code', 'cc_locker_name'];

        if (str_starts_with((string)($this->request->get['route'] ?? ''), 'api/')) {
            if (isset($custom['cc_delivery_mode'])) {
                return;
            }
            $q = $this->db->query("SELECT `custom_field` FROM `" . DB_PREFIX . "order` WHERE `order_id` = '" . $order_id . "' LIMIT 1");
            $old = $q->num_rows ? json_decode((string)$q->row['custom_field'], true) : null;
            if (!is_array($old) || !isset($old['cc_delivery_mode'])) {
                return;
            }
            foreach ($keys as $k) {
                $custom[$k] = (string)($old[$k] ?? '');
            }
        } else {
            foreach ($keys as $k) {
                unset($custom[$k]);
            }
            $custom = array_merge($custom, $this->selectionFields($args[1]));
        }
        $args[1]['custom_field'] = $custom;
    }

    /** Event catalog/controller/checkout/success/before: a new order starts without a stale choice. */
    public function clearSession(string &$route, array &$args): void {
        unset($this->session->data['cc_boxnow']);
    }

    /** The session's BOX NOW choice as custom_field keys, or [] (none, or a shipping method without BOX NOW). */
    private function selectionFields(array $order_data): array {
        $sel = $this->session->data['cc_boxnow'] ?? [];
        if (empty($sel['selected'])) {
            return [];
        }
        // Only for an order with a shipping method that offers BOX NOW (the choice may be left from another method).
        $allowed = $this->config->get('shipping_courier_center_boxnow_shipping_methods');
        $allowed = (is_array($allowed) && $allowed) ? array_map('strval', $allowed) : ['courier_center'];
        $sm = $order_data['shipping_method'] ?? [];
        $code = is_array($sm) ? (string)($sm['code'] ?? '') : (string)($order_data['shipping_code'] ?? '');
        if (!in_array(explode('.', $code)[0], $allowed, true)) {
            return [];
        }
        // Server-side check: "pick" without a locker becomes "auto" (nearest locker), like OpenCart 3.
        $locker_id = trim((string)($sel['locker_id'] ?? ''));
        $pick = (string)$sel['delivery_mode'] === 'pick' && $locker_id !== '';
        return [
            'cc_delivery_mode' => $pick ? 'pick' : 'auto',
            'cc_locker_id'     => $pick ? $locker_id : '',
            'cc_locker_code'   => $pick ? (string)($sel['locker_code'] ?? '') : '',
            'cc_locker_name'   => $pick ? (string)($sel['locker_name'] ?? '') : '',
        ];
    }
}
