<?php
namespace Opencart\Catalog\Controller\Extension\Couriercenter\Shipping;

/**
 * Tracking email integration.
 *
 * Event: catalog/model/checkout/order/addHistory/after
 * Fires when an order's status changes. When the merchant ticks "notify customer"
 * and a Courier Center voucher exists for the order, we append shipment tracking
 * details to a customer email.
 *
 * $args[0] = order_id, $args[1] = order_status_id, $args[2] = comment, $args[3] = notify
 */
class CourierCenterEmail extends \Opencart\System\Engine\Controller {

    public function sendTracking(string &$route, array &$args): void {
        if ((string)$this->config->get('shipping_courier_center_email_tracking_enabled') === '0') {
            return;
        }

        $order_id = (int)($args[0] ?? 0);
        $notify   = !empty($args[3]);

        if (!$order_id || !$notify) {
            return;
        }

        // Shipment for this order (query directly — self-contained)
        $q = $this->db->query(
            "SELECT * FROM `" . DB_PREFIX . "cc_shipments` WHERE `order_id` = '" . (int)$order_id . "' LIMIT 1"
        );
        $shipment = $q->row;

        if (empty($shipment['voucher_number']) || !empty($shipment['is_voided'])) {
            return;
        }

        // Order recipient
        $o = $this->db->query(
            "SELECT `email`, `firstname`, `lastname`, `store_id` FROM `" . DB_PREFIX . "order`
             WHERE `order_id` = '" . (int)$order_id . "' LIMIT 1"
        );
        if (!$o->row || empty($o->row['email'])) {
            return;
        }

        $tracking_code = $shipment['tracking_number'] ?: $shipment['voucher_number'];

        $tracking_url_tpl = (string)$this->config->get('shipping_courier_center_tracking_url');
        if (!$tracking_url_tpl) {
            $tracking_url_tpl = 'https://www.courier.gr/track/result?tracknr={{tracking}}';
        }
        $tracking_url = str_replace('{{tracking}}', urlencode($tracking_code), $tracking_url_tpl);

        $name  = trim($o->row['firstname'] . ' ' . $o->row['lastname']);
        $email = $o->row['email'];

        $store_name = (string)$this->config->get('config_name');

        $this->load->library('mail');
        $mail_option = [
            'parameter'     => $this->config->get('config_mail_parameter'),
            'smtp_hostname' => $this->config->get('config_mail_smtp_hostname'),
            'smtp_username' => $this->config->get('config_mail_smtp_username'),
            'smtp_password' => html_entity_decode($this->config->get('config_mail_smtp_password'), ENT_QUOTES, 'UTF-8'),
            'smtp_port'     => $this->config->get('config_mail_smtp_port'),
            'smtp_timeout'  => $this->config->get('config_mail_smtp_timeout'),
        ];

        $mail = new \Opencart\System\Library\Mail($this->config->get('config_mail_engine'), $mail_option);
        $mail->setTo($email);
        $mail->setFrom($this->config->get('config_email'));
        $mail->setSender($store_name);
        $mail->setSubject('Στοιχεία Αποστολής — Παραγγελία #' . $order_id);
        $mail->setHtml($this->buildHtmlEmail($shipment['voucher_number'], $tracking_url, $name));
        $mail->setText($this->buildPlainEmail($shipment['voucher_number'], $tracking_url));
        $mail->send();
    }

    private function buildHtmlEmail(string $awb, string $tracking_url, string $name): string {
        $name_escaped = htmlspecialchars($name);
        $awb_escaped  = htmlspecialchars($awb);
        $url_escaped  = htmlspecialchars($tracking_url);

        return <<<HTML
<div style="margin:0 0 30px; padding:20px; background:#f6f9fc; border-left:4px solid #2271b1; border-radius:4px; font-family:Arial,sans-serif;">
  <p style="margin:0 0 12px; font-size:16px; font-weight:bold; color:#1d2327; border-bottom:1px solid #dce3e8; padding-bottom:10px;">
    Στοιχεία Αποστολής Courier Center
  </p>
  <p style="margin:0 0 10px; color:#2c3338; font-size:14px;">
    Αγαπητέ/ή <strong>$name_escaped</strong>,<br>
    η παραγγελία σας έχει δρομολογηθεί μέσω <strong>Courier Center</strong>.
  </p>
  <table cellpadding="0" cellspacing="0" border="0" style="width:100%; margin:10px 0;">
    <tr>
      <td style="padding:6px 0; color:#50575e; font-size:14px; width:180px;">Αριθμός Αποστολής:</td>
      <td style="padding:6px 0; color:#1d2327; font-size:14px; font-weight:bold;">$awb_escaped</td>
    </tr>
  </table>
  <p style="margin:15px 0 0;">
    <a href="$url_escaped" style="display:inline-block; padding:10px 20px; background:#2271b1; color:#ffffff; text-decoration:none; border-radius:4px; font-weight:500; font-size:14px;">
      Παρακολούθηση Αποστολής &rarr;
    </a>
  </p>
  <p style="margin:15px 0 0; color:#787c82; font-size:12px;">
    Μπορείτε να παρακολουθείτε την αποστολή σας χρησιμοποιώντας τον αριθμό αποστολής στο
    <a href="https://www.courier.gr" style="color:#2271b1;">courier.gr</a>.
  </p>
</div>
HTML;
    }

    private function buildPlainEmail(string $awb, string $tracking_url): string {
        return "\n=====================================\n" .
               "ΣΤΟΙΧΕΙΑ ΑΠΟΣΤΟΛΗΣ - COURIER CENTER\n" .
               "=====================================\n" .
               "Αριθμός Αποστολής: $awb\n" .
               "Παρακολούθηση: $tracking_url\n" .
               "=====================================\n\n";
    }
}
