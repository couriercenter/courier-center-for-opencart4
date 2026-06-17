<?php
namespace Opencart\Admin\Controller\Extension\Couriercenter\Shipping;

/**
 * Bug Report page — lets the merchant send a technical problem report to the
 * Courier Center dashboard (same endpoint as the WooCommerce plugin).
 */
class CourierCenterBug extends \Opencart\System\Engine\Controller {

    const DASHBOARD_URL  = 'https://courier-center-dashboard.onrender.com/api/report';
    const PLUGIN_VERSION = '1.2.7';

    public function index(): void {
        $this->document->setTitle('Αναφορά Προβλήματος — Courier Center');

        $token = $this->session->data['user_token'];

        $data['site_url']         = (string)($this->config->get('config_url') ?: (defined('HTTP_CATALOG') ? HTTP_CATALOG : ''));
        $data['plugin_version']   = self::PLUGIN_VERSION;
        $data['opencart_version'] = defined('VERSION') ? VERSION : 'N/A';
        $data['php_version']      = PHP_VERSION;

        // Pre-fill the contact email with the logged-in admin user's email.
        $data['user_email'] = '';
        $uid = (int)($this->session->data['user_id'] ?? 0);
        if ($uid > 0) {
            $uq = $this->db->query("SELECT `email` FROM `" . DB_PREFIX . "user` WHERE `user_id` = '" . $uid . "' LIMIT 1");
            if ($uq->num_rows) {
                $data['user_email'] = (string)$uq->row['email'];
            }
        }

        $data['url_submit']   = $this->url->link('extension/couriercenter/shipping/courier_center_bug.submit', 'user_token=' . $token, true);
        $data['url_settings'] = $this->url->link('extension/couriercenter/shipping/courier_center', 'user_token=' . $token);

        $data['breadcrumbs'] = [
            ['text' => 'Home',                'href' => $this->url->link('common/dashboard', 'user_token=' . $token)],
            ['text' => 'Courier Center',      'href' => $data['url_settings']],
            ['text' => 'Αναφορά Προβλήματος', 'href' => ''],
        ];

        $data['header']      = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer']      = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('extension/couriercenter/shipping/bug_report', $data));
    }

    public function submit(): void {
        $this->response->addHeader('Content-Type: application/json');

        $title       = trim((string)($this->request->post['title']       ?? ''));
        $description = trim((string)($this->request->post['description'] ?? ''));
        $severity    = (string)($this->request->post['severity'] ?? 'low');
        $email       = trim((string)($this->request->post['email'] ?? ''));

        if ($title === '' || $description === '' || $email === '') {
            $this->response->setOutput(json_encode(['success' => false, 'error' => 'Συμπλήρωσε τίτλο, περιγραφή και email επικοινωνίας.']));
            return;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->response->setOutput(json_encode(['success' => false, 'error' => 'Μη έγκυρο email επικοινωνίας.']));
            return;
        }
        if (!in_array($severity, ['low', 'medium', 'critical'], true)) {
            $severity = 'low';
        }

        $payload = [
            'site_url'         => (string)($this->config->get('config_url') ?: (defined('HTTP_CATALOG') ? HTTP_CATALOG : '')),
            'platform'         => 'OpenCart',
            'opencart_version' => defined('VERSION') ? VERSION : 'N/A',
            'plugin_version'   => self::PLUGIN_VERSION,
            'php_version'      => PHP_VERSION,
            'title'            => $title,
            'description'      => $description,
            'severity'         => $severity,
            'reporter_email'   => $email,
        ];

        $ch = curl_init(self::DASHBOARD_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 15,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) {
            $this->response->setOutput(json_encode(['success' => false, 'error' => 'Σφάλμα σύνδεσης: ' . $err]));
            return;
        }
        if ($code === 201 || $code === 200) {
            $this->response->setOutput(json_encode(['success' => true]));
            return;
        }

        $this->response->setOutput(json_encode([
            'success' => false,
            'error'   => 'Απόκριση dashboard: HTTP ' . $code . ' — ' . substr((string)$body, 0, 200),
        ]));
    }
}
