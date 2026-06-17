<?php
namespace Opencart\Admin\Controller\Extension\Couriercenter\Shipping;

class CourierCenterManifest extends \Opencart\System\Engine\Controller {

    public function index(): void {
        $this->load->language('extension/couriercenter/shipping/courier_center');
        $this->document->setTitle('Manifest Παραλαβής — Courier Center');

        $today         = date('Y-m-d');
        $selected_date = trim($this->request->get['manifest_date'] ?? $today);

        // Validate date
        $d = \DateTime::createFromFormat('Y-m-d', $selected_date);
        if (!$d || $d->format('Y-m-d') !== $selected_date) {
            $selected_date = $today;
        }

        $this->load->model('extension/couriercenter/shipping/courier_center');
        $vouchers_count = $this->model_extension_couriercenter_shipping_courier_center->countShipmentsForDate($selected_date);

        $data['selected_date']  = $selected_date;
        $data['today']          = $today;
        $data['yesterday']      = date('Y-m-d', strtotime('-1 day'));
        $data['day_before']     = date('Y-m-d', strtotime('-2 days'));
        $data['vouchers_count'] = $vouchers_count;
        $data['greek_date']     = $this->formatGreekDate($selected_date);

        $data['url_download']   = $this->url->link('extension/couriercenter/shipping/courier_center_manifest.download', 'user_token=' . $this->session->data['user_token'] . '&date=' . urlencode($selected_date));
        $data['url_download_save'] = $this->url->link('extension/couriercenter/shipping/courier_center_manifest.download', 'user_token=' . $this->session->data['user_token'] . '&date=' . urlencode($selected_date) . '&save=1');
        $data['url_self']       = $this->url->link('extension/couriercenter/shipping/courier_center_manifest', 'user_token=' . $this->session->data['user_token']);
        $data['url_settings']   = $this->url->link('extension/couriercenter/shipping/courier_center', 'user_token=' . $this->session->data['user_token']);

        $data['breadcrumbs'] = [
            ['text' => 'Home',               'href' => $this->url->link('common/dashboard',   'user_token=' . $this->session->data['user_token'])],
            ['text' => 'Courier Center',     'href' => $data['url_settings']],
            ['text' => 'Manifest Παραλαβής', 'href' => $data['url_self']],
        ];

        $data['header']      = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer']      = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('extension/couriercenter/shipping/manifest', $data));
    }

    public function download(): void {
        $date = trim($this->request->get['date'] ?? date('Y-m-d'));
        $d    = \DateTime::createFromFormat('Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date) $date = date('Y-m-d');

        $save = !empty($this->request->get['save']);

        require_once DIR_EXTENSION . 'couriercenter/library/CCApi.php';
        $api = new \Opencart\Extension\Couriercenter\Library\CCApi(
            (string)$this->config->get('shipping_courier_center_user_alias'),
            (string)$this->config->get('shipping_courier_center_credential_value'),
            (string)$this->config->get('shipping_courier_center_api_key'),
            (string)$this->config->get('shipping_courier_center_billing_account')
        );

        $pdf = $api->get_manifest($date);

        if (is_array($pdf)) {
            http_response_code(500);
            exit('Σφάλμα λήψης manifest: ' . ($pdf['error'] ?? 'Άγνωστο σφάλμα'));
        }

        $disposition = $save ? 'attachment' : 'inline';
        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . $disposition . '; filename="manifest-' . $date . '.pdf"');
        header('Content-Length: ' . strlen($pdf));
        header('Cache-Control: no-cache');
        echo $pdf;
        exit;
    }

    private function formatGreekDate(string $date): string {
        $months = [
            '01' => 'Ιανουαρίου', '02' => 'Φεβρουαρίου', '03' => 'Μαρτίου',
            '04' => 'Απριλίου',   '05' => 'Μαΐου',       '06' => 'Ιουνίου',
            '07' => 'Ιουλίου',    '08' => 'Αυγούστου',   '09' => 'Σεπτεμβρίου',
            '10' => 'Οκτωβρίου',  '11' => 'Νοεμβρίου',   '12' => 'Δεκεμβρίου',
        ];
        $parts = explode('-', $date);
        if (count($parts) !== 3) return $date;
        return (int)$parts[2] . ' ' . ($months[$parts[1]] ?? $parts[1]) . ' ' . $parts[0];
    }
}
