<?php
/**
 * Courier Center - OpenCart 4 shipping extension (admin): settings page, install / uninstall / repair and
 * the order AJAX endpoints. Voucher creation / void / tracking go through the shared library
 * (CCVoucherService, CCStatusTracker), the same code the OpenCart 3 extension runs.
 */
namespace Opencart\Admin\Controller\Extension\Couriercenter\Shipping;

require_once DIR_EXTENSION . 'couriercenter/library/oc4/loader.php';

use Opencart\Extension\Couriercenter\Library\CCApi;
use Opencart\Extension\Couriercenter\Library\CCAutofill;
use Opencart\Extension\Couriercenter\Library\CCDbSchema;
use Opencart\Extension\Couriercenter\Library\CCPdfScaler;
use Opencart\Extension\Couriercenter\Library\CCPickupPoints;
use Opencart\Extension\Couriercenter\Library\CCReturnsRules;
use Opencart\Extension\Couriercenter\Library\CCReturnsSettings;
use Opencart\Extension\Couriercenter\Library\CCStatusTracker;
use Opencart\Extension\Couriercenter\Library\CCVoucherService;
use Opencart\Extension\Couriercenter\Library\Oc4\CCOc4Setup;

class CourierCenter extends \Opencart\System\Engine\Controller {
    use \Opencart\Extension\Couriercenter\Library\Oc4\CCOc4Controller;

    const ROUTE = 'extension/couriercenter/shipping/courier_center';
    const SECRET_FIELDS = ['credential_value', 'api_key'];
    const DEFAULT_TRACKING_URL = 'https://www.courier.gr/track/result?tracknr={{tracking}}';

    private array $error = [];
    private string $prefix = 'shipping_courier_center_';

    // ── Settings page ──────────────────────────────────────────────────────
    public function index(): void {
        $this->load->language(self::ROUTE);
        $this->document->setTitle($this->language->get('heading_title'));
        $this->load->model('setting/setting');

        if ($this->request->server['REQUEST_METHOD'] === 'POST' && $this->validate()) {
            $post = $this->request->post;
            $warnings = $this->prepareSettingsPost($post);
            $this->model_setting_setting->editSetting('shipping_courier_center', $post);
            $this->session->data['success'] = $this->language->get('text_success');
            if ($warnings) {
                $this->session->data['cc_warnings'] = $warnings;
                $this->response->redirect($this->ccLink(self::ROUTE));
            }
            $this->response->redirect($this->ccLink('marketplace/extension', 'type=shipping'));
        }
        CCOc4Setup::repairIfUpdated($this->db, DB_PREFIX);
        CCDbSchema::ensureOnce($this->db, DB_PREFIX);
        $this->ccSendPing();

        $data = [];
        $data['error_warning'] = $this->error['warning'] ?? '';
        $data['success'] = '';
        $data['warnings'] = [];
        if (isset($this->session->data['success'])) {
            $data['success'] = (string)$this->session->data['success'];
            unset($this->session->data['success']);
        }
        if (isset($this->session->data['cc_warnings'])) {
            $data['warnings'] = (array)$this->session->data['cc_warnings'];
            unset($this->session->data['cc_warnings']);
        }
        $data['breadcrumbs'] = [
            ['text' => $this->language->get('text_home'), 'href' => $this->ccLink('common/dashboard')],
            ['text' => $this->language->get('text_extension'), 'href' => $this->ccLink('marketplace/extension', 'type=shipping')],
            ['text' => $this->language->get('heading_title'), 'href' => $this->ccLink(self::ROUTE)],
        ];
        foreach (['heading_title', 'button_save', 'button_cancel', 'text_enabled', 'text_disabled', 'text_none', 'text_all_zones',
            'entry_status', 'entry_user_alias', 'entry_credential_value', 'entry_api_key', 'entry_billing_account',
            'entry_shipper_name', 'entry_shipper_address', 'entry_shipper_postcode', 'entry_shipper_city', 'entry_shipper_phone',
            'entry_cost', 'entry_cod_fee', 'entry_geo_zone', 'entry_tax_class', 'entry_sort_order'] as $k) {
            $data[$k] = $this->language->get($k);
        }

        $fields = [
            'status', 'user_alias', 'billing_account',
            'shipper_name', 'shipper_address', 'shipper_postcode', 'shipper_city', 'shipper_phone',
            'tracking_url', 'email_tracking_enabled',
            'boxnow_enabled', 'boxnow_default_selected', 'print_template', 'print_template_boxnow', 'auto_complete_status_id', 'auto_complete_notify',
            'street_number_field', 'pseudo_cron',
            'auto_create_enabled', 'auto_create_status_id',
            'cost', 'cod_fee', 'geo_zone_id', 'tax_class_id', 'sort_order',
        ];
        foreach ($fields as $field) {
            $value = $this->config->get($this->prefix . $field);
            $data[$field] = $value === null ? '' : $this->ccText($value);
        }
        // Secrets are never sent back to the browser: an empty field keeps the saved value.
        foreach (self::SECRET_FIELDS as $field) {
            $data[$field . '_saved'] = trim((string)$this->config->get($this->prefix . $field)) !== '';
        }
        if ($data['tracking_url'] === '') {
            $data['tracking_url'] = self::DEFAULT_TRACKING_URL;
        }
        $defaults = ['email_tracking_enabled' => '1', 'print_template' => 'pdf', 'print_template_boxnow' => 'singlepdf_100x150_4up',
            'auto_complete_notify' => '1', 'pseudo_cron' => '1', 'auto_create_enabled' => '0', 'auto_create_status_id' => '0', 'cost' => '0', 'cod_fee' => '0', 'sort_order' => '0'];
        foreach ($defaults as $k => $v) {
            if ($data[$k] === '') {
                $data[$k] = $v;
            }
        }

        // auto_complete_status_id: '' = automatic (the shop's first complete status), '0' = off.
        // Address custom fields that can hold a separate street number (text fields)
        $this->load->model('customer/custom_field');
        $data['address_custom_fields'] = [];
        foreach ($this->model_customer_custom_field->getCustomFields([]) as $cf) {
            if (($cf['location'] ?? '') === 'address' && in_array(($cf['type'] ?? ''), ['text', 'textarea'], true)) {
                $data['address_custom_fields'][] = ['id' => (string)$cf['custom_field_id'], 'name' => $this->ccText($cf['name'])];
            }
        }

        $this->load->model('localisation/order_status');
        $data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();
        $this->load->model('localisation/geo_zone');
        $data['geo_zones'] = $this->model_localisation_geo_zone->getGeoZones();
        $this->load->model('localisation/tax_class');
        $data['tax_classes'] = $this->model_localisation_tax_class->getTaxClasses();

        $data['shipping_methods_list'] = $this->shippingMethodsList();
        $bn = $this->config->get($this->prefix . 'boxnow_shipping_methods');
        $data['boxnow_shipping_methods'] = (is_array($bn) && $bn) ? $bn : ['courier_center'];
        $hm = $this->config->get($this->prefix . 'handled_shipping_methods');
        $data['handled_shipping_methods'] = is_array($hm) ? $hm : [];

        // Pickup points (sub-codes) and the default point
        $points = CCPickupPoints::fromConfig($this->config);
        $data['pickup_points'] = $points->subs();
        $data['points_all'] = $points->all();
        $data['default_pickup_point'] = $points->defaultCode();

        // Returns
        $rs = new CCReturnsSettings($this->config);
        $data['returns'] = [
            'enabled'              => $rs->enabled(),
            'require_approval'     => $rs->requireApproval(),
            'window_days'          => $rs->windowDays(),
            'method_dropoff'       => in_array(CCReturnsRules::METHOD_DROPOFF, $rs->enabledMethods(), true),
            'method_pickup'        => in_array(CCReturnsRules::METHOD_PICKUP, $rs->enabledMethods(), true),
            'method_boxnow'        => in_array(CCReturnsRules::METHOD_BOXNOW, $rs->enabledMethods(), true),
            'admin_notify'         => $rs->adminNotify(),
            'email_received'       => $rs->customerEmailEnabled('received'),
            'email_created'        => $rs->customerEmailEnabled('created'),
            'email_rejected'       => $rs->customerEmailEnabled('rejected'),
            'admin_emails'         => $this->ccText($this->config->get($this->prefix . 'returns_admin_emails')),
            'weight'               => $rs->weight(),
            'accounts'             => $rs->accountRows(),
            'dropoff_instructions' => $this->ccText($this->config->get($this->prefix . 'returns_dropoff_instructions')),
            'boxnow_instructions'  => $this->ccText($this->config->get($this->prefix . 'returns_boxnow_instructions')),
            'pickup_instructions'  => $this->ccText($this->config->get($this->prefix . 'returns_pickup_instructions')),
        ];
        $data['returns_defaults'] = ['dropoff_instructions' => CCReturnsSettings::DEFAULT_DROPOFF_TEXT, 'pickup_instructions' => CCReturnsSettings::DEFAULT_PICKUP_TEXT,
            'boxnow_instructions' => CCReturnsSettings::DEFAULT_BOXNOW_TEXT];
        $data['shop_email'] = (string)$this->config->get('config_email');
        $data['locker_sizes'] = CCReturnsRules::LOCKER_SIZES;
        $data['dropoff_shipper'] = CCReturnsRules::DROPOFF_SHIPPER;

        // Cron: CLI and URL with a secret key
        $data['version'] = CCOc4Setup::version();
        $data['cron_last_run'] = CCOc4Setup::getState($this->db, DB_PREFIX, 'cron_last_run') ?: '—';
        $data['cron_next_run'] = CCOc4Setup::getState($this->db, DB_PREFIX, 'cron_next_run') ?: '—';
        $data['cron_cli'] = 'php ' . str_replace('\\', '/', DIR_EXTENSION) . 'couriercenter/cron_status_tracker.php';
        $data['cron_url'] = $this->cronUrl(CCOc4Setup::ensureCronKey($this->db, DB_PREFIX));

        $data['action'] = $this->ccLink(self::ROUTE);
        $data['back'] = $this->ccLink('marketplace/extension', 'type=shipping');
        $urls = [];
        foreach (['autofill', 'autofillPoint', 'clearSettings', 'repair', 'regenerateCronKey'] as $m) {
            $urls[$m] = $this->ccLink(self::ROUTE . '.' . $m);
        }
        $urls['update_check'] = $this->ccLink(self::ROUTE . '_update.check');
        $urls['update_apply'] = $this->ccLink(self::ROUTE . '_update.apply');
        $data['urls'] = $urls;
        $data['url_manifest'] = $this->ccLink(self::ROUTE . '_manifest');
        $data['url_returns'] = $this->ccLink(self::ROUTE . '_returns');
        $data['url_bug_report'] = $this->ccLink(self::ROUTE . '_bug');
        $data['url_docs'] = $this->ccLink(self::ROUTE . '_docs');

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');
        $this->response->setOutput($this->load->view('extension/couriercenter/shipping/courier_center', $data));
    }

    // ── Install / uninstall / repair ───────────────────────────────────────
    public function install(): void {
        CCOc4Setup::repair($this->db, DB_PREFIX, (int)$this->user->getGroupId());
        // Extensions > Shipping lists only the main method: the helper controllers live under shipping/ for routing.
        $this->db->query("DELETE FROM `" . DB_PREFIX . "extension_path` WHERE `path` LIKE 'couriercenter/admin/controller/shipping/%.php' AND `path` <> 'couriercenter/admin/controller/shipping/courier_center.php'");
        $this->ccSendPing(true);
    }

    /** The events go; vouchers, returns and the cron key stay (a re-install keeps the history). */
    public function uninstall(): void {
        $codes = [];
        foreach (array_merge(array_keys(CCOc4Setup::events()), CCOc4Setup::obsoleteEvents()) as $code) {
            $codes[] = "'" . $this->db->escape($code) . "'";
        }
        $this->db->query("DELETE FROM `" . DB_PREFIX . "event` WHERE `code` IN (" . implode(',', $codes) . ")");
    }

    public function repair(): void {
        if (!$this->ccCanModify()) {
            $this->ccDeny();
            return;
        }
        $this->ccJson(['success' => true, 'done' => CCOc4Setup::repair($this->db, DB_PREFIX, (int)$this->user->getGroupId())]);
    }

    // ── Settings tools ─────────────────────────────────────────────────────
    /** Test shipment -> read the account's sender data -> void. Only the credentials and the sender keys are written. */
    public function autofill(): void {
        if (!$this->ccCanModify()) {
            $this->ccDeny();
            return;
        }
        $creds = [];
        foreach (['user_alias', 'credential_value', 'api_key', 'billing_account'] as $k) {
            $v = trim($this->ccText($this->request->post[$k] ?? ''));
            $creds[$k] = $v !== '' ? $v : trim($this->ccText($this->config->get($this->prefix . $k)));
        }
        if (in_array('', $creds, true)) {
            $this->ccJson(['success' => false, 'error' => 'Συμπλήρωσε πρώτα τα API credentials']);
            return;
        }
        $api = new CCApi($creds['user_alias'], $creds['credential_value'], $creds['api_key'], $creds['billing_account']);
        $r = CCAutofill::fetch($api, $this->ccContext($creds), $creds['billing_account']);
        if (!$r['ok'] && empty($r['account_ok'])) {
            $this->ccJson(['success' => false, 'error' => $r['error']]);
            return;
        }
        foreach ($creds as $k => $v) {
            CCOc4Setup::setSetting($this->db, DB_PREFIX, $k, $v);
        }
        if (!$r['ok']) {
            $this->ccJson(['success' => false, 'error' => $r['error']]);
            return;
        }
        $out = ['success' => true];
        foreach (['name', 'address', 'postcode', 'city', 'phone'] as $k) {
            $out['shipper_' . $k] = $r['data'][$k];
            if ($r['data'][$k] !== '') {
                CCOc4Setup::setSetting($this->db, DB_PREFIX, 'shipper_' . $k, $r['data'][$k]);
            }
        }
        if ($r['warning'] !== '') {
            $out['warning'] = $r['warning'];
        }
        $this->ccJson($out);
    }

    /** Test & Auto-fill of ONE pickup point (sub-code): returns its sender data, writes nothing. */
    public function autofillPoint(): void {
        if (!$this->ccCanModify()) {
            $this->ccDeny();
            return;
        }
        $code = trim($this->ccText($this->request->post['code'] ?? ''));
        $creds = [];
        foreach (['user_alias', 'credential_value', 'api_key', 'billing_account'] as $k) {
            $creds[$k] = trim($this->ccText($this->config->get($this->prefix . $k)));
        }
        if ($creds['user_alias'] === '' || $creds['credential_value'] === '' || $creds['api_key'] === '') {
            $this->ccJson(['success' => false, 'error' => 'Αποθήκευσε πρώτα τα API credentials (και κάνε Test & Auto-fill στον κεντρικό κωδικό).']);
            return;
        }
        if ($code !== '' && $code === $creds['billing_account']) {
            $this->ccJson(['success' => false, 'error' => 'Αυτός είναι ο κεντρικός κωδικός· πρόσθεσε μόνο υποκωδικούς.']);
            return;
        }
        $api = new CCApi($creds['user_alias'], $creds['credential_value'], $creds['api_key'], $code);
        $r = CCAutofill::fetch($api, $this->ccContext($creds), $code);
        $this->ccJson($r['ok'] ? ['success' => true, 'data' => $r['data'], 'warning' => $r['warning']] : ['success' => false, 'error' => $r['error']]);
    }

    public function clearSettings(): void {
        if (!$this->ccCanModify()) {
            $this->ccDeny();
            return;
        }
        $this->load->model('setting/setting');
        $this->model_setting_setting->deleteSetting('shipping_courier_center');
        $this->ccJson(['success' => true]);
    }

    public function regenerateCronKey(): void {
        if (!$this->ccCanModify()) {
            $this->ccDeny();
            return;
        }
        CCOc4Setup::setState($this->db, DB_PREFIX, 'cron_key', '');
        $this->ccJson(['success' => true, 'cron_url' => $this->cronUrl(CCOc4Setup::ensureCronKey($this->db, DB_PREFIX))]);
    }

    // ── Order AJAX endpoints (order panel + bulk bar) ──────────────────────
    public function createVoucher(): void {
        if (!$this->ccCanModify()) {
            $this->ccDeny();
            return;
        }
        try {
            $p = $this->request->post;
            $order_id = (int)($p['order_id'] ?? 0);
            $order = $this->loadOrder($order_id);
            if (!$order) {
                $this->ccJson(['success' => false, 'error' => 'Παραγγελία δε βρέθηκε']);
                return;
            }
            $svc = $this->ccService();
            $existing = $svc->getShipment($order_id);
            if (CCVoucherService::isActive($existing)) {
                $this->ccJson(['success' => false, 'error' => 'Η παραγγελία έχει ήδη ενεργό voucher (' . $existing['voucher_number'] . '). Ακύρωσέ το πρώτα.']);
                return;
            }
            if (empty($p['override']) && !$svc->isHandledOrder($order)) {
                $name = CCVoucherService::shippingName($order);
                $this->ccJson(['success' => false, 'needs_override' => true,
                    'error' => 'Η παραγγελία χρησιμοποιεί μέθοδο αποστολής «' . ($name !== '' ? $name : '—') . '» που δεν διαχειρίζεται το plugin. Δημιουργία αποστολής στην Courier Center ούτως ή άλλως;']);
                return;
            }
            $this->ccJson($svc->createForOrder($order, [
                'service_type'  => (string)($p['service_type'] ?? 'next_day'),
                'parcel_count'  => (int)($p['parcel_count'] ?? 1),
                'return_option' => (string)($p['return_option'] ?? 'none'),
                'boxnow'        => !empty($p['boxnow']),
                'locker_id'     => $this->ccText($p['locker_id'] ?? ''),
                'locker_code'   => $this->ccText($p['locker_code'] ?? ''),
                'locker_name'   => $this->ccText($p['locker_name'] ?? ''),
                'pickup_point'  => trim($this->ccText($p['pickup_point'] ?? '')),
                'mode'          => 'manual',
            ]));
        } catch (\Throwable $e) {
            $this->ccJson(['success' => false, 'error' => 'Σφάλμα: ' . $e->getMessage()]);
        }
    }

    /** Bulk create (Next Day): BOX NOW is detected per order from its checkout choice. POST order_ids, pickup_point. */
    public function bulkCreate(): void {
        @set_time_limit(0);
        if (!$this->ccCanModify()) {
            $this->ccDeny();
            return;
        }
        $ids = $this->parseOrderIds((string)($this->request->post['order_ids'] ?? ''));
        if (!$ids) {
            $this->ccJson(['success' => false, 'error' => 'Δεν επιλέχθηκαν παραγγελίες']);
            return;
        }
        $svc = $this->ccService();
        $point_code = trim($this->ccText($this->request->post['pickup_point'] ?? ''));
        if ($point_code !== '' && $svc->points()->get($point_code) === null) {
            $this->ccJson(['success' => false, 'error' => 'Το σημείο αποστολής ' . $point_code . ' δεν υπάρχει πια στις ρυθμίσεις. Ανανέωσε τη σελίδα.']);
            return;
        }
        $c = ['success' => 0, 'failed' => 0, 'skipped' => 0, 'boxnow' => 0, 'skipped_unmanaged' => 0, 'errors' => []];
        foreach ($ids as $order_id) {
            if (CCVoucherService::isActive($svc->getShipment($order_id))) {
                $c['skipped']++;
                continue;
            }
            $order = $this->loadOrder($order_id);
            if (!$order) {
                $c['failed']++;
                $c['errors'][] = '#' . $order_id . ': Παραγγελία δε βρέθηκε';
                continue;
            }
            if (!$svc->isHandledOrder($order)) {
                $c['skipped_unmanaged']++;
                continue;
            }
            $bn = CCVoucherService::boxnowFromOrder($order);
            try {
                $res = $svc->createForOrder($order, array_merge(['service_type' => 'next_day', 'mode' => 'bulk', 'pickup_point' => $point_code], $bn));
            } catch (\Throwable $e) {
                $res = ['success' => false, 'error' => $e->getMessage()];
            }
            if (!empty($res['success'])) {
                $c['success']++;
                if ($bn) {
                    $c['boxnow']++;
                }
            } elseif (($res['stage'] ?? '') === 'exists') {
                $c['skipped']++;
            } else {
                $c['failed']++;
                $c['errors'][] = '#' . $order_id . ': ' . ($res['error'] ?? 'Άγνωστο σφάλμα');
            }
            usleep(250000);
        }
        $point = $svc->points()->get($point_code !== '' ? $point_code : $svc->points()->defaultCode());
        $msg = 'Σύνολο: ' . count($ids) . ' | ✅ Δημιουργήθηκαν: ' . $c['success'] . ($c['boxnow'] ? ' (από αυτά BOX NOW: ' . $c['boxnow'] . ')' : '');
        if ($svc->points()->hasSubs() && $point) {
            $msg .= ' | 📍 Σημείο: ' . CCPickupPoints::label($point);
        }
        if ($c['skipped']) {
            $msg .= ' | ⏭️ Έχουν ήδη voucher: ' . $c['skipped'];
        }
        if ($c['skipped_unmanaged']) {
            $msg .= ' | 🚫 Μη διαχειρίσιμη μέθοδος: ' . $c['skipped_unmanaged'];
        }
        if ($c['failed']) {
            $msg .= ' | ❌ Απέτυχαν: ' . $c['failed'];
        }
        if ($c['errors']) {
            $msg .= "\n\n" . implode("\n", array_slice($c['errors'], 0, 15));
        }
        $this->ccJson(['success' => true, 'message' => $msg]);
    }

    public function bulkStatus(): void {
        @set_time_limit(0);
        if (!$this->ccCanModify()) {
            $this->ccDeny();
            return;
        }
        $ids = $this->parseOrderIds((string)($this->request->post['order_ids'] ?? ''));
        if (!$ids) {
            $this->ccJson(['success' => false, 'error' => 'Δεν επιλέχθηκαν παραγγελίες']);
            return;
        }
        $svc = $this->ccService();
        $tracker = $this->ccTracker();
        $updated = $skipped = $recent = $failed = 0;
        $errors = [];
        foreach ($ids as $order_id) {
            $shipment = $svc->getShipment($order_id);
            if (!CCVoucherService::isActive($shipment)) {
                $skipped++;
                continue;
            }
            if (CCStatusTracker::wait_minutes($shipment) > 0) {
                $recent++; // same 60-minute limit as the order panel and the cron
                continue;
            }
            $r = $tracker->refresh($shipment, 'Bulk');
            if ($r['success']) {
                $updated++;
            } else {
                $failed++;
                $errors[] = '#' . $order_id . ': ' . $r['error'];
            }
            usleep(300000);
        }
        $msg = 'Σύνολο: ' . count($ids) . ' | 🔄 Ενημερώθηκαν: ' . $updated;
        if ($skipped) {
            $msg .= ' | ⏭️ Χωρίς voucher: ' . $skipped;
        }
        if ($recent) {
            $msg .= ' | ⏱️ Ενημερώθηκαν την τελευταία ώρα: ' . $recent;
        }
        if ($failed) {
            $msg .= ' | ❌ Απέτυχαν: ' . $failed;
        }
        if ($errors) {
            $msg .= "\n\n" . implode("\n", array_slice($errors, 0, 15));
        }
        $this->ccJson(['success' => true, 'message' => $msg]);
    }

    public function voidShipment(): void {
        if (!$this->ccCanModify()) {
            $this->ccDeny();
            return;
        }
        $r = $this->ccService()->voidForOrder((int)($this->request->post['order_id'] ?? 0));
        $this->ccJson($r['success'] ? ['success' => true] : ['success' => false, 'error' => $r['error']]);
    }

    public function removeBoxNow(): void {
        if (!$this->ccCanModify()) {
            $this->ccDeny();
            return;
        }
        $order_id = (int)($this->request->post['order_id'] ?? 0);
        $shipment = $this->ccService()->getShipment($order_id);
        if (CCVoucherService::isActive($shipment)) {
            $this->ccJson(['success' => false, 'error' => 'Ακύρωσε πρώτα την αποστολή.']);
            return;
        }
        $q = $this->db->query("SELECT `custom_field` FROM `" . DB_PREFIX . "order` WHERE `order_id` = '" . $order_id . "' LIMIT 1");
        if ($q->num_rows) {
            $cf = json_decode((string)$q->row['custom_field'], true) ?: [];
            unset($cf['cc_locker_id'], $cf['cc_locker_name'], $cf['cc_locker_code'], $cf['cc_delivery_mode']);
            $this->db->query("UPDATE `" . DB_PREFIX . "order` SET `custom_field` = '" . $this->db->escape(json_encode($cf)) . "' WHERE `order_id` = '" . $order_id . "'");
        }
        if ($shipment) {
            $this->db->query("UPDATE `" . DB_PREFIX . "cc_shipments` SET `is_boxnow` = 0, `locker_id` = '', `locker_code` = '', `locker_name` = '', `boxnow_parcel_id` = '' WHERE `order_id` = '" . $order_id . "'");
        }
        $this->ccService()->history()->add($order_id, '↩️ Η επιλογή BOX NOW αφαιρέθηκε από τον merchant. Η παραγγελία θα αποσταλεί ως κανονική αποστολή.');
        $this->ccJson(['success' => true]);
    }

    public function updateStatus(): void {
        if (!$this->ccCanModify()) {
            $this->ccDeny();
            return;
        }
        $svc = $this->ccService();
        $shipment = $svc->getShipment((int)($this->request->post['order_id'] ?? 0));
        if (empty($shipment['voucher_number'])) {
            $this->ccJson(['success' => false, 'error' => 'Δεν υπάρχει αποστολή']);
            return;
        }
        $mins = CCStatusTracker::wait_minutes($shipment);
        if ($mins > 0) {
            $this->ccJson(['success' => false, 'error' => 'Η αποστολή ενημερώθηκε πρόσφατα. Ξαναδοκίμασε σε ' . $mins . ' λεπτά.']);
            return;
        }
        $r = $this->ccTracker()->refresh($shipment, '');
        $this->ccJson($r['success'] ? ['success' => true, 'code' => $r['code'], 'desc' => $r['desc']] : ['success' => false, 'error' => $r['error']]);
    }

    /** GET order_id [&type=return]. */
    public function downloadVoucher(): void {
        $svc = $this->ccService();
        $shipment = $svc->getShipment((int)($this->request->get['order_id'] ?? 0));
        if (empty($shipment['voucher_number'])) {
            $this->ccTextError(404, 'Δεν υπάρχει αποστολή για αυτή την παραγγελία.');
        }
        if (($this->request->get['type'] ?? '') === 'return') {
            if (empty($shipment['return_awb'])) {
                $this->ccTextError(404, 'Δεν υπάρχει επιστροφικό voucher για αυτή την παραγγελία.');
            }
            $awbs = [$shipment['return_awb']];
        } else {
            $awbs = [$shipment['voucher_number']];
            if (!empty($shipment['return_awb'])) {
                $awbs[] = $shipment['return_awb'];
            }
        }
        $this->ccSendPdf($this->fetchPdf($svc, $awbs, !empty($shipment['is_boxnow'])), 'voucher_' . $shipment['voucher_number'] . '.pdf');
    }

    /** GET order_ids: the normal vouchers of the selection (the list's print button opens BOX NOW separately). */
    public function bulkPrint(): void {
        $this->streamBulkPdf(false);
    }

    public function bulkPrintBoxnow(): void {
        $this->streamBulkPdf(true);
    }

    private function streamBulkPdf(bool $boxnow_only): void {
        $ids = $this->parseOrderIds((string)($this->request->get['order_ids'] ?? ''));
        if (!$ids) {
            $this->ccTextError(400, 'Δεν επιλέχθηκαν παραγγελίες.');
        }
        $svc = $this->ccService();
        $awbs = [];
        foreach ($ids as $order_id) {
            $s = $svc->getShipment($order_id);
            if (!CCVoucherService::isActive($s) || $boxnow_only !== !empty($s['is_boxnow'])) {
                continue;
            }
            $awbs[] = $s['voucher_number'];
            if (!empty($s['return_awb'])) {
                $awbs[] = $s['return_awb'];
            }
        }
        if (!$awbs) {
            $this->ccTextError(404, $boxnow_only ? 'Καμία από τις επιλεγμένες παραγγελίες δεν έχει ενεργό BOX NOW voucher.' : 'Καμία από τις επιλεγμένες παραγγελίες δεν έχει ενεργό voucher.');
        }
        $this->ccSendPdf($this->fetchPdf($svc, $awbs, $boxnow_only), 'vouchers-' . date('Y-m-d-His') . '.pdf');
    }

    /** Voucher PDF with the configured template; custom 4-up and 95% scaling. */
    private function fetchPdf(CCVoucherService $svc, array $awbs, bool $boxnow): string {
        $raw = $boxnow ? ((string)$svc->setting('print_template_boxnow') ?: 'singlepdf_100x150_4up') : ((string)$svc->setting('print_template') ?: 'pdf');
        $use_4up = ($raw === 'singlepdf_100x150_4up');
        $api_template = $use_4up ? 'singlepdf_100x150' : $raw;
        $pdf = $svc->api()->get_voucher_pdf(implode(',', $awbs), $api_template);
        if (is_array($pdf)) {
            $this->ccTextError(500, 'Σφάλμα voucher: ' . ($pdf['error'] ?? 'Άγνωστο σφάλμα'));
        }
        try {
            if ($use_4up) {
                $pdf = CCPdfScaler::arrange_4up($pdf);
            } elseif (in_array($api_template, ['pdf', 'clean'], true)) {
                $pdf = CCPdfScaler::scale_pdf($pdf);
            }
        } catch (\Throwable $e) {
            $this->log->write('Courier Center PDF post-processing (original PDF served): ' . $e->getMessage());
        }
        return $pdf;
    }

    private function loadOrder(int $order_id): array {
        if ($order_id <= 0) {
            return [];
        }
        $this->load->model('sale/order');
        $order = $this->model_sale_order->getOrder($order_id);
        return is_array($order) ? $order : [];
    }

    /** "1,2,3" -> unique positive ints */
    private function parseOrderIds(string $raw): array {
        $ids = [];
        foreach (explode(',', $raw) as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        return array_values($ids);
    }

    // ── Helpers ────────────────────────────────────────────────────────────
    private function validate(): bool {
        if (!$this->ccCanModify()) {
            $this->error['warning'] = $this->language->get('error_permission');
        }
        return !$this->error;
    }

    /**
     * Normalise the settings form before editSetting() (which replaces EVERY key of the extension):
     * secrets left empty keep the saved value, pickup points and returns are cleaned.
     * @return string[] warnings for the merchant
     */
    private function prepareSettingsPost(array &$post): array {
        $p = $this->prefix;
        $warnings = [];
        foreach (self::SECRET_FIELDS as $field) {
            if (trim((string)($post[$p . $field] ?? '')) === '') {
                $post[$p . $field] = (string)$this->config->get($p . $field);
            }
        }
        $url = trim($this->ccText($post[$p . 'tracking_url'] ?? ''));
        if ($url === '' || strpos($url, '{{tracking}}') === false) {
            if ($url !== '') {
                $warnings[] = 'Το Tracking URL πρέπει να περιέχει το {{tracking}}· μπήκε ξανά το προεπιλεγμένο.';
            }
            $post[$p . 'tracking_url'] = self::DEFAULT_TRACKING_URL;
        }
        $main = trim($this->ccText($post[$p . 'billing_account'] ?? ''));
        $clean = CCPickupPoints::sanitizeRows($post[$p . 'pickup_points'] ?? [], $main);
        $post[$p . 'pickup_points'] = $clean['rows'];
        $warnings = array_merge($warnings, $clean['errors']);
        $codes = [$main];
        foreach ($clean['rows'] as $row) {
            $codes[] = $row['code'];
        }
        $handled = CCVoucherService::sanitizeHandled($post[$p . 'handled_shipping_methods'] ?? [], $this->config->get($p . 'handled_shipping_methods'));
        $post[$p . 'handled_shipping_methods'] = $handled['codes'];
        if ($handled['warning'] !== '') {
            $warnings[] = $handled['warning'];
        }
        $default = trim($this->ccText($post[$p . 'default_pickup_point'] ?? ''));
        $post[$p . 'default_pickup_point'] = in_array($default, $codes, true) ? $default : '';
        return array_merge($warnings, CCReturnsSettings::sanitizePost($post, array_values(array_filter($codes, 'strlen'))));
    }

    private function ccContext(array $creds): array {
        return ['UserAlias' => $creds['user_alias'], 'CredentialValue' => $creds['credential_value'], 'ApiKey' => $creds['api_key']];
    }

    private function cronUrl(string $key): string {
        $base = defined('HTTP_CATALOG') ? HTTP_CATALOG : '';
        return rtrim($base, '/') . '/index.php?route=extension/couriercenter/shipping/courier_center_cron&key=' . $key;
    }

    /** Installed + enabled shipping extensions, with their own names. */
    private function shippingMethodsList(): array {
        $this->load->model('setting/extension');
        $list = [];
        foreach ($this->model_setting_extension->getExtensionsByType('shipping') as $ext) {
            if (!$this->config->get('shipping_' . $ext['code'] . '_status')) {
                continue;
            }
            $list[] = ['code' => $ext['code'], 'name' => ucwords(str_replace('_', ' ', $ext['code']))];
        }
        return $list;
    }

    /** Install/usage ping to the Courier Center dashboard, at most every 12 h. Never breaks anything. */
    private function ccSendPing(bool $force = false): void {
        try {
            $last = (int)CCOc4Setup::getState($this->db, DB_PREFIX, 'ping_sent_at');
            if (!$force && $last > 0 && (time() - $last) < 43200) {
                return;
            }
            $payload = [
                'site_url'         => (string)($this->config->get('config_url') ?: (defined('HTTP_CATALOG') ? HTTP_CATALOG : '')),
                'platform'         => 'OpenCart',
                'opencart_version' => defined('VERSION') ? VERSION : 'N/A',
                'plugin_version'   => CCOc4Setup::version(),
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
            CCOc4Setup::setState($this->db, DB_PREFIX, 'ping_sent_at', (string)time());
        } catch (\Throwable $e) {
            // Telemetry must never break anything.
        }
    }
}
