<?php
namespace Opencart\Admin\Controller\Extension\Couriercenter\Shipping;

require_once DIR_EXTENSION . 'couriercenter/library/oc4/loader.php';

use Opencart\Extension\Couriercenter\Library\CCDbSchema;
use Opencart\Extension\Couriercenter\Library\CCReturnsRules;
use Opencart\Extension\Couriercenter\Library\CCReturnsTracker;
use Opencart\Extension\Couriercenter\Library\Oc4\CCOc4Setup;

/**
 * Courier Center returns (admin): list page with filters, the actions of a request (approve, reject,
 * create / retry, verify, refresh tracking), the return voucher PDF and the Sales menu entry.
 */
class CourierCenterReturns extends \Opencart\System\Engine\Controller {
    use \Opencart\Extension\Couriercenter\Library\Oc4\CCOc4Controller;

    const ROUTE = 'extension/couriercenter/shipping/courier_center_returns';
    const PER_PAGE = 20;

    public function index(): void {
        CCDbSchema::ensureOnce($this->db, DB_PREFIX);
        $this->document->setTitle('Επιστροφές — Courier Center');
        $svc = $this->ccReturns();
        $svc->releaseStaleLocks();

        $status = (string)($this->request->get['filter_status'] ?? '');
        $method = (string)($this->request->get['filter_method'] ?? '');
        $search = trim($this->ccText($this->request->get['filter_search'] ?? ''));
        $page = max(1, (int)($this->request->get['page'] ?? 1));
        $args = ['status' => $status, 'method' => $method, 'search' => $search];
        $total = $svc->repo()->count($args);
        $rows = [];
        foreach ($svc->repo()->query($args + ['limit' => self::PER_PAGE, 'offset' => ($page - 1) * self::PER_PAGE]) as $r) {
            $order = $svc->getOrder((int)$r['order_id']);
            $rows[] = $this->ccReturnRow($r) + [
                'customer'  => trim($this->ccText(($order['firstname'] ?? '') . ' ' . ($order['lastname'] ?? ''))),
                'order_url' => $this->ccLink('sale/order.info', 'order_id=' . (int)$r['order_id']),
            ];
        }
        $counts = $svc->repo()->countByStatus();
        $statuses = [];
        foreach ([CCReturnsRules::ST_PENDING, CCReturnsRules::ST_APPROVED, CCReturnsRules::ST_CREATING, CCReturnsRules::ST_VERIFY, CCReturnsRules::ST_FAILED, CCReturnsRules::ST_CREATED, CCReturnsRules::ST_REJECTED] as $st) {
            $statuses[] = ['code' => $st, 'label' => CCReturnsRules::status_label($st), 'count' => $counts[$st] ?? 0];
        }
        $filter = 'filter_status=' . urlencode($status) . '&filter_method=' . urlencode($method) . '&filter_search=' . urlencode($search);
        $pages = (int)ceil($total / self::PER_PAGE);
        $pagination = [];
        for ($p = 1; $p <= $pages && $pages > 1; $p++) {
            $pagination[] = ['page' => $p, 'href' => $this->ccLink(self::ROUTE, $filter . '&page=' . $p), 'active' => $p === $page];
        }
        $data = [
            'rows'          => $rows,
            'statuses'      => $statuses,
            'methods'       => [CCReturnsRules::METHOD_DROPOFF => CCReturnsRules::method_label(CCReturnsRules::METHOD_DROPOFF), CCReturnsRules::METHOD_BOXNOW => CCReturnsRules::method_label(CCReturnsRules::METHOD_BOXNOW)],
            'filter_status' => $status,
            'filter_method' => $method,
            'filter_search' => $search,
            'total'         => $total,
            'pagination'    => $pagination,
            'enabled'       => $svc->settings()->enabled(),
            'can_modify'    => $this->ccCanModify(),
            'user_token'    => $this->session->data['user_token'],
            'route'         => self::ROUTE,
            'url_settings'  => $this->ccLink(CCOc4Setup::BASE_ROUTE),
        ];
        $data['returns_js'] = $this->load->view('extension/couriercenter/shipping/returns_js', ['urls' => $this->ccReturnActionUrls()]);
        $data['breadcrumbs'] = [
            ['text' => 'Αρχική', 'href' => $this->ccLink('common/dashboard')],
            ['text' => 'Courier Center', 'href' => $data['url_settings']],
            ['text' => 'Επιστροφές', 'href' => $this->ccLink(self::ROUTE)],
        ];
        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');
        $this->response->setOutput($this->load->view('extension/couriercenter/shipping/returns', $data));
    }

    public function approve(): void {
        $this->act(function ($svc, $id) {
            return $svc->approve($id, (int)$this->user->getId());
        });
    }

    public function reject(): void {
        $this->act(function ($svc, $id) {
            return $svc->reject($id, (int)$this->user->getId());
        });
    }

    public function create(): void {
        $this->act(function ($svc, $id) {
            return $svc->create($id);
        });
    }

    public function verify(): void {
        $this->act(function ($svc, $id) {
            return $svc->verify($id);
        });
    }

    public function track(): void {
        $this->act(function ($svc, $id) {
            return (new CCReturnsTracker($svc))->refresh($id);
        });
    }

    /** POST id → JSON {success, error, status_label}. */
    private function act(callable $fn): void {
        @set_time_limit(120);
        if (!$this->ccCanModify()) {
            $this->ccDeny();
            return;
        }
        $id = (int)($this->request->post['id'] ?? 0);
        $svc = $this->ccReturns();
        if ($id <= 0 || !$svc->repo()->get($id)) {
            $this->ccJson(['success' => false, 'error' => 'Το αίτημα επιστροφής δεν βρέθηκε.']);
            return;
        }
        $r = $fn($svc, $id);
        $row = $svc->repo()->get($id);
        $this->ccJson(['success' => !empty($r['ok']), 'error' => empty($r['ok']) ? (string)($r['error'] ?? 'Σφάλμα') : '',
            'status_label' => $row ? CCReturnsRules::status_label($row['status']) : '']);
    }

    /** GET id: the return voucher PDF. */
    public function voucher(): void {
        $svc = $this->ccReturns();
        $row = $svc->repo()->get((int)($this->request->get['id'] ?? 0));
        if (!$row || $row['return_awb'] === '') {
            $this->ccTextError(404, 'Δεν υπάρχει αποστολή επιστροφής.');
        }
        $pdf = $svc->api()->get_voucher_pdf($row['return_awb'], 'singlepdf_100x150');
        if (is_array($pdf)) {
            $this->ccTextError(500, 'Σφάλμα voucher: ' . ($pdf['error'] ?? 'Άγνωστο σφάλμα'));
        }
        $this->ccSendPdf($pdf, 'return_' . $row['return_awb'] . '.pdf');
    }

    /** Event admin/view/common/column_left/before: «Επιστροφές Courier Center» under Sales. */
    public function menu(string &$route, array &$args): void {
        try {
            if (!isset($args['menus']) || !is_array($args['menus']) || !$this->user->hasPermission('access', self::ROUTE)
                || (string)$this->config->get('shipping_courier_center_returns_enabled') !== '1') {
                return;
            }
            $n = 0;
            if ($this->db->query("SHOW TABLES LIKE '" . $this->db->escape(DB_PREFIX . 'cc_return_requests') . "'")->num_rows) {
                $q = $this->db->query("SELECT COUNT(*) AS n FROM `" . DB_PREFIX . "cc_return_requests` WHERE `status` IN ('" . CCReturnsRules::ST_PENDING . "','" . CCReturnsRules::ST_VERIFY . "','" . CCReturnsRules::ST_FAILED . "','" . CCReturnsRules::ST_APPROVED . "')");
                $n = (int)($q->row['n'] ?? 0);
            }
            $entry = ['name' => 'Επιστροφές Courier Center' . ($n ? ' (' . $n . ')' : ''), 'href' => $this->ccLink(self::ROUTE), 'children' => []];
            foreach ($args['menus'] as $i => $m) {
                if (($m['id'] ?? '') === 'menu-sale') {
                    $args['menus'][$i]['children'][] = $entry;
                    return;
                }
            }
        } catch (\Throwable $e) {
            $this->log->write('Courier Center returns menu: ' . $e->getMessage());
        }
    }
}
