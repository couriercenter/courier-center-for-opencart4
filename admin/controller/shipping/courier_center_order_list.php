<?php
namespace Opencart\Admin\Controller\Extension\Couriercenter\Shipping;

require_once DIR_EXTENSION . 'couriercenter/library/oc4/loader.php';

use Opencart\Extension\Couriercenter\Library\CCDbSchema;
use Opencart\Extension\Couriercenter\Library\CCPickupPoints;
use Opencart\Extension\Couriercenter\Library\CCVoucherService;
use Opencart\Extension\Couriercenter\Library\Oc4\CCOc4Setup;

/**
 * Courier Center on the admin order list (event admin/view/sale/order_list/after, also on every AJAX reload of the
 * list): Manifest / Returns buttons, bulk bar, Method / Type / CC Voucher / CC Status columns. Values go in with textContent.
 */
class CourierCenterOrderList extends \Opencart\System\Engine\Controller {
    use \Opencart\Extension\Couriercenter\Library\Oc4\CCOc4Controller;

    public function injectColumns(string &$route, array &$args, mixed &$output): void {
        if (!is_string($output) || $output === '') {
            return;
        }
        try {
            CCDbSchema::ensureOnce($this->db, DB_PREFIX);
            preg_match_all('/name="selected\[\]"\s+value="(\d+)"/', $output, $m);
            $ids = array_values(array_unique(array_map('intval', $m[1])));
            $svc = $this->ccService();
            $shipments = [];
            $methods = [];
            $returns = [];
            if ($ids) {
                $list = implode(',', $ids);
                foreach ($this->db->query("SELECT * FROM `" . DB_PREFIX . "cc_shipments` WHERE `order_id` IN (" . $list . ")")->rows as $row) {
                    $shipments[(string)$row['order_id']] = [
                        'voucher_number'  => $this->ccText($row['voucher_number']),
                        'tracking_number' => $this->ccText($row['tracking_number']),
                        'status_code'     => (string)$row['status_code'],
                        'status_desc'     => $this->ccText($row['status_desc']),
                        'is_voided'       => (int)$row['is_voided'],
                        'is_boxnow'       => (int)$row['is_boxnow'],
                        'parcel_id'       => $this->ccText($row['boxnow_parcel_id'] ?? ''),
                    ];
                }
                foreach ($this->db->query("SELECT `order_id`, `shipping_method` FROM `" . DB_PREFIX . "order` WHERE `order_id` IN (" . $list . ")")->rows as $row) {
                    $methods[(string)$row['order_id']] = ['name' => CCVoucherService::shippingName($row), 'managed' => $svc->isHandledOrder($row)];
                }
                // «Επιστροφή»: status of the newest return request (+N when there are more)
                foreach ((new \Opencart\Extension\Couriercenter\Library\CCReturnsRepo($this->db, DB_PREFIX))->summaryForOrders($ids) as $id => $r) {
                    $returns[(string)$id] = \Opencart\Extension\Couriercenter\Library\CCReturnsRules::status_label($r['status']) . ($r['count'] > 1 ? ' (+' . ($r['count'] - 1) . ')' : '');
                }
            }
            $points = [];
            if ($svc->points()->hasSubs()) {
                foreach ($svc->points()->all() as $pt) {
                    $points[] = ['code' => $pt['code'], 'label' => CCPickupPoints::label($pt)];
                }
            }
            $tracking = (string)$svc->setting('tracking_url');
            $base = CCOc4Setup::BASE_ROUTE . '.';
            $output .= $this->load->view('extension/couriercenter/shipping/order_list_inject', [
                'shipments'     => (object)$shipments,
                'methods'       => (object)$methods,
                'returns'       => (object)$returns,
                'tracking_url'  => $tracking !== '' ? $tracking : 'https://www.courier.gr/track/result?tracknr={{tracking}}',
                'points'        => $points,
                'default_point' => $svc->points()->defaultCode(),
                'urls'          => [
                    'create'  => $this->ccLink($base . 'bulkCreate'),
                    'status'  => $this->ccLink($base . 'bulkStatus'),
                    'print'   => $this->ccLink($base . 'bulkPrint'),
                    'printbn' => $this->ccLink($base . 'bulkPrintBoxnow'),
                ],
                'url_manifest'  => $this->ccLink(CCOc4Setup::BASE_ROUTE . '_manifest'),
                'url_returns'   => $this->ccLink(CCOc4Setup::BASE_ROUTE . '_returns'),
            ]);
        } catch (\Throwable $e) {
            $this->log->write('Courier Center order list: ' . $e->getMessage());
        }
    }
}
