<?php
namespace Opencart\Admin\Controller\Extension\Couriercenter\Shipping;

require_once DIR_EXTENSION . 'couriercenter/library/oc4/loader.php';

use Opencart\Extension\Couriercenter\Library\CCDbSchema;
use Opencart\Extension\Couriercenter\Library\CCReturnsRules;
use Opencart\Extension\Couriercenter\Library\CCReturnsTracker;
use Opencart\Extension\Couriercenter\Library\Oc4\CCOc4Setup;

/**
 * Courier Center returns (admin): list page with filters, the actions of a request (approve, reject,
 * create / retry, verify, refresh tracking), the return voucher PDF, «Νέα επιστροφή» of the shop (add),
 * the Sales menu entry with the counter and the admin notice.
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
            'methods'       => array_combine(CCReturnsRules::METHODS_ORDER, array_map([CCReturnsRules::class, 'method_label'], CCReturnsRules::METHODS_ORDER)),
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

    /** «Νέα επιστροφή» of the shop for one order (GET form, POST create). */
    public function add(): void {
        CCDbSchema::ensureOnce($this->db, DB_PREFIX);
        $order_id = (int)($this->request->get['order_id'] ?? 0);
        $svc = $this->ccReturns();
        $order = $svc->getOrder($order_id);
        $order_url = $this->ccLink('sale/order.info', 'order_id=' . $order_id);
        $error = '';
        $posted = [];
        if ($this->request->server['REQUEST_METHOD'] === 'POST' && $order) {
            if (!$this->ccCanModify()) {
                $error = 'Δεν έχετε δικαίωμα τροποποίησης του Courier Center.';
            } else {
                $posted = $this->request->post;
                $items = [];
                foreach ((array)($posted['qty'] ?? []) as $iid => $qty) {
                    if (is_scalar($qty) && (int)$qty > 0) {
                        $items[(int)$iid] = trim((string)$qty);
                    }
                }
                @set_time_limit(120);
                $r = $svc->shopCreate($order, (int)$this->user->getId(), [
                    'request_uid' => (string)($posted['request_uid'] ?? ''),
                    'method'      => (string)($posted['method'] ?? ''),
                    'items'       => $items,
                    'reason'      => $this->ccText($posted['reason'] ?? ''),
                    'name'        => $this->ccText($posted['name'] ?? ''),
                    'mobile'      => $this->ccText($posted['mobile'] ?? ''),
                    'landline'    => $this->ccText($posted['landline'] ?? ''),
                    'locker_size' => (string)($posted['locker_size'] ?? ''),
                    'notify'      => !empty($posted['notify']) ? '1' : '',
                ] + $this->ccPickupPost($posted));
                if ($r['ok']) {
                    $row = $svc->repo()->get((int)$r['id']);
                    if ($row && $row['status'] === CCReturnsRules::ST_CREATED) {
                        $msg = sprintf('Η επιστροφή #%d δημιουργήθηκε. Αριθμός αποστολής επιστροφής: %s.', $r['id'], $row['return_awb'])
                            . (!empty($row['notify_customer']) ? ' Ο πελάτης ενημερώνεται με email.' : '');
                        $this->session->data['cc_return_notice'] = ['type' => 'success', 'text' => $msg];
                    } else {
                        $err = $row && trim((string)$row['last_error']) !== '' ? $row['last_error'] : CCReturnsRules::status_label($row ? $row['status'] : '');
                        $this->session->data['cc_return_notice'] = ['type' => 'warning',
                            'text' => sprintf('Η επιστροφή #%d καταχωρήθηκε, αλλά η δημιουργία της αποστολής δεν ολοκληρώθηκε: %s', $r['id'], $err)];
                    }
                    $this->response->redirect($order_url);
                    return;
                }
                $error = $r['error'];
                if ($r['code'] === 'forbidden') {
                    unset($posted['request_uid']);
                }
            }
        }
        $data = ['error' => $error, 'order_id' => $order_id, 'order_url' => $order_url, 'found' => (bool)$order, 'active' => false];
        if ($order) {
            $data['active'] = $svc->hasActiveAwb($order_id);
            $ship = $this->db->query("SELECT `voucher_number` FROM `" . DB_PREFIX . "cc_shipments` WHERE `order_id` = '" . $order_id . "' LIMIT 1");
            $defaults = $svc->customerDefaults($order);
            $items = [];
            foreach ($svc->availableItems($order) as $iid => $it) {
                $items[] = ['id' => $iid, 'name' => $it['name'], 'model' => $it['model'], 'ordered' => $it['ordered'], 'available' => $it['available'],
                    'value' => isset($posted['qty'][$iid]) ? (int)$posted['qty'][$iid] : 0];
            }
            $sizes = [];
            foreach (CCReturnsRules::LOCKER_SIZES as $code => $sz) {
                $sizes[] = ['code' => $code, 'label' => $sz['label'], 'dims' => $sz['dims']];
            }
            $methods = [];
            foreach (CCReturnsRules::METHODS_ORDER as $m) {
                $methods[] = ['code' => $m, 'label' => CCReturnsRules::method_label($m)];
            }
            $method = (string)($posted['method'] ?? '');
            $data += [
                'awb'         => $ship->num_rows ? (string)$ship->row['voucher_number'] : '',
                'email'       => (string)($order['email'] ?? ''),
                'action'      => $this->ccLink(self::ROUTE . '.add', 'order_id=' . $order_id),
                'request_uid' => preg_match('/^[A-Za-z0-9_-]{8,32}$/', (string)($posted['request_uid'] ?? '')) ? (string)$posted['request_uid'] : bin2hex(random_bytes(12)),
                'items'       => $items,
                'methods'     => $methods,
                'method'      => in_array($method, CCReturnsRules::METHODS_ORDER, true) ? $method : CCReturnsRules::METHOD_DROPOFF,
                'sizes'       => $sizes,
                'locker_size' => (string)($posted['locker_size'] ?? ''),
                'name'        => $posted ? $this->ccText($posted['name'] ?? '') : $defaults['name'],
                'mobile'      => $posted ? $this->ccText($posted['mobile'] ?? '') : $defaults['mobile'],
                'landline'    => $posted ? $this->ccText($posted['landline'] ?? '') : $defaults['landline'],
                'reason'      => $this->ccText($posted['reason'] ?? ''),
                'notify'      => $posted ? !empty($posted['notify']) : true,
                'boxnow'      => CCReturnsRules::METHOD_BOXNOW,
                'msg_phone'   => CCReturnsRules::MSG_PHONE,
                'msg_mobile'  => CCReturnsRules::MSG_MOBILE,
                'can_modify'  => $this->ccCanModify(),
            ] + $this->ccPickupView($posted, $defaults);
        }
        $this->document->setTitle('Νέα επιστροφή — Courier Center');
        $data['breadcrumbs'] = [
            ['text' => 'Αρχική', 'href' => $this->ccLink('common/dashboard')],
            ['text' => 'Επιστροφές Courier Center', 'href' => $this->ccLink(self::ROUTE)],
            ['text' => 'Νέα επιστροφή #' . $order_id, 'href' => $this->ccLink(self::ROUTE . '.add', 'order_id=' . $order_id)],
        ];
        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');
        $this->response->setOutput($this->load->view('extension/couriercenter/shipping/returns_add', $data));
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
            if (!isset($args['menus']) || !is_array($args['menus']) || !$this->user->hasPermission('access', CCOc4Setup::BASE_ROUTE)) {
                return;
            }
            $this->ccRememberAdminUrl();
            $n = $this->pendingCount();
            // Sales → Courier Center → Manifest / Returns (with the count) / Settings / Guide / Bug report:
            // the submenu of the WooCommerce plugin (each entry only if the user may open it).
            $items = [
                ['Manifest Παραλαβής', CCOc4Setup::BASE_ROUTE . '_manifest'],
                ['Επιστροφές' . ($n ? ' (' . $n . ')' : ''), self::ROUTE],
                ['Ρυθμίσεις', CCOc4Setup::BASE_ROUTE],
                ['Οδηγίες', CCOc4Setup::BASE_ROUTE . '_docs'],
                ['Αναφορά Προβλήματος', CCOc4Setup::BASE_ROUTE . '_bug'],
            ];
            $children = [];
            foreach ($items as [$item_name, $item_route]) { // (not $route: it is the event's by-reference route)
                if ($this->user->hasPermission('access', $item_route)) {
                    $children[] = ['name' => $item_name, 'href' => $this->ccLink($item_route), 'children' => []];
                }
            }
            $entry = ['name' => 'Courier Center' . ($n ? ' (' . $n . ')' : ''), 'href' => '', 'children' => $children];
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

    /** Requests that need action (0 without the table). Cached per request (menu + notice). */
    private function pendingCount(): int {
        static $n = null;
        if ($n === null) {
            $n = 0;
            if ($this->db->query("SHOW TABLES LIKE '" . $this->db->escape(DB_PREFIX . 'cc_return_requests') . "'")->num_rows) {
                $n = (new \Opencart\Extension\Couriercenter\Library\CCReturnsRepo($this->db, DB_PREFIX))->pendingCount();
            }
        }
        return $n;
    }

    /**
     * Event admin/view/common/dashboard/after, sale/order/after, sale/order_info/after:
     * «Υπάρχουν N αιτήματα επιστροφής που χρειάζονται ενέργεια» above the page content.
     */
    public function notice(string &$route, array &$args, mixed &$output): void {
        if (!is_string($output) || $output === '') {
            return;
        }
        try {
            if (!$this->user->hasPermission('access', self::ROUTE) || (string)$this->config->get('shipping_courier_center_returns_enabled') !== '1') {
                return;
            }
            $n = $this->pendingCount();
            if ($n < 1 || strpos($output, 'id="cc-returns-notice"') !== false) {
                return;
            }
            $text = $n === 1 ? 'Υπάρχει 1 αίτημα επιστροφής που χρειάζεται ενέργεια.' : sprintf('Υπάρχουν %d αιτήματα επιστροφής που χρειάζονται ενέργεια.', $n);
            $html = '<div class="alert alert-warning" id="cc-returns-notice"><i class="fa-solid fa-rotate-left"></i> ' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8')
                . ' <a href="' . htmlspecialchars($this->ccLink(self::ROUTE), ENT_QUOTES, 'UTF-8') . '">Δες τα αιτήματα</a></div>';
            $marker = '<div class="container-fluid">';
            $head = strpos($output, '<div class="page-header">');
            $first = $head === false ? false : strpos($output, $marker, $head);
            $pos = $first === false ? false : strpos($output, $marker, $first + strlen($marker));
            if ($pos !== false) {
                $pos += strlen($marker);
                $output = substr($output, 0, $pos) . $html . substr($output, $pos);
            }
        } catch (\Throwable $e) {
            $this->log->write('Courier Center returns notice: ' . $e->getMessage());
        }
    }
}
