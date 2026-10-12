<?php
namespace Opencart\Admin\Controller\Extension\Couriercenter\Shipping;

require_once DIR_EXTENSION . 'couriercenter/library/oc4/loader.php';

use Opencart\Extension\Couriercenter\Library\CCDbSchema;
use Opencart\Extension\Couriercenter\Library\CCOrderAdapter;
use Opencart\Extension\Couriercenter\Library\CCPickupPoints;
use Opencart\Extension\Couriercenter\Library\CCVoucherService;
use Opencart\Extension\Couriercenter\Library\Oc4\CCOc4Setup;

/**
 * "Courier Center" tab of the admin order page (event admin/view/sale/order_info/after).
 * Voucher actions live in courier_center.php; returns actions in courier_center_returns.php.
 */
class CourierCenterOrder extends \Opencart\System\Engine\Controller {
    use \Opencart\Extension\Couriercenter\Library\Oc4\CCOc4Controller;

    public function orderPanel(string &$route, array &$args, mixed &$output): void {
        $order_id = (int)($this->request->get['order_id'] ?? 0);
        if ($order_id <= 0 || !is_string($output)) {
            return;
        }
        try {
            CCDbSchema::ensureOnce($this->db, DB_PREFIX);
            $svc = $this->ccService();
            $shipment = [];
            foreach ($svc->getShipment($order_id) as $k => $v) {
                $shipment[$k] = $this->ccText($v);
            }
            $q = $this->db->query("SELECT `custom_field` FROM `" . DB_PREFIX . "order` WHERE `order_id` = '" . $order_id . "' LIMIT 1");
            $cf = $q->num_rows ? (json_decode((string)$q->row['custom_field'], true) ?: []) : [];

            $status = (string)($shipment['status_code'] ?? '');
            $status_class = '';
            if (in_array($status, ['29', '87'], true)) {
                $status_class = 'cc-delivered';
            } elseif (in_array($status, ['28', '30'], true)) {
                $status_class = 'cc-failed';
            } elseif (in_array($status, ['25', '99', '14'], true)) {
                $status_class = 'cc-returned';
            }

            // Pickup points: the select appears only with sub-codes; after a void the old point is preselected.
            $points = $svc->points();
            $point_options = [];
            foreach ($points->all() as $pt) {
                $point_options[] = ['code' => $pt['code'], 'label' => CCPickupPoints::label($pt)];
            }
            $current = (string)($shipment['pickup_point'] ?? '');
            $selected = ($current !== '' && $points->get($current)) ? $current : $points->defaultCode();
            $point_info = '';
            if ($points->hasSubs() && $current !== '') {
                $pt = $points->get($current);
                $point_info = $pt ? CCPickupPoints::label($pt) : $current;
                if (!empty($shipment['requestor_account']) && $shipment['requestor_account'] !== $current) {
                    $point_info .= ' — Χρέωση: ' . $shipment['requestor_account'];
                }
            }

            // Consignee preview (shipping address, per-field fallback to the payment address)
            $this->load->model('sale/order');
            $order = $this->model_sale_order->getOrder($order_id);
            $consignee = '';
            if ($order) {
                // The address exactly as the voucher will carry it (with the separate street number, if configured).
                $a = new CCOrderAdapter($order);
                $b = new \Opencart\Extension\Couriercenter\Library\CCShipmentBuilder($a, $this->ccService()->builderSettings());
                $consignee = trim($a->get_billing_first_name() . ' ' . $a->get_billing_last_name()) . ', ' . $b->get_consignee_address_preview() . ' · ' . $a->get_billing_phone();
            }

            $returns = [];
            $svc_ret = $this->ccReturns();
            foreach ($svc_ret->repo()->forOrder($order_id) as $r) {
                $returns[] = $this->ccReturnRow($r);
            }
            $return_notice = $this->session->data['cc_return_notice'] ?? null;
            unset($this->session->data['cc_return_notice']);
            $base = CCOc4Setup::BASE_ROUTE . '.';
            $return_labels = ['optional' => 'Προαιρετικό', 'mandatory' => 'Υποχρεωτικό'];
            $data = [
                'order_id'            => $order_id,
                'shipment'            => $shipment,
                'has_active'          => CCVoucherService::isActive($shipment),
                'was_voided'          => !empty($shipment['voucher_number']) && !empty($shipment['is_voided']),
                'status_class'        => $status_class,
                'service_label'       => CCVoucherService::serviceLabel((string)($shipment['service_type'] ?? '')),
                'return_label'        => $return_labels[$shipment['return_option'] ?? ''] ?? '',
                'locker_id'           => $this->ccText($cf['cc_locker_id'] ?? ''),
                'locker_code'         => $this->ccText($cf['cc_locker_code'] ?? ''),
                'locker_name'         => $this->ccText($cf['cc_locker_name'] ?? ''),
                'delivery_mode'       => (string)($cf['cc_delivery_mode'] ?? ''),
                'has_points'          => $points->hasSubs(),
                'point_options'       => $point_options,
                'selected_point'      => $selected,
                'point_info'          => $point_info,
                'consignee'           => $this->ccText($consignee),
                'returns'             => $returns,
                'can_add_return'      => $svc_ret->hasActiveAwb($order_id) && $this->ccCanModify(),
                'url_add_return'      => $this->ccLink(CCOc4Setup::BASE_ROUTE . '_returns.add', 'order_id=' . $order_id),
                'return_notice'       => $return_notice,
                'urls'                => [
                    'create'       => $this->ccLink($base . 'createVoucher'),
                    'void'         => $this->ccLink($base . 'voidShipment'),
                    'status'       => $this->ccLink($base . 'updateStatus'),
                    'removeBoxnow' => $this->ccLink($base . 'removeBoxNow'),
                ],
                'url_download'        => $this->ccLink($base . 'downloadVoucher', 'order_id=' . $order_id),
                'url_download_return' => $this->ccLink($base . 'downloadVoucher', 'order_id=' . $order_id . '&type=return'),
            ];
            $data['returns_js'] = $returns ? $this->load->view('extension/couriercenter/shipping/returns_js', ['urls' => $this->ccReturnActionUrls()]) : '';
            $output .= $this->load->view('extension/couriercenter/shipping/order_panel', $data);
        } catch (\Throwable $e) {
            $this->log->write('Courier Center order panel: ' . $e->getMessage());
        }
    }
}
