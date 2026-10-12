<?php
namespace Opencart\Catalog\Controller\Extension\Couriercenter\Shipping;

require_once DIR_EXTENSION . 'couriercenter/library/oc4/loader.php';

use Opencart\Extension\Couriercenter\Library\CCReturnsRules;

/**
 * Customer returns (storefront): the «Επιστροφές» block on account/order.info (event
 * catalog/view/account/order_info/after) and the return request form (customer_token required, like the account pages).
 */
class CourierCenterReturns extends \Opencart\System\Engine\Controller {
    use \Opencart\Extension\Couriercenter\Library\Oc4\CCOc4Controller;

    const ROUTE = 'extension/couriercenter/shipping/courier_center_returns';

    public function orderInfo(string &$route, array &$args, mixed &$output): void {
        if (!is_string($output) || $output === '' || !$this->customer->isLogged()) {
            return;
        }
        try {
            if ((string)$this->config->get('shipping_courier_center_returns_enabled') !== '1') {
                return;
            }
            $order_id = (int)($args['order_id'] ?? ($this->request->get['order_id'] ?? 0));
            $svc = $this->ccReturns();
            $order = $order_id > 0 ? $svc->getOrder($order_id) : [];
            $customer_id = (int)$this->customer->getId();
            if (!$order || (int)$order['customer_id'] !== $customer_id) {
                return;
            }
            $requests = [];
            foreach ($svc->repo()->forOrder($order_id) as $r) {
                $requests[] = $this->customerRow($r, $svc);
            }
            $can_request = false;
            if ($svc->checkEligibility($order, $customer_id)['ok']) {
                foreach ($svc->availableItems($order) as $it) {
                    if ($it['available'] > 0) {
                        $can_request = true;
                        break;
                    }
                }
            }
            if (!$requests && !$can_request) {
                return;
            }
            $flash = (string)($this->session->data['cc_return_success'] ?? '');
            unset($this->session->data['cc_return_success']);
            $html = $this->load->view('extension/couriercenter/shipping/returns_block', [
                'requests'    => $requests,
                'can_request' => $can_request,
                'url_request' => $this->link(self::ROUTE, $order_id),
                'flash'       => $flash,
            ]);
            $pos = strrpos($output, '<div class="text-end mt-3">');
            if ($pos === false) {
                $pos = strripos($output, '<footer');
            }
            $output = $pos === false ? $output . $html : substr($output, 0, $pos) . $html . substr($output, $pos);
        } catch (\Throwable $e) {
            $this->log->write('Courier Center returns (account): ' . $e->getMessage());
        }
    }

    /**
     * Event catalog/view/account/order_list/after: «Αίτημα Επιστροφής» next to «View» of every order the customer
     * can return (completed, active voucher, inside the deadline, items left), like the WooCommerce orders list.
     */
    public function orderList(string &$route, array &$args, mixed &$output): void {
        if (!is_string($output) || $output === '' || !$this->customer->isLogged()) {
            return;
        }
        try {
            if ((string)$this->config->get('shipping_courier_center_returns_enabled') !== '1') {
                return;
            }
            if (!preg_match_all('#<a\b[^>]*href="[^"]*account/order[^"]*order_id=(\d+)[^"]*"[^>]*>.*?</a>#s', $output, $hits, PREG_SET_ORDER)) {
                return;
            }
            $svc = $this->ccReturns();
            $customer_id = (int)$this->customer->getId();
            $done = [];
            foreach ($hits as $hit) {
                $order_id = (int)$hit[1];
                if (isset($done[$order_id])) {
                    continue;
                }
                $done[$order_id] = true;
                $order = $svc->getOrder($order_id);
                if (!$order || !$svc->checkEligibility($order, $customer_id)['ok']) {
                    continue;
                }
                $left = false;
                foreach ($svc->availableItems($order) as $it) {
                    if ($it['available'] > 0) {
                        $left = true;
                        break;
                    }
                }
                if ($left) {
                    $button = ' <a href="' . htmlspecialchars($this->link(self::ROUTE, $order_id), ENT_QUOTES, 'UTF-8') . '" data-bs-toggle="tooltip" title="Αίτημα Επιστροφής" class="btn btn-light cc-return-btn"><i class="fa-solid fa-rotate-left"></i> Αίτημα Επιστροφής</a>';
                    $output = str_replace($hit[0], $hit[0] . $button, $output);
                }
            }
        } catch (\Throwable $e) {
            $this->log->write('Courier Center returns (orders list): ' . $e->getMessage());
        }
    }

    /** The return request form (GET) and its submission (POST). */
    public function index(): void {
        $order_id = (int)($this->request->get['order_id'] ?? 0);
        $token = (string)($this->request->get['customer_token'] ?? '');
        if (!$this->customer->isLogged() || !isset($this->session->data['customer_token']) || !hash_equals((string)$this->session->data['customer_token'], $token)) {
            $this->session->data['redirect'] = $this->ccCatalogLink(self::ROUTE, 'language=' . $this->config->get('config_language') . '&order_id=' . $order_id);
            $this->response->redirect($this->ccCatalogLink('account/login', 'language=' . $this->config->get('config_language')));
            return;
        }
        $back = $this->link('account/order.info', $order_id);
        $svc = $this->ccReturns();
        $order = $svc->getOrder($order_id);
        $customer_id = (int)$this->customer->getId();
        $ok = $svc->checkEligibility($order, $customer_id);
        if (!$ok['ok']) {
            $this->renderPage(['error' => $ok['error'], 'blocked' => true, 'back' => $back, 'order_id' => $order_id]);
            return;
        }
        $error = '';
        $posted = [];
        if ($this->request->server['REQUEST_METHOD'] === 'POST') {
            $posted = $this->request->post;
            $items = [];
            foreach ((array)($posted['qty'] ?? []) as $iid => $qty) {
                $items[(int)$iid] = is_scalar($qty) ? trim((string)$qty) : '';
            }
            $r = $svc->submit($order, $customer_id, [
                'request_uid' => (string)($posted['request_uid'] ?? ''),
                'method'      => (string)($posted['method'] ?? ''),
                'items'       => $items,
                'reason'      => $this->ccText($posted['reason'] ?? ''),
                'name'        => $this->ccText($posted['name'] ?? ''),
                'mobile'      => $this->ccText($posted['mobile'] ?? ''),
                'landline'    => $this->ccText($posted['landline'] ?? ''),
                'locker_size' => (string)($posted['locker_size'] ?? ''),
            ] + $this->ccPickupPost($posted));
            if ($r['ok']) {
                $row = $svc->repo()->get((int)$r['id']);
                $this->session->data['cc_return_success'] = ($row && $row['status'] === CCReturnsRules::ST_CREATED)
                    ? 'Η επιστροφή σας δημιουργήθηκε επιτυχώς!'
                    : 'Το αίτημα επιστροφής καταχωρίστηκε. Θα ενημερωθείτε με email για την εξέλιξή του.';
                $this->response->redirect($back);
                return;
            }
            $error = $r['error'];
        }
        $methods = $svc->settings()->enabledMethods();
        $defaults = $svc->customerDefaults($order);
        $sizes = [];
        foreach (CCReturnsRules::LOCKER_SIZES as $code => $sz) {
            $sizes[] = ['code' => $code, 'label' => $sz['label'], 'dims' => $sz['dims']];
        }
        $items = [];
        foreach ($svc->availableItems($order) as $iid => $it) {
            $items[] = ['id' => $iid, 'name' => $it['name'], 'model' => $it['model'], 'ordered' => $it['ordered'], 'available' => $it['available'],
                'value' => isset($posted['qty'][$iid]) ? (int)$posted['qty'][$iid] : 0];
        }
        $method_list = [];
        foreach ($methods as $m) {
            $method_list[] = ['code' => $m, 'label' => CCReturnsRules::method_label($m)];
        }
        $this->renderPage([
            'error'       => $error,
            'blocked'     => false,
            'back'        => $back,
            'order_id'    => $order_id,
            'action'      => $this->link(self::ROUTE, $order_id),
            'request_uid' => preg_match('/^[A-Za-z0-9_-]{8,32}$/', (string)($posted['request_uid'] ?? '')) ? (string)$posted['request_uid'] : bin2hex(random_bytes(12)),
            'items'       => $items,
            'methods'     => $method_list,
            'method'      => in_array((string)($posted['method'] ?? ''), $methods, true) ? (string)$posted['method'] : CCReturnsRules::default_method($methods),
            'sizes'       => $sizes,
            'locker_size' => (string)($posted['locker_size'] ?? ''),
            'name'        => $posted ? $this->ccText($posted['name'] ?? '') : $defaults['name'],
            'mobile'      => $posted ? $this->ccText($posted['mobile'] ?? '') : $defaults['mobile'],
            'landline'    => $posted ? $this->ccText($posted['landline'] ?? '') : $defaults['landline'],
            'reason'      => $this->ccText($posted['reason'] ?? ''),
            'boxnow'      => CCReturnsRules::METHOD_BOXNOW,
            'msg_phone'   => CCReturnsRules::MSG_PHONE,
            'msg_mobile'  => CCReturnsRules::MSG_MOBILE,
        ] + $this->ccPickupView($posted, $defaults));
    }

    /** Storefront link with language + customer_token (+ order_id). */
    private function link(string $route, int $order_id): string {
        return $this->ccCatalogLink($route, 'language=' . $this->config->get('config_language') . '&customer_token=' . ($this->session->data['customer_token'] ?? '') . '&order_id=' . $order_id);
    }

    private function renderPage(array $data): void {
        $this->document->setTitle('Αίτημα επιστροφής');
        $data['breadcrumbs'] = [
            ['text' => 'Αρχική', 'href' => $this->ccCatalogLink('common/home', 'language=' . $this->config->get('config_language'))],
            ['text' => 'Παραγγελία #' . (int)$data['order_id'], 'href' => $data['back']],
            ['text' => 'Αίτημα επιστροφής', 'href' => $data['back']],
        ];
        foreach (['column_left', 'column_right', 'content_top', 'content_bottom', 'footer', 'header'] as $part) {
            $data[$part] = $this->load->controller('common/' . $part);
        }
        $this->response->setOutput($this->load->view('extension/couriercenter/shipping/returns_form', $data));
    }

    /** What the customer sees of a request (internal creation states shown as one). */
    private function customerRow(array $r, $svc): array {
        $created = $r['status'] === CCReturnsRules::ST_CREATED;
        $parts = [];
        if ($created) {
            foreach (CCReturnsRules::creation_parts($r) as $p) {
                $parts[] = ['text' => $p[0], 'value' => $p[1], 'is_code' => $p[2]];
            }
        }
        $items = [];
        foreach ($r['items'] as $line) {
            $items[] = (string)($line['name'] ?? '') . ' × ' . (int)($line['qty'] ?? 0);
        }
        $tracking = (string)$r['display_status'];
        $pickup = ($r['method'] === CCReturnsRules::METHOD_PICKUP && !$created && $r['status'] !== CCReturnsRules::ST_REJECTED) ? \Opencart\Extension\Couriercenter\Library\CCReturnsService::pickupLabel($r) : '';
        return [
            'id'           => (int)$r['id'],
            'date'         => (string)$r['created_at'],
            'status_label' => CCReturnsRules::customer_status_label($r['status']),
            'method_label' => CCReturnsRules::method_label($r['method']),
            'items'        => implode(', ', $items),
            'pickup'       => $pickup,
            'parts'        => $parts,
            'instructions' => $created ? $svc->settings()->instructionsFor($r['method']) : '',
            'tracking'     => ($created && $tracking !== '' && $tracking !== 'None' && (string)$r['shipment_status'] !== '0') ? $tracking : '',
        ];
    }
}
