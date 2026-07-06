<?php
namespace Opencart\Admin\Controller\Extension\Couriercenter\Shipping;

class CourierCenter extends \Opencart\System\Engine\Controller {

    private array $error = [];
    private string $prefix = 'shipping_courier_center_';

    public function index(): void {
        $this->load->language('extension/couriercenter/shipping/courier_center');
        $this->document->setTitle($this->language->get('heading_title'));
        $this->load->model('setting/setting');

        // Usage ping to the Courier Center dashboard (throttled to once / 12h).
        $this->ccSendPing();

        if ($this->request->server['REQUEST_METHOD'] === 'POST' && $this->validate()) {
            $this->model_setting_setting->editSetting('shipping_courier_center', $this->request->post);
            $this->session->data['success'] = $this->language->get('text_success');
            $this->response->redirect(
                $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=shipping')
            );
        }

        if (isset($this->session->data['success'])) {
            $data['success'] = $this->session->data['success'];
            unset($this->session->data['success']);
        } else {
            $data['success'] = '';
        }

        $data['breadcrumbs'] = [
            ['text' => $this->language->get('text_home'),      'href' => $this->url->link('common/dashboard',     'user_token=' . $this->session->data['user_token'])],
            ['text' => $this->language->get('text_extension'), 'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=shipping')],
            ['text' => $this->language->get('heading_title'),  'href' => $this->url->link('extension/couriercenter/shipping/courier_center', 'user_token=' . $this->session->data['user_token'])],
        ];

        $fields = [
            'status', 'user_alias', 'credential_value', 'api_key', 'billing_account',
            'shipper_name', 'shipper_address', 'shipper_postcode', 'shipper_city', 'shipper_phone',
            'tracking_url', 'email_tracking_enabled',
            'boxnow_enabled', 'boxnow_default_selected', 'print_template', 'print_template_boxnow', 'auto_complete_status_id',
            'auto_create_enabled', 'auto_create_status_id',
            'cost', 'cod_fee', 'geo_zone_id', 'tax_class_id', 'sort_order',
        ];

        foreach ($fields as $field) {
            $key = $this->prefix . $field;
            $data[$field] = $this->request->post[$key] ?? $this->config->get($key) ?? '';
        }

        // Defaults
        if ($data['tracking_url'] === '') {
            $data['tracking_url'] = 'https://www.courier.gr/track/result?tracknr={{tracking}}';
        }
        if ($data['email_tracking_enabled']  === '') $data['email_tracking_enabled']  = '1';
        if ($data['print_template']           === '') $data['print_template']          = 'pdf';
        if ($data['print_template_boxnow']    === '') $data['print_template_boxnow']   = 'singlepdf_100x150_4up';
        if ($data['auto_complete_status_id']  === '') $data['auto_complete_status_id'] = '0';
        if ($data['auto_create_enabled']      === '') $data['auto_create_enabled']     = '0';
        if ($data['auto_create_status_id']    === '') $data['auto_create_status_id']   = '0';
        if ($data['cost']                     === '') $data['cost']                    = '0';
        if ($data['cod_fee']                  === '') $data['cod_fee']                 = '0';
        if ($data['sort_order']               === '') $data['sort_order']              = '0';

        // Order statuses for the auto-complete dropdown
        $this->load->model('localisation/order_status');
        $data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();

        // Enabled shipping methods → which ones show the BOX NOW widget at checkout.
        $this->load->model('setting/extension');
        $data['shipping_methods_list'] = [];
        foreach ($this->model_setting_extension->getExtensionsByType('shipping') as $ext) {
            if (!$this->config->get('shipping_' . $ext['code'] . '_status')) continue;
            $data['shipping_methods_list'][] = [
                'code' => $ext['code'],
                'name' => ucwords(str_replace('_', ' ', $ext['code'])),
            ];
        }
        $data['boxnow_shipping_methods'] = $this->config->get($this->prefix . 'boxnow_shipping_methods');
        if (!is_array($data['boxnow_shipping_methods']) || empty($data['boxnow_shipping_methods'])) {
            $data['boxnow_shipping_methods'] = ['courier_center'];
        }

        // Which shipping methods the plugin manages (empty = all — see ccIsHandledOrder).
        $data['handled_shipping_methods'] = $this->config->get($this->prefix . 'handled_shipping_methods');
        if (!is_array($data['handled_shipping_methods'])) {
            $data['handled_shipping_methods'] = [];
        }

        // Shipping-method dropdown data
        $this->load->model('localisation/geo_zone');
        $data['geo_zones'] = $this->model_localisation_geo_zone->getGeoZones();

        $this->load->model('localisation/tax_class');
        $data['tax_classes'] = $this->model_localisation_tax_class->getTaxClasses();

        // Cron info
        $data['cron_last_run']  = $this->config->get($this->prefix . 'cron_last_run')  ?: '—';
        $data['cron_next_run']  = $this->config->get($this->prefix . 'cron_next_run')  ?: '—';

        $data['user_token']  = $this->session->data['user_token'];
        $data['action']      = $this->url->link('extension/couriercenter/shipping/courier_center',            'user_token=' . $this->session->data['user_token']);
        $data['cancel']      = $this->url->link('marketplace/extension',                                      'user_token=' . $this->session->data['user_token'] . '&type=shipping');
        $data['url_autofill']= $this->url->link('extension/couriercenter/shipping/courier_center.autofill',  'user_token=' . $this->session->data['user_token'], true);
        $data['url_clear']   = $this->url->link('extension/couriercenter/shipping/courier_center.clearSettings', 'user_token=' . $this->session->data['user_token'], true);
        $data['url_manifest']= $this->url->link('extension/couriercenter/shipping/courier_center_manifest',  'user_token=' . $this->session->data['user_token']);
        $data['url_bug_report'] = $this->url->link('extension/couriercenter/shipping/courier_center_bug',     'user_token=' . $this->session->data['user_token']);
        $data['url_docs']       = $this->url->link('extension/couriercenter/shipping/courier_center_docs',    'user_token=' . $this->session->data['user_token']);
        $data['url_update_check'] = $this->url->link('extension/couriercenter/shipping/courier_center_update.check', 'user_token=' . $this->session->data['user_token'], true);
        $data['url_update_apply'] = $this->url->link('extension/couriercenter/shipping/courier_center_update.apply', 'user_token=' . $this->session->data['user_token'], true);

        $data['header']      = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer']      = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('extension/couriercenter/shipping/courier_center', $data));
    }

    public function install(): void {
        $this->load->model('extension/couriercenter/shipping/courier_center');
        $this->model_extension_couriercenter_shipping_courier_center->install();

        // Grant permissions for all sub-controllers
        $this->load->model('user/user_group');
        $routes = [
            'extension/couriercenter/shipping/courier_center',
            'extension/couriercenter/shipping/courier_center_order',
            'extension/couriercenter/shipping/courier_center_manifest',
            'extension/couriercenter/shipping/courier_center_bug',
            'extension/couriercenter/shipping/courier_center_update',
            'extension/couriercenter/shipping/courier_center_docs',
        ];
        foreach ($routes as $route) {
            $this->model_user_user_group->addPermission($this->user->getGroupId(), 'access', $route);
            $this->model_user_user_group->addPermission($this->user->getGroupId(), 'modify', $route);
        }

        $this->load->model('setting/event');
        $this->model_setting_event->addEvent([
            'code'        => 'courier_center_order_panel',
            'description' => 'Courier Center — panel στη σελίδα παραγγελίας',
            'trigger'     => 'admin/view/sale/order_info/after',
            'action'      => 'extension/couriercenter/shipping/courier_center_order.orderPanel',
            'status'      => 1,
            'sort_order'  => 0,
        ]);
        $this->model_setting_event->addEvent([
            'code'        => 'courier_center_order_list',
            'description' => 'Courier Center — στήλες στη λίστα παραγγελιών',
            'trigger'     => 'admin/view/sale/order_list/after',
            'action'      => 'extension/couriercenter/shipping/courier_center_order_list.injectColumns',
            'status'      => 1,
            'sort_order'  => 0,
        ]);
        $this->model_setting_event->addEvent([
            'code'        => 'courier_center_email',
            'description' => 'Courier Center — tracking info σε order emails',
            'trigger'     => 'catalog/model/checkout/order/addHistory/after',
            'action'      => 'extension/couriercenter/shipping/courier_center_email.sendTracking',
            'status'      => 1,
            'sort_order'  => 99,
        ]);
        $this->model_setting_event->addEvent([
            'code'        => 'courier_center_boxnow_widget',
            'description' => 'Courier Center — BOX NOW widget στο checkout',
            'trigger'     => 'catalog/view/checkout/checkout/after',
            'action'      => 'extension/couriercenter/shipping/courier_center_boxnow.widget',
            'status'      => 1,
            'sort_order'  => 0,
        ]);
        $this->model_setting_event->addEvent([
            'code'        => 'courier_center_boxnow_order',
            'description' => 'Courier Center — αποθήκευση BOX NOW locker στην παραγγελία',
            'trigger'     => 'catalog/model/checkout/order/addOrder/after',
            'action'      => 'extension/couriercenter/shipping/courier_center_boxnow.saveToOrder',
            'status'      => 1,
            'sort_order'  => 0,
        ]);
        $this->model_setting_event->addEvent([
            'code'        => 'courier_center_auto_create',
            'description' => 'Courier Center — αυτόματη δημιουργία voucher σε αλλαγή status',
            'trigger'     => 'catalog/model/checkout/order/addHistory/after',
            'action'      => 'extension/couriercenter/shipping/courier_center_autocreate.autoCreate',
            'status'      => 1,
            'sort_order'  => 50,
        ]);

        $this->model_setting_event->addEvent([
            'code'        => 'courier_center_update_notice',
            'description' => 'Courier Center — ειδοποίηση διαθέσιμης ενημέρωσης στο admin',
            'trigger'     => 'admin/view/common/header/after',
            'action'      => 'extension/couriercenter/shipping/courier_center_update.notice',
            'status'      => 1,
            'sort_order'  => 0,
        ]);

        // Extensions > Shipping should list only the main method. The helper
        // controllers (bug/docs/manifest/order/order_list/update) live under
        // shipping/ for routing, not as shipping methods — drop their registered
        // paths so the ocmod installer doesn't list them as separate methods.
        $this->db->query(
            "DELETE FROM `" . DB_PREFIX . "extension_path`
             WHERE `path` LIKE 'couriercenter/admin/controller/shipping/%.php'
               AND `path` <> 'couriercenter/admin/controller/shipping/courier_center.php'"
        );

        // Announce the install to the Courier Center dashboard.
        $this->ccSendPing(true);
    }

    public function uninstall(): void {
        $this->load->model('extension/couriercenter/shipping/courier_center');
        $this->model_extension_couriercenter_shipping_courier_center->uninstall();

        $this->load->model('setting/event');
        $this->model_setting_event->deleteEventByCode('courier_center_order_panel');
        $this->model_setting_event->deleteEventByCode('courier_center_order_list');
        $this->model_setting_event->deleteEventByCode('courier_center_email');
        $this->model_setting_event->deleteEventByCode('courier_center_boxnow_widget');
        $this->model_setting_event->deleteEventByCode('courier_center_boxnow_order');
        $this->model_setting_event->deleteEventByCode('courier_center_auto_create');
        $this->model_setting_event->deleteEventByCode('courier_center_update_notice');
    }

    public function autofill(): void {
        $this->response->addHeader('Content-Type: application/json');

        if (!$this->user->hasPermission('modify', 'extension/couriercenter/shipping/courier_center')) {
            $this->response->setOutput(json_encode(['success' => false, 'error' => 'No permission']));
            return;
        }

        $user_alias       = trim($this->request->post['user_alias']       ?? '');
        $credential_value = trim($this->request->post['credential_value'] ?? '');
        $api_key          = trim($this->request->post['api_key']          ?? '');
        $billing_account  = trim($this->request->post['billing_account']  ?? '');

        if (!$user_alias)       $user_alias       = (string)$this->config->get($this->prefix . 'user_alias');
        if (!$credential_value) $credential_value = (string)$this->config->get($this->prefix . 'credential_value');
        if (!$api_key)          $api_key          = (string)$this->config->get($this->prefix . 'api_key');
        if (!$billing_account)  $billing_account  = (string)$this->config->get($this->prefix . 'billing_account');

        if (!$user_alias || !$credential_value || !$api_key || !$billing_account) {
            $this->response->setOutput(json_encode(['success' => false, 'error' => 'Συμπλήρωσε πρώτα τα API credentials']));
            return;
        }

        require_once DIR_EXTENSION . 'couriercenter/library/CCApi.php';
        $api = new \Opencart\Extension\Couriercenter\Library\CCApi(
            $user_alias, $credential_value, $api_key, $billing_account
        );

        $payload = [
            'Context'      => ['UserAlias' => $user_alias, 'CredentialValue' => $credential_value, 'ApiKey' => $api_key],
            'shipmentDate' => date('Y-m-d'),
            'comments'     => 'TEST AUTOFILL - DELETE',
            'Requestor'    => ['CarrierBillingAccount' => $billing_account],
            'Shipper'      => [
                'CarrierBillingAccount' => $billing_account,
                'CompanyName'           => 'TEST',
                'Address'               => 'TEST',
                'ZipCode'               => '10431',
                'City'                  => 'ΑΘΗΝΑ',
                'Phones'                => '2101234567',
                'Country'               => 'GREECE',
                'CountryCode'           => 'GR',
            ],
            'Consignee'    => [
                'CompanyName' => 'TEST',
                'ContactName' => 'TEST',
                'Address'     => 'ΑΘΗΝΑ',
                'City'        => 'ΑΘΗΝΑ',
                'Area'        => 'ΑΘΗΝΑ',
                'ZipCode'     => '10431',
                'Country'     => 'GR',
                'Mobile1'     => '2101234567',
            ],
            'BillTo'       => 'Requestor',
            'BasicService' => '211',
            'Reference1'   => 'TEST-AUTOFILL',
            'Items'        => [[
                'GoodsType'        => 'NoDocs',
                'Content'          => 'TEST',
                'IsDangerousGoods' => false,
                'IsDryIce'         => false,
                'IsFragile'        => false,
                'Weight'           => ['Unit' => 'kg', 'Value' => 1.0],
            ]],
        ];

        $create = $api->create_shipment($payload);
        if (isset($create['success']) && $create['success'] === false) {
            $this->response->setOutput(json_encode(['success' => false, 'error' => 'Βήμα 1 — ' . ($create['error'] ?? 'Αποτυχία δημιουργίας test αποστολής')]));
            return;
        }

        $awb = $create['ShipmentNumber'] ?? $create['VoucherNumber'] ?? $create['AwbNumber'] ?? '';
        if (!$awb) {
            $this->response->setOutput(json_encode(['success' => false, 'error' => 'Το API δεν επέστρεψε ShipmentNumber. Raw: ' . json_encode($create)]));
            return;
        }

        $details = $api->get_shipment_details($awb);
        $info    = $details['ShipmentDetails'][0]['ShipmentInfo'] ?? [];
        $shipper = $info['Shipper'] ?? [];

        $name    = $shipper['CompanyName'] ?? '';
        $address = $shipper['Address']     ?? '';
        $postal  = $shipper['ZipCode']     ?? '';
        $city    = $shipper['City']        ?? '';
        $phone   = $shipper['Phones']      ?? '';

        $api->void_shipment($awb);

        $this->load->model('setting/setting');
        $this->model_setting_setting->editSetting('shipping_courier_center', [
            $this->prefix . 'user_alias'       => $user_alias,
            $this->prefix . 'credential_value' => $credential_value,
            $this->prefix . 'api_key'          => $api_key,
            $this->prefix . 'billing_account'  => $billing_account,
            $this->prefix . 'shipper_name'     => $name,
            $this->prefix . 'shipper_address'  => $address,
            $this->prefix . 'shipper_postcode' => $postal,
            $this->prefix . 'shipper_city'     => $city,
            $this->prefix . 'shipper_phone'    => $phone,
            $this->prefix . 'status'           => $this->config->get($this->prefix . 'status') ?: '0',
        ]);

        $this->response->setOutput(json_encode([
            'success'         => true,
            'shipper_name'    => $name,
            'shipper_address' => $address,
            'shipper_postcode'=> $postal,
            'shipper_city'    => $city,
            'shipper_phone'   => $phone,
        ]));
    }

    public function clearSettings(): void {
        $this->response->addHeader('Content-Type: application/json');

        if (!$this->user->hasPermission('modify', 'extension/couriercenter/shipping/courier_center')) {
            $this->response->setOutput(json_encode(['success' => false, 'error' => 'No permission']));
            return;
        }

        $this->load->model('setting/setting');
        $this->model_setting_setting->deleteSetting('shipping_courier_center');

        $this->response->setOutput(json_encode(['success' => true]));
    }

    // ── Order AJAX endpoints ──────────────────────────────────────────

    public function createVoucher(): void {
        // Defensive guard: ensure the client always receives JSON, never a raw
        // PHP fatal/HTML page (which would break JSON.parse on the front-end).
        register_shutdown_function(function() {
            $err = error_get_last();
            if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
                if (!headers_sent()) {
                    header('Content-Type: application/json');
                }
                echo json_encode(['success' => false, 'error' => 'PHP Fatal: ' . $err['message'] . ' @ ' . basename($err['file']) . ':' . $err['line']]);
            }
        });

        $this->response->addHeader('Content-Type: application/json');

        try {
            $order_id = (int)($this->request->post['order_id'] ?? 0);

            // Shipping method not managed by the plugin → ask for confirmation
            // (override) instead of silently creating a shipment for another courier.
            if (empty($this->request->post['override']) && !$this->ccIsHandledOrder($order_id)) {
                $ship = $this->ccOrderShipping($order_id);
                $this->response->setOutput(json_encode([
                    'success'        => false,
                    'needs_override' => true,
                    'error'          => 'Η παραγγελία χρησιμοποιεί μέθοδο αποστολής «' . ($ship['name'] ?: '—') . '» που δεν διαχειρίζεται το plugin. Δημιουργία αποστολής στην Courier Center ούτως ή άλλως;',
                ]));
                return;
            }

            $result = $this->ccCreateVoucherForOrder($order_id, [
                'service_type'  => $this->request->post['service_type']  ?? 'next_day',
                'parcel_count'  => $this->request->post['parcel_count']  ?? 1,
                'return_option' => $this->request->post['return_option'] ?? 'none',
                'boxnow'        => !empty($this->request->post['boxnow']),
                'locker_id'     => $this->request->post['locker_id']     ?? '',
                'locker_code'   => $this->request->post['locker_code']   ?? '',
                'locker_name'   => $this->request->post['locker_name']   ?? '',
            ]);
            $this->response->setOutput(json_encode($result));
        } catch (\Throwable $e) {
            $this->response->setOutput(json_encode(['success' => false, 'error' => 'Σφάλμα: ' . $e->getMessage()]));
        }
    }

    /**
     * Create a voucher for a single order. Shared by createVoucher() (single,
     * from the order panel) and bulkCreate() (mass, from the orders list).
     * Returns ['success'=>bool, 'voucher'=>..., 'tracking'=>..., 'error'=>...].
     */
    private function ccCreateVoucherForOrder(int $order_id, array $opts): array {
        $service       = $opts['service_type']  ?? 'next_day';
        $parcel_count  = max(1, (int)($opts['parcel_count'] ?? 1));
        $return_option = $opts['return_option'] ?? 'none';
        $boxnow        = !empty($opts['boxnow']);
        $locker_id     = trim((string)($opts['locker_id']   ?? ''));
        $locker_code   = trim((string)($opts['locker_code'] ?? ''));
        $locker_name   = trim((string)($opts['locker_name'] ?? ''));

        $this->load->model('sale/order');
        $this->load->model('extension/couriercenter/shipping/courier_center');

        $order_data = $this->model_sale_order->getOrder($order_id);
        if (!$order_data) {
            return ['success' => false, 'error' => 'Παραγγελία δε βρέθηκε'];
        }
        $products = $this->ccEnrichProducts($this->model_sale_order->getProducts($order_id));

        require_once DIR_EXTENSION . 'couriercenter/library/CCOrderAdapter.php';
        require_once DIR_EXTENSION . 'couriercenter/library/CCCityScope.php';
        require_once DIR_EXTENSION . 'couriercenter/library/CCShipmentBuilder.php';

        $adapter = new \Opencart\Extension\Couriercenter\Library\CCOrderAdapter($order_data, $products);
        $builder = new \Opencart\Extension\Couriercenter\Library\CCShipmentBuilder($adapter, $this->ccBuildSettings(), $parcel_count);

        // BOX NOW lockers cannot accept cash-on-delivery.
        if ($boxnow && $adapter->is_cod()) {
            return ['success' => false, 'error' => '❌ Το BOX NOW δεν υποστηρίζει αντικαταβολή. Άλλαξε τρόπο πληρωμής ή απενεργοποίησε το BOX NOW Locker.'];
        }

        $settings_ok = $builder->validate_settings();
        if ($settings_ok !== true) {
            return ['success' => false, 'error' => $settings_ok];
        }
        $order_ok = $builder->validate_order();
        if ($order_ok !== true) {
            return ['success' => false, 'error' => $order_ok];
        }

        $payload = $builder->build_payload($service, $boxnow, $return_option);

        if ($boxnow && !empty($locker_id)) {
            $payload['LockerDeliveryInfo'] = ['Prefix' => 'ATH', 'Code' => (string)$locker_id];
        }

        $result = $this->ccApi()->create_shipment($payload);

        if (isset($result['success']) && $result['success'] === false) {
            return ['success' => false, 'error' => $result['error']];
        }

        $voucher  = $result['VoucherNumber']  ?? $result['AwbNumber']  ?? $result['ShipmentNumber'] ?? '';
        $tracking = $result['TrackingNumber'] ?? $result['TrackingNo'] ?? ($result['TrackingNumbers'][0] ?? '');
        if ($tracking === '') $tracking = $voucher;

        // Return AWB — the API may report it in several different fields
        // (mirrors the WooCommerce plugin's broader lookup).
        $return_awb = '';
        if ($return_option !== 'none') {
            $return_awb = (string)(
                ($result['ReturnShipmentNumber'] ?? null)
                ?? ($result['ReturnAWB'] ?? null)
                ?? ($result['ReturnVoucherNumber'] ?? null)
                ?? ($result['ReturnTrackingNumbers'][0] ?? null)
                ?? ($result['TrackingNumbers'][1] ?? '')
            );
        }

        if (empty($voucher)) {
            return ['success' => false, 'error' => 'Το API δεν επέστρεψε αριθμό voucher'];
        }

        // BOX NOW: detect "no locker found" fallback (door-to-door) or the locker
        // the carrier actually assigned, so the merchant sees it in the note.
        $note_extra = '';
        if ($boxnow) {
            $contractor_note = (string)($result['ContractorResultNote'] ?? '');
            if (stripos($contractor_note, 'No locker found') !== false) {
                $note_extra = ' | ⚠️ Δεν βρέθηκε locker — αποστέλλεται door-to-door';
            } else {
                $assigned = (string)(
                    $result['AssignedLockerCode']
                    ?? $result['LockerCode']
                    ?? $result['DestinationLockerCode']
                    ?? $result['LockerDeliveryInfo']['Code']
                    ?? ''
                );
                if ($assigned === '' && $contractor_note !== '' && preg_match('/locker[:\s#]*(\w+)/i', $contractor_note, $m)) {
                    $assigned = $m[1];
                }
                if ($assigned !== '') {
                    $note_extra = ' | 📦 Locker: ' . $assigned;
                    if ($locker_code === '') $locker_code = $assigned;
                }
            }
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

        // Order History note (like a WooCommerce order note).
        $note = sprintf(
            '✅ Courier Center voucher: %s | Υπηρεσία: %s%s%s',
            $voucher,
            $this->ccServiceLabel($service),
            $boxnow ? ' + BOX NOW' : '',
            $return_option !== 'none' ? ' + Επιστροφικό' : ''
        );
        if ($return_awb !== '') $note .= ' | Return AWB: ' . $return_awb;
        $note .= $note_extra;
        $this->ccAddOrderHistory($order_id, $note);

        return ['success' => true, 'voucher' => $voucher, 'tracking' => $tracking];
    }

    /**
     * Bulk: create "next day" vouchers for the selected orders.
     * Skips orders that already have an active voucher. POST order_ids=1,2,3.
     */
    public function bulkCreate(): void {
        @set_time_limit(0);
        $this->response->addHeader('Content-Type: application/json');

        if (!$this->user->hasPermission('modify', 'extension/couriercenter/shipping/courier_center')) {
            $this->response->setOutput(json_encode(['success' => false, 'error' => 'No permission']));
            return;
        }

        $order_ids = $this->ccParseOrderIds($this->request->post['order_ids'] ?? '');
        if (empty($order_ids)) {
            $this->response->setOutput(json_encode(['success' => false, 'error' => 'Δεν επιλέχθηκαν παραγγελίες']));
            return;
        }

        $r   = $this->ccRunBulkCreate($order_ids, false);
        $msg = "Σύνολο: " . count($order_ids) . " | ✅ Δημιουργήθηκαν: {$r['success']}";
        if ($r['skipped'])      $msg .= " | ⏭️ Έχουν ήδη voucher: {$r['skipped']}";
        if ($r['skipped_type']) $msg .= " | 📦 BOX NOW (χρησ. «Δημιουργία BOX NOW»): {$r['skipped_type']}";
        if (!empty($r['skipped_unmanaged'])) $msg .= " | 🚫 Μη διαχειρίσιμη μέθοδος: {$r['skipped_unmanaged']}";
        if ($r['failed'])       $msg .= " | ❌ Απέτυχαν: {$r['failed']}";
        if ($r['errors'])       $msg .= "\n\n" . implode("\n", array_slice($r['errors'], 0, 15));

        $this->response->setOutput(json_encode(['success' => true, 'message' => $msg]));
    }

    /**
     * Bulk: create vouchers ONLY for the selected orders that chose BOX NOW at
     * checkout (uses the locker the customer picked). Non-BOX NOW orders are left
     * for the regular "Δημιουργία Vouchers" button — mirrors the bulk-print split.
     */
    public function bulkCreateBoxnow(): void {
        @set_time_limit(0);
        $this->response->addHeader('Content-Type: application/json');

        if (!$this->user->hasPermission('modify', 'extension/couriercenter/shipping/courier_center')) {
            $this->response->setOutput(json_encode(['success' => false, 'error' => 'No permission']));
            return;
        }

        $order_ids = $this->ccParseOrderIds($this->request->post['order_ids'] ?? '');
        if (empty($order_ids)) {
            $this->response->setOutput(json_encode(['success' => false, 'error' => 'Δεν επιλέχθηκαν παραγγελίες']));
            return;
        }

        $r   = $this->ccRunBulkCreate($order_ids, true);
        $msg = "📦 BOX NOW — Σύνολο: " . count($order_ids) . " | ✅ Δημιουργήθηκαν: {$r['success']}";
        if ($r['skipped'])      $msg .= " | ⏭️ Έχουν ήδη voucher: {$r['skipped']}";
        if ($r['skipped_type']) $msg .= " | ➡️ Όχι BOX NOW (χρησ. «Δημιουργία Vouchers»): {$r['skipped_type']}";
        if (!empty($r['skipped_unmanaged'])) $msg .= " | 🚫 Μη διαχειρίσιμη μέθοδος: {$r['skipped_unmanaged']}";
        if ($r['failed'])       $msg .= " | ❌ Απέτυχαν: {$r['failed']}";
        if ($r['errors'])       $msg .= "\n\n" . implode("\n", array_slice($r['errors'], 0, 15));

        $this->response->setOutput(json_encode(['success' => true, 'message' => $msg]));
    }

    /**
     * Shared bulk-create loop, split by type like the bulk printing:
     *   $boxnow_only=false → process only NON-BOX NOW orders (normal vouchers)
     *   $boxnow_only=true  → process only orders that chose BOX NOW at checkout
     * Orders of the other type are counted in 'skipped_type' and left untouched.
     * Returns ['success','failed','skipped','skipped_type','errors'].
     */
    private function ccRunBulkCreate(array $order_ids, bool $boxnow_only): array {
        $this->load->model('extension/couriercenter/shipping/courier_center');

        $success = 0; $failed = 0; $skipped = 0; $skipped_type = 0; $skipped_unmanaged = 0; $errors = [];

        foreach ($order_ids as $order_id) {
            $existing = $this->model_extension_couriercenter_shipping_courier_center->getShipment($order_id);
            if (!empty($existing['voucher_number']) && empty($existing['is_voided'])) {
                $skipped++;
                continue;
            }

            // Only orders whose shipping method the plugin manages.
            if (!$this->ccIsHandledOrder($order_id)) {
                $skipped_unmanaged++;
                continue;
            }

            // BOX NOW order = the customer picked BOX NOW at checkout.
            $bn              = $this->ccBoxnowFromOrder($order_id);
            $is_boxnow_order = !empty($bn);

            // Each button handles only its own type (mirrors the bulk printing split):
            // "Δημιουργία Vouchers" → normal orders only · "Δημιουργία BOX NOW" → BOX NOW only.
            if ($boxnow_only !== $is_boxnow_order) {
                $skipped_type++;
                continue;
            }

            try {
                $opts = array_merge(['service_type' => 'next_day'], $bn);
                $res  = $this->ccCreateVoucherForOrder($order_id, $opts);
            } catch (\Throwable $e) {
                $res = ['success' => false, 'error' => $e->getMessage()];
            }
            if (!empty($res['success'])) {
                $success++;
            } else {
                $failed++;
                $errors[] = "#$order_id: " . ($res['error'] ?? 'Άγνωστο σφάλμα');
            }
            usleep(250000);
        }

        return ['success' => $success, 'failed' => $failed, 'skipped' => $skipped, 'skipped_type' => $skipped_type, 'skipped_unmanaged' => $skipped_unmanaged, 'errors' => $errors];
    }

    /**
     * Bulk: refresh tracking status for the selected orders. POST order_ids=1,2,3.
     */
    public function bulkStatus(): void {
        @set_time_limit(0);
        $this->response->addHeader('Content-Type: application/json');

        if (!$this->user->hasPermission('modify', 'extension/couriercenter/shipping/courier_center')) {
            $this->response->setOutput(json_encode(['success' => false, 'error' => 'No permission']));
            return;
        }

        $order_ids = $this->ccParseOrderIds($this->request->post['order_ids'] ?? '');
        if (empty($order_ids)) {
            $this->response->setOutput(json_encode(['success' => false, 'error' => 'Δεν επιλέχθηκαν παραγγελίες']));
            return;
        }

        $this->load->model('extension/couriercenter/shipping/courier_center');

        $final_codes = ['29', '25', '99', '14', '87', '95'];
        $updated = 0; $skipped = 0; $failed = 0; $errors = [];

        foreach ($order_ids as $order_id) {
            $shipment = $this->model_extension_couriercenter_shipping_courier_center->getShipment($order_id);
            if (empty($shipment['voucher_number']) || !empty($shipment['is_voided'])) {
                $skipped++;
                continue;
            }
            $result = $this->ccApi()->get_shipment_details($shipment['voucher_number']);
            if (isset($result['success']) && $result['success'] === false) {
                $failed++;
                $errors[] = "#$order_id: " . $result['error'];
                continue;
            }
            $code = (string)($result['StatusCode']        ?? $result['DeliveryStatus'] ?? '');
            $desc = (string)($result['StatusDescription'] ?? $result['DeliveryStatusDescription'] ?? '');
            $final = in_array($code, $final_codes, true);
            $old_code = (string)($shipment['status_code'] ?? '');
            $this->model_extension_couriercenter_shipping_courier_center->updateStatus($order_id, $code, $desc, $final);
            if ($code !== '' && $code !== $old_code) {
                $this->ccAddOrderHistory($order_id, sprintf('📍 [Bulk] Courier Center status: %s — %s', $code, $desc));
                $this->ccMaybeAutoComplete($order_id, $code);
            }
            $updated++;
            usleep(300000);
        }

        $msg = "Σύνολο: " . count($order_ids) . " | 🔄 Ενημερώθηκαν: $updated";
        if ($skipped) $msg .= " | ⏭️ Χωρίς voucher: $skipped";
        if ($failed)  $msg .= " | ❌ Απέτυχαν: $failed";
        if ($errors)  $msg .= "\n\n" . implode("\n", array_slice($errors, 0, 15));

        $this->response->setOutput(json_encode(['success' => true, 'message' => $msg]));
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

        $result = $this->ccApi()->void_shipment($shipment['voucher_number']);
        if ($result !== true) {
            $this->response->setOutput(json_encode(['success' => false, 'error' => $result['error'] ?? 'Αποτυχία ακύρωσης']));
            return;
        }

        $this->model_extension_couriercenter_shipping_courier_center->voidShipment($order_id);
        $this->ccAddOrderHistory($order_id, '❌ Courier Center: Αποστολή ακυρώθηκε — AWB ' . $shipment['voucher_number']);
        $this->response->setOutput(json_encode(['success' => true]));
    }

    public function removeBoxNow(): void {
        $this->response->addHeader('Content-Type: application/json');

        $order_id = (int)($this->request->post['order_id'] ?? 0);
        $this->load->model('extension/couriercenter/shipping/courier_center');

        $shipment = $this->model_extension_couriercenter_shipping_courier_center->getShipment($order_id);
        if (!empty($shipment['voucher_number']) && empty($shipment['is_voided'])) {
            $this->response->setOutput(json_encode(['success' => false, 'error' => 'Ακύρωσε πρώτα την αποστολή.']));
            return;
        }

        $q = $this->db->query("SELECT `custom_field` FROM `" . DB_PREFIX . "order` WHERE `order_id` = '" . (int)$order_id . "' LIMIT 1");
        if ($q->row) {
            $custom = json_decode($q->row['custom_field'] ?? '{}', true) ?: [];
            unset($custom['cc_locker_id'], $custom['cc_locker_name'], $custom['cc_locker_code'], $custom['cc_delivery_mode']);
            $this->db->query("UPDATE `" . DB_PREFIX . "order` SET `custom_field` = '" . $this->db->escape(json_encode($custom)) . "' WHERE `order_id` = '" . (int)$order_id . "'");
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

        // Rate limit: 60 min between manual checks of the same shipment (#3).
        $last    = (int)($shipment['last_checked_at'] ?? 0);
        $elapsed = time() - $last;
        if ($last > 0 && $elapsed < 3600) {
            $mins = max(1, (int)ceil((3600 - $elapsed) / 60));
            $this->response->setOutput(json_encode(['success' => false, 'error' => "Η αποστολή ενημερώθηκε πρόσφατα. Ξαναδοκίμασε σε $mins λεπτά."]));
            return;
        }

        $result = $this->ccApi()->get_shipment_details($shipment['voucher_number']);
        if (isset($result['success']) && $result['success'] === false) {
            $this->response->setOutput(json_encode(['success' => false, 'error' => $result['error']]));
            return;
        }

        $final_codes = ['29', '25', '99', '14', '87', '95'];
        $code  = (string)($result['StatusCode']        ?? $result['DeliveryStatus'] ?? '');
        $desc  = (string)($result['StatusDescription'] ?? $result['DeliveryStatusDescription'] ?? '');
        $final = in_array($code, $final_codes, true);

        $old_code = (string)($shipment['status_code'] ?? '');
        $this->model_extension_couriercenter_shipping_courier_center->updateStatus($order_id, $code, $desc, $final);

        // Order History note + auto-complete, only when the status actually changed.
        if ($code !== '' && $code !== $old_code) {
            $this->ccAddOrderHistory($order_id, sprintf('📍 Courier Center status: %s — %s', $code, $desc));
            $this->ccMaybeAutoComplete($order_id, $code);
        }

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

        // Which AWB(s) to print: main (optionally combined with return), or return only.
        $type = (($this->request->get['type'] ?? '') === 'return') ? 'return' : 'main';
        if ($type === 'return') {
            if (empty($shipment['return_awb'])) {
                http_response_code(404);
                exit('Δεν υπάρχει επιστροφικό voucher για αυτή την παραγγελία.');
            }
            $awbs = [$shipment['return_awb']];
        } else {
            $awbs = [$shipment['voucher_number']];
            if (!empty($shipment['return_awb'])) $awbs[] = $shipment['return_awb'];
        }

        // BOX NOW shipments use their own print template.
        $is_boxnow    = !empty($shipment['is_boxnow']);
        $raw_template = $is_boxnow
            ? ((string)$this->config->get($this->prefix . 'print_template_boxnow') ?: 'singlepdf_100x150_4up')
            : ((string)$this->config->get($this->prefix . 'print_template')        ?: 'pdf');

        // 100x150_4up is custom: request singlepdf_100x150 then arrange 4-up via FPDI.
        $use_4up      = ($raw_template === 'singlepdf_100x150_4up');
        $api_template = $use_4up ? 'singlepdf_100x150' : $raw_template;

        $pdf = $this->ccApi()->get_voucher_pdf(implode(',', $awbs), $api_template);

        if (is_array($pdf)) {
            http_response_code(500);
            exit('Σφάλμα voucher: ' . ($pdf['error'] ?? 'Άγνωστο σφάλμα'));
        }

        require_once DIR_EXTENSION . 'couriercenter/library/CCPdfScaler.php';
        try {
            if ($use_4up) {
                $pdf = \Opencart\Extension\Couriercenter\Library\CCPdfScaler::arrange_4up($pdf);
            } elseif (in_array($api_template, ['pdf', 'clean'], true)) {
                $pdf = \Opencart\Extension\Couriercenter\Library\CCPdfScaler::scale_pdf($pdf);
            }
        } catch (\Throwable $e) {
            // Serve original PDF if post-processing fails.
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="voucher_' . $shipment['voucher_number'] . '.pdf"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
        exit;
    }

    /**
     * Bulk: stream a single combined PDF with the vouchers of the selected
     * orders (normal couriers only). GET order_ids=1,2,3 — opened in a new tab.
     */
    public function bulkPrint(): void {
        $this->ccStreamBulkPdf(false);
    }

    /**
     * Bulk: stream a combined PDF with the BOX NOW vouchers of the selected
     * orders, arranged 4-up on A4. GET order_ids=1,2,3.
     */
    public function bulkPrintBoxnow(): void {
        $this->ccStreamBulkPdf(true);
    }

    private function ccStreamBulkPdf(bool $boxnow_only): void {
        $order_ids = $this->ccParseOrderIds($this->request->get['order_ids'] ?? '');
        if (empty($order_ids)) {
            http_response_code(400);
            exit('Δεν επιλέχθηκαν παραγγελίες.');
        }

        $this->load->model('extension/couriercenter/shipping/courier_center');

        $awbs = [];
        foreach ($order_ids as $order_id) {
            $s = $this->model_extension_couriercenter_shipping_courier_center->getShipment($order_id);
            if (empty($s['voucher_number']) || !empty($s['is_voided'])) continue;
            if ($boxnow_only && empty($s['is_boxnow'])) continue;
            if (!$boxnow_only && !empty($s['is_boxnow'])) continue;
            $awbs[] = $s['voucher_number'];
            if (!empty($s['return_awb'])) $awbs[] = $s['return_awb'];
        }

        if (empty($awbs)) {
            http_response_code(404);
            exit($boxnow_only
                ? 'Καμία από τις επιλεγμένες παραγγελίες δεν έχει ενεργό BOX NOW voucher.'
                : 'Καμία από τις επιλεγμένες παραγγελίες δεν έχει ενεργό voucher.');
        }

        // Resolve the print template (BOX NOW has its own); 100x150_4up is custom:
        // request singlepdf_100x150 from the API then arrange 4-up via FPDI.
        $raw_template = $boxnow_only
            ? ((string)$this->config->get($this->prefix . 'print_template_boxnow') ?: 'singlepdf_100x150_4up')
            : ((string)$this->config->get($this->prefix . 'print_template')        ?: 'pdf');
        $use_4up      = ($raw_template === 'singlepdf_100x150_4up');
        $api_template = $use_4up ? 'singlepdf_100x150' : $raw_template;

        $pdf = $this->ccApi()->get_voucher_pdf(implode(',', $awbs), $api_template);
        if (is_array($pdf)) {
            http_response_code(500);
            exit('Σφάλμα λήψης vouchers: ' . ($pdf['error'] ?? 'Άγνωστο σφάλμα'));
        }

        require_once DIR_EXTENSION . 'couriercenter/library/CCPdfScaler.php';
        try {
            if ($use_4up) {
                $pdf = \Opencart\Extension\Couriercenter\Library\CCPdfScaler::arrange_4up($pdf);
            } elseif (in_array($api_template, ['pdf', 'clean'], true)) {
                $pdf = \Opencart\Extension\Couriercenter\Library\CCPdfScaler::scale_pdf($pdf);
            }
        } catch (\Throwable $e) {
            // Serve the original PDF if post-processing fails.
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="vouchers-' . date('Y-m-d-His') . '.pdf"');
        header('Content-Length: ' . strlen($pdf));
        header('Cache-Control: no-cache');
        echo $pdf;
        exit;
    }

    /**
     * Read the customer's BOX NOW selection (saved at checkout) from the order's
     * custom_field. Returns BOX NOW options for ccCreateVoucherForOrder(), or an
     * empty array for a normal (non-BOX NOW) order.
     */
    private function ccBoxnowFromOrder(int $order_id): array {
        $q = $this->db->query("SELECT `custom_field` FROM `" . DB_PREFIX . "order` WHERE `order_id` = '" . (int)$order_id . "' LIMIT 1");
        if (!$q->row) return [];

        $cf = $q->row['custom_field'] ?? [];
        if (!is_array($cf)) {
            $cf = json_decode((string)$cf, true) ?: [];
        }

        $locker_id     = (string)($cf['cc_locker_id']     ?? '');
        $delivery_mode = (string)($cf['cc_delivery_mode'] ?? '');

        // Not a BOX NOW order (no locker and not the "auto/nearest" mode).
        if ($locker_id === '' && $delivery_mode !== 'auto') {
            return [];
        }

        return [
            'boxnow'      => true,
            'locker_id'   => $locker_id,
            'locker_code' => (string)($cf['cc_locker_code'] ?? ''),
            'locker_name' => (string)($cf['cc_locker_name'] ?? ''),
        ];
    }

    /**
     * Enrich order products with real weight + dimensions from the product table
     * (oc_order_product has none). Weight is converted to kg and dimensions to cm
     * from the product's weight/length class, mirroring the WooCommerce plugin's
     * wc_get_weight()/wc_get_dimension() — a store measuring in grams or inches
     * would otherwise send raw values (e.g. 146 g -> 146 kg) and exceed limits.
     */
    private function ccEnrichProducts(array $products): array {
        foreach ($products as &$p) {
            $pid = (int)($p['product_id'] ?? 0);
            if ($pid <= 0) continue;
            $q = $this->db->query(
                "SELECT `weight`, `weight_class_id`, `length`, `width`, `height`, `length_class_id` FROM `" . DB_PREFIX . "product`
                 WHERE `product_id` = '" . $pid . "' LIMIT 1"
            );
            if ($q->num_rows) {
                $wcid = (int)$q->row['weight_class_id'];
                $lcid = (int)$q->row['length_class_id'];
                $p['weight'] = $this->ccConvertClass((float)$q->row['weight'], $wcid, 'weight_class', 'kg');
                $p['length'] = $this->ccConvertClass((float)$q->row['length'], $lcid, 'length_class', 'cm');
                $p['width']  = $this->ccConvertClass((float)$q->row['width'],  $lcid, 'length_class', 'cm');
                $p['height'] = $this->ccConvertClass((float)$q->row['height'], $lcid, 'length_class', 'cm');
            }
        }
        unset($p);
        return $products;
    }

    /**
     * Convert a value stored in an OpenCart weight/length class to the unit the
     * Courier Center API expects (kg / cm). Looks up the target class by unit and
     * scales by the class ratios (value_target / value_from). Falls back to the
     * raw value if the target or source class cannot be resolved.
     */
    private function ccConvertClass(float $value, int $from_class_id, string $table, string $unit): float {
        if ($value <= 0 || $from_class_id <= 0) {
            return $value;
        }
        static $target_cache = [];
        $key = $table . '|' . $unit;
        if (!array_key_exists($key, $target_cache)) {
            $r = $this->db->query(
                "SELECT c.`" . $table . "_id` AS id, c.`value` AS val
                   FROM `" . DB_PREFIX . $table . "` c
                   JOIN `" . DB_PREFIX . $table . "_description` d ON c.`" . $table . "_id` = d.`" . $table . "_id`
                  WHERE LOWER(TRIM(d.`unit`)) = '" . $this->db->escape($unit) . "' LIMIT 1"
            );
            $target_cache[$key] = $r->num_rows ? ['id' => (int)$r->row['id'], 'val' => (float)$r->row['val']] : null;
        }
        $target = $target_cache[$key];
        if (!$target || $from_class_id === $target['id']) {
            return $value;
        }
        $f = $this->db->query("SELECT `value` FROM `" . DB_PREFIX . $table . "` WHERE `" . $table . "_id` = '" . (int)$from_class_id . "' LIMIT 1");
        $from_val = $f->num_rows ? (float)$f->row['value'] : 0.0;
        if ($from_val <= 0) {
            return $value;
        }
        return $value * ($target['val'] / $from_val);
    }

    /** Parse a "1,2,3" order-id list into a unique array of positive ints. */
    private function ccParseOrderIds(string $raw): array {
        $ids = array_filter(array_map('intval', explode(',', $raw)), fn($id) => $id > 0);
        return array_values(array_unique($ids));
    }

    /**
     * The shipping method [code, name] of an order. OpenCart stores it as JSON
     * in oc_order.shipping_method with a "code" like "courier_center.courier_center";
     * the plugin-facing code is the part before the dot.
     */
    private function ccOrderShipping(int $order_id): array {
        $q = $this->db->query("SELECT `shipping_method` FROM `" . DB_PREFIX . "order` WHERE `order_id` = '" . (int)$order_id . "' LIMIT 1");
        if (!$q->num_rows) return ['code' => '', 'name' => ''];
        $m = json_decode((string)$q->row['shipping_method'], true);
        if (!is_array($m)) return ['code' => '', 'name' => ''];
        $full = (string)($m['code'] ?? '');
        return [
            'code' => $full !== '' ? explode('.', $full)[0] : '',
            'name' => (string)($m['name'] ?? ''),
        ];
    }

    /**
     * Does the plugin manage this order's shipping method? (mirrors WooCommerce's
     * CC_Shipment_Builder::is_handled_order). If no methods are configured the
     * plugin manages ALL orders, so existing installs keep working.
     */
    private function ccIsHandledOrder(int $order_id): bool {
        $handled = $this->config->get($this->prefix . 'handled_shipping_methods');
        $handled = is_array($handled) ? array_values(array_filter(array_map('strval', $handled))) : [];
        if (empty($handled)) return true;
        return in_array($this->ccOrderShipping($order_id)['code'], $handled, true);
    }

    /**
     * Append a comment to an order's History tab (like WooCommerce order notes).
     * If $new_status_id > 0 and differs from the current status, also changes the
     * order status (used for auto-complete on delivery). Customer is never notified.
     */
    private function ccAddOrderHistory(int $order_id, string $comment, int $new_status_id = 0): void {
        if ($order_id <= 0) return;

        $cur = $this->db->query(
            "SELECT `order_status_id` FROM `" . DB_PREFIX . "order` WHERE `order_id` = '" . (int)$order_id . "' LIMIT 1"
        );
        if (!$cur->num_rows) return;

        $current_status = (int)$cur->row['order_status_id'];
        $status_id      = $new_status_id > 0 ? $new_status_id : $current_status;

        // order_status_id 0 = incomplete/abandoned order — don't write history for it.
        if ($status_id <= 0) return;

        $this->db->query(
            "INSERT INTO `" . DB_PREFIX . "order_history` SET
                `order_id`        = '" . (int)$order_id . "',
                `order_status_id` = '" . (int)$status_id . "',
                `notify`          = '0',
                `comment`         = '" . $this->db->escape($comment) . "',
                `date_added`      = NOW()"
        );

        if ($new_status_id > 0 && $new_status_id !== $current_status) {
            $this->db->query(
                "UPDATE `" . DB_PREFIX . "order`
                 SET `order_status_id` = '" . (int)$new_status_id . "', `date_modified` = NOW()
                 WHERE `order_id` = '" . (int)$order_id . "'"
            );
        }
    }

    /**
     * If a shipment reached a "delivered" status and auto-complete is configured,
     * move the order to the chosen status. Returns true if it changed the order.
     */
    private function ccMaybeAutoComplete(int $order_id, string $status_code): bool {
        if (!in_array($status_code, ['29', '87'], true)) return false;

        $auto_status = (int)$this->config->get($this->prefix . 'auto_complete_status_id');
        if ($auto_status <= 0) return false;

        $cur = $this->db->query(
            "SELECT `order_status_id` FROM `" . DB_PREFIX . "order` WHERE `order_id` = '" . (int)$order_id . "' LIMIT 1"
        );
        if (!$cur->num_rows || (int)$cur->row['order_status_id'] === $auto_status) return false;

        $this->ccAddOrderHistory($order_id, '🚚 Courier Center: Η αποστολή παραδόθηκε — αυτόματη ολοκλήρωση παραγγελίας.', $auto_status);
        return true;
    }

    /** Greek label for a service-type code, for order-history notes. */
    private function ccServiceLabel(string $service): string {
        return [
            'next_day'    => 'Επόμενη Μέρα',
            'same_day_3h' => 'Αυθημερόν 3ω',
            'same_day_5h' => 'Αυθημερόν 5ω',
        ][$service] ?? $service;
    }

    /**
     * Lightweight install/usage ping to the Courier Center dashboard (mirrors the
     * WooCommerce CC_Updater::send_ping). Throttled to once per 12h. Fire-and-forget:
     * telemetry must never slow down or break the admin.
     */
    private function ccSendPing(bool $force = false): void {
        try {
            $last = (int)$this->config->get($this->prefix . 'ping_sent_at');
            if (!$force && $last > 0 && (time() - $last) < 43200) {
                return;
            }

            $version = '1.0.0';
            $f = DIR_EXTENSION . 'couriercenter/install.json';
            if (is_file($f)) {
                $j = json_decode((string)file_get_contents($f), true);
                if (!empty($j['version'])) $version = (string)$j['version'];
            }

            $payload = [
                'site_url'         => (string)($this->config->get('config_url') ?: (defined('HTTP_CATALOG') ? HTTP_CATALOG : '')),
                'platform'         => 'OpenCart',
                'opencart_version' => defined('VERSION') ? VERSION : 'N/A',
                'plugin_version'   => $version,
                'php_version'      => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
            ];

            $ch = curl_init('https://courier-center-dashboard.onrender.com/api/ping');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($payload),
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT        => 5,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            @curl_exec($ch);
            curl_close($ch);

            // Save throttle timestamp (direct upsert — survives outside the form save).
            $key = $this->prefix . 'ping_sent_at';
            $ex  = $this->db->query("SELECT `setting_id` FROM `" . DB_PREFIX . "setting` WHERE `store_id` = '0' AND `key` = '" . $this->db->escape($key) . "' LIMIT 1");
            if ($ex->num_rows) {
                $this->db->query("UPDATE `" . DB_PREFIX . "setting` SET `value` = '" . (int)time() . "' WHERE `store_id` = '0' AND `key` = '" . $this->db->escape($key) . "'");
            } else {
                $this->db->query("INSERT INTO `" . DB_PREFIX . "setting` SET `store_id` = '0', `code` = 'shipping_courier_center', `key` = '" . $this->db->escape($key) . "', `value` = '" . (int)time() . "', `serialized` = '0'");
            }
        } catch (\Throwable $e) {
            // Telemetry must never break anything.
        }
    }

    private function ccApi(): \Opencart\Extension\Couriercenter\Library\CCApi {
        require_once DIR_EXTENSION . 'couriercenter/library/CCApi.php';
        return new \Opencart\Extension\Couriercenter\Library\CCApi(
            (string)$this->config->get($this->prefix . 'user_alias'),
            (string)$this->config->get($this->prefix . 'credential_value'),
            (string)$this->config->get($this->prefix . 'api_key'),
            (string)$this->config->get($this->prefix . 'billing_account')
        );
    }

    private function ccBuildSettings(): array {
        $postcode = (string)$this->config->get($this->prefix . 'shipper_postcode');
        require_once DIR_EXTENSION . 'couriercenter/library/CCCityScope.php';
        $station = \Opencart\Extension\Couriercenter\Library\CCCityScope::get_station_for_postcode($postcode);
        return [
            'user_alias'       => (string)$this->config->get($this->prefix . 'user_alias'),
            'credential_value' => (string)$this->config->get($this->prefix . 'credential_value'),
            'api_key'          => (string)$this->config->get($this->prefix . 'api_key'),
            'billing_account'  => (string)$this->config->get($this->prefix . 'billing_account'),
            'shipper_name'     => (string)$this->config->get($this->prefix . 'shipper_name'),
            'shipper_address'  => (string)$this->config->get($this->prefix . 'shipper_address'),
            'shipper_postal'   => $postcode,
            'shipper_city'     => (string)$this->config->get($this->prefix . 'shipper_city'),
            'shipper_phone'    => (string)$this->config->get($this->prefix . 'shipper_phone'),
            'shipper_station'  => $station,
        ];
    }

    private function validate(): bool {
        if (!$this->user->hasPermission('modify', 'extension/couriercenter/shipping/courier_center')) {
            $this->error['warning'] = $this->language->get('error_permission');
        }
        return !$this->error;
    }
}
