<?php
namespace Opencart\Admin\Controller\Extension\Couriercenter\Shipping;

/**
 * In-admin user guide + changelog (Courier Center → Οδηγίες).
 * OpenCart port of the WooCommerce plugin's Documentation page.
 *
 * ▶ ΣΕ ΚΑΘΕ ΝΕΑ ΕΚΔΟΣΗ: πρόσθεσε εγγραφή στην ενότητα «Τι νέο σε κάθε έκδοση»
 *   μέσα στο template docs.twig και ενημέρωσε το install.json version.
 */
class CourierCenterDocs extends \Opencart\System\Engine\Controller {

    public function index(): void {
        if (!$this->user->hasPermission('access', 'extension/couriercenter/shipping/courier_center_docs')) {
            $this->response->redirect($this->url->link('error/permission', 'user_token=' . $this->session->data['user_token']));
            return;
        }

        $this->document->setTitle('Οδηγίες — Courier Center');
        $token = $this->session->data['user_token'];

        // Version from install.json (single source of truth, used by the updater too).
        $version = '1.0.0';
        $ij = DIR_EXTENSION . 'couriercenter/install.json';
        if (is_file($ij)) {
            $j = json_decode((string)file_get_contents($ij), true);
            if (!empty($j['version'])) {
                $version = (string)$j['version'];
            }
        }

        $data['version']      = $version;
        $data['url_settings'] = $this->url->link('extension/couriercenter/shipping/courier_center',      'user_token=' . $token);
        $data['url_report']   = $this->url->link('extension/couriercenter/shipping/courier_center_bug',  'user_token=' . $token);
        $data['url_manifest'] = $this->url->link('extension/couriercenter/shipping/courier_center_manifest', 'user_token=' . $token);

        $data['breadcrumbs'] = [
            ['text' => 'Home',           'href' => $this->url->link('common/dashboard', 'user_token=' . $token)],
            ['text' => 'Courier Center', 'href' => $data['url_settings']],
            ['text' => 'Οδηγίες',        'href' => ''],
        ];

        $data['header']      = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer']      = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('extension/couriercenter/shipping/docs', $data));
    }
}
