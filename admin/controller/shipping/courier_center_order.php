<?php
namespace Opencart\Admin\Controller\Extension\Couriercenter\Shipping;

class CourierCenterOrder extends \Opencart\System\Engine\Controller {

    private function api(): \Opencart\Extension\Couriercenter\Library\CCApi {
        require_once DIR_EXTENSION . 'couriercenter/library/CCApi.php';
        return new \Opencart\Extension\Couriercenter\Library\CCApi(
            (string)$this->config->get('shipping_courier_center_user_alias'),
            (string)$this->config->get('shipping_courier_center_credential_value'),
            (string)$this->config->get('shipping_courier_center_api_key'),
            (string)$this->config->get('shipping_courier_center_billing_account')
        );
    }

    private function buildSettings(): array {
        $postcode = (string)$this->config->get('shipping_courier_center_shipper_postcode');
        require_once DIR_EXTENSION . 'couriercenter/library/CCCityScope.php';
        $station = \Opencart\Extension\Couriercenter\Library\CCCityScope::get_station_for_postcode($postcode);
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

    public function createVoucher(): void {
        $this->response->addHeader('Content-Type: application/json');

        $order_id      = (int)($this->request->post['order_id']     ?? 0);
        $service       = $this->request->post['service_type']        ?? 'next_day';
        $parcel_count  = max(1, (int)($this->request->post['parcel_count'] ?? 1));
        $return_option = $this->request->post['return_option']       ?? 'none';
        $boxnow        = !empty($this->request->post['boxnow']);
        $locker_id     = trim($this->request->post['locker_id']      ?? '');
        $locker_code   = trim($this->request->post['locker_code']    ?? '');
        $locker_name   = trim($this->request->post['locker_name']    ?? '');
        $delivery_mode = trim($this->request->post['delivery_mode']  ?? '');

        $this->load->model('sale/order');
        $this->load->model('extension/couriercenter/shipping/courier_center');

        $order_data = $this->model_sale_order->getOrder($order_id);
        if (!$order_data) {
            $this->response->setOutput(json_encode(['success' => false, 'error' => 'Παραγγελία δε βρέθηκε']));
            return;
        }
        $products = $this->model_sale_order->getProducts($order_id);

        require_once DIR_EXTENSION . 'couriercenter/library/CCOrderAdapter.php';
        require_once DIR_EXTENSION . 'couriercenter/library/CCCityScope.php';
        require_once DIR_EXTENSION . 'couriercenter/library/CCShipmentBuilder.php';

        $adapter = new \Opencart\Extension\Couriercenter\Library\CCOrderAdapter($order_data, $products);
        $builder = new \Opencart\Extension\Couriercenter\Library\CCShipmentBuilder($adapter, $this->buildSettings(), $parcel_count);

        $settings_ok = $builder->validate_settings();
        if ($settings_ok !== true) {
            $this->response->setOutput(json_encode(['success' => false, 'error' => $settings_ok]));
            return;
        }
        $order_ok = $builder->validate_order();
        if ($order_ok !== true) {
            $this->response->setOutput(json_encode(['success' => false, 'error' => $order_ok]));
            return;
        }

        // Pass locker info to builder via settings override if BOX NOW
        $payload = $builder->build_payload($service, $boxnow, $return_option);

        // Override LockerDeliveryInfo with specific locker if provided
        if ($boxnow && !empty($locker_id)) {
            $payload['LockerDeliveryInfo'] = [
                'Prefix' => 'ATH',
                'Code'   => (string)$locker_id,
            ];
        }

        $result = $this->api()->create_shipment($payload);

        if (isset($result['success']) && $result['success'] === false) {
            $this->response->setOutput(json_encode(['success' => false, 'error' => $result['error']]));
            return;
        }

        $voucher      = $result['VoucherNumber']  ?? $result['AwbNumber']  ?? $result['ShipmentNumber']  ?? '';
        $tracking     = $result['TrackingNumber'] ?? $result['TrackingNo'] ?? $voucher;
        $return_awb   = $result['ReturnAWB']      ?? $result['ReturnVoucherNumber'] ?? '';

        if (empty($voucher)) {
            $this->response->setOutput(json_encode(['success' => false, 'error' => 'Το API δεν επέστρεψε αριθμό voucher. Raw: ' . json_encode($result)]));
            return;
        }

        $this->model_extension_couriercenter_shipping_courier_center->saveShipment($order_id, [
            'voucher_number'  => $voucher,
            'tracking_number' => $tracking,
            'service_type'    => $service,
            'return_option'   => $return_option,
            'return_awb'      => $return_awb,
            'is_boxnow'       => $boxnow ? 1 : 0,
            'locker_id'       => $locker_id,
            'locker_code'     => $locker_code,
            'locker_name'     => $locker_name,
        ]);

        $this->response->setOutput(json_encode([
            'success'  => true,
            'voucher'  => $voucher,
            'tracking' => $tracking,
        ]));
    }

    public function voidShipment(): void {
        $this->response->addHeader('Content-Type: application/json');

        $order_id = (int)($this->request->post['order_id'] ?? 0);
        $this->load->model('extension/couriercenter/shipping/courier_center');
        $shipment = $this->model_extension_couriercenter_shipping_courier_center->getShipment($order_id);

        if (empty($shipment['voucher_number'])) {
            $this->response->setOutput(json_encode(['success' => false, 'error' => 'Δεν υπάρχει αποστολή για ακύρωση']));
            return;
        }

        $result = $this->api()->void_shipment($shipment['voucher_number']);
        if ($result !== true) {
            $this->response->setOutput(json_encode(['success' => false, 'error' => $result['error'] ?? 'Αποτυχία ακύρωσης']));
            return;
        }

        $this->model_extension_couriercenter_shipping_courier_center->voidShipment($order_id);
        $this->response->setOutput(json_encode(['success' => true]));
    }

    public function removeBoxNow(): void {
        $this->response->addHeader('Content-Type: application/json');

        $order_id = (int)($this->request->post['order_id'] ?? 0);
        $this->load->model('extension/couriercenter/shipping/courier_center');

        $shipment = $this->model_extension_couriercenter_shipping_courier_center->getShipment($order_id);
        if (!empty($shipment['voucher_number']) && empty($shipment['is_voided'])) {
            $this->response->setOutput(json_encode(['success' => false, 'error' => 'Δεν μπορείς να αφαιρέσεις BOX NOW αφού έχει δημιουργηθεί voucher. Ακύρωσε πρώτα την αποστολή.']));
            return;
        }

        // Clear locker from order custom_field
        $q = $this->db->query(
            "SELECT `custom_field` FROM `" . DB_PREFIX . "order`
             WHERE `order_id` = '" . (int)$order_id . "' LIMIT 1"
        );
        if ($q->row) {
            $custom = json_decode($q->row['custom_field'] ?? '{}', true) ?: [];
            unset($custom['cc_locker_id'], $custom['cc_locker_name'], $custom['cc_locker_code'], $custom['cc_delivery_mode']);
            $this->db->query(
                "UPDATE `" . DB_PREFIX . "order`
                 SET `custom_field` = '" . $this->db->escape(json_encode($custom)) . "'
                 WHERE `order_id` = '" . (int)$order_id . "'"
            );
        }

        if (!empty($shipment)) {
            $this->model_extension_couriercenter_shipping_courier_center->removeBoxNow($order_id);
        }

        $this->response->setOutput(json_encode(['success' => true]));
    }

    public function updateStatus(): void {
        $this->response->addHeader('Content-Type: application/json');

        $order_id = (int)($this->request->post['order_id'] ?? 0);
        $this->load->model('extension/couriercenter/shipping/courier_center');
        $shipment = $this->model_extension_couriercenter_shipping_courier_center->getShipment($order_id);

        if (empty($shipment['voucher_number'])) {
            $this->response->setOutput(json_encode(['success' => false, 'error' => 'Δεν υπάρχει αποστολή']));
            return;
        }

        $result = $this->api()->get_shipment_details($shipment['voucher_number']);
        if (isset($result['success']) && $result['success'] === false) {
            $this->response->setOutput(json_encode(['success' => false, 'error' => $result['error']]));
            return;
        }

        $final_codes = ['29', '25', '99', '14', '87', '95'];
        $code  = (string)($result['StatusCode']        ?? $result['DeliveryStatus'] ?? '');
        $desc  = (string)($result['StatusDescription'] ?? $result['DeliveryStatusDescription'] ?? '');
        $final = in_array($code, $final_codes);

        $this->model_extension_couriercenter_shipping_courier_center->updateStatus($order_id, $code, $desc, $final);
        $this->response->setOutput(json_encode(['success' => true, 'code' => $code, 'desc' => $desc]));
    }

    public function downloadVoucher(): void {
        $order_id = (int)($this->request->get['order_id'] ?? 0);
        $this->load->model('extension/couriercenter/shipping/courier_center');
        $shipment = $this->model_extension_couriercenter_shipping_courier_center->getShipment($order_id);

        if (empty($shipment['voucher_number'])) {
            http_response_code(404);
            exit('Δεν υπάρχει αποστολή για αυτή την παραγγελία.');
        }

        $template = (string)$this->config->get('shipping_courier_center_print_template') ?: 'pdf';
        $pdf = $this->api()->get_voucher_pdf($shipment['voucher_number'], $template);

        if (is_array($pdf)) {
            http_response_code(500);
            exit('Σφάλμα voucher: ' . ($pdf['error'] ?? 'Άγνωστο σφάλμα'));
        }

        try {
            require_once DIR_EXTENSION . 'couriercenter/library/CCPdfScaler.php';
            $pdf = \Opencart\Extension\Couriercenter\Library\CCPdfScaler::scale_pdf($pdf);
        } catch (\Exception $e) {
            // Serve original PDF if scaling fails
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="voucher_' . $shipment['voucher_number'] . '.pdf"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
        exit;
    }
}
