<?php
/**
 * Customer email content of the Courier Center extension (shared by OpenCart 3 and 4, PHP 7.2+):
 * - the tracking block added to the shop's own order emails (AWB + tracking link)
 * - the 3 returns emails (received / created / rejected) and «Νέο αίτημα επιστροφής» to the shop
 * Sending stays in the platform code (OpenCart 3 and 4 have different Mail classes).
 */

namespace Opencart\Extension\Couriercenter\Library;

class CCMailContent {

    const DEFAULT_TRACKING_URL = 'https://www.courier.gr/track/result?tracknr={{tracking}}';

    public static function trackingUrl(string $template, string $code): string {
        $template = trim($template) !== '' ? $template : self::DEFAULT_TRACKING_URL;
        return str_replace('{{tracking}}', rawurlencode($code), $template);
    }

    /** HTML block for the order email ('' without an active voucher). */
    public static function trackingHtml(array $shipment, string $tracking_template): string {
        if (!CCVoucherService::isActive($shipment)) {
            return '';
        }
        $awb = self::e((string)$shipment['voucher_number']);
        $code = (string)($shipment['tracking_number'] ?? '') !== '' ? (string)$shipment['tracking_number'] : (string)$shipment['voucher_number'];
        $url = self::e(self::trackingUrl($tracking_template, $code));
        $parcel = (string)($shipment['boxnow_parcel_id'] ?? '');
        $extra = $parcel !== '' ? '<br>BOX NOW Parcel ID: <strong>' . self::e($parcel) . '</strong>' : '';
        return '<div style="margin:0 0 20px; padding:14px 16px; background:#f6f9fc; border-left:4px solid #2271b1; border-radius:4px; font-family:Arial,sans-serif; font-size:14px; color:#2c3338;">'
            . '<strong>Στοιχεία Αποστολής Courier Center</strong><br>'
            . 'Αριθμός Αποστολής: <strong>' . $awb . '</strong>' . $extra . '<br>'
            . '<a href="' . $url . '" style="color:#2271b1;">Παρακολούθηση Αποστολής &rarr;</a></div>';
    }

    /** Plain-text block for the order email ('' without an active voucher). */
    public static function trackingText(array $shipment, string $tracking_template): string {
        if (!CCVoucherService::isActive($shipment)) {
            return '';
        }
        $code = (string)($shipment['tracking_number'] ?? '') !== '' ? (string)$shipment['tracking_number'] : (string)$shipment['voucher_number'];
        $parcel = (string)($shipment['boxnow_parcel_id'] ?? '');
        return "Στοιχεία Αποστολής Courier Center\n"
            . 'Αριθμός Αποστολής: ' . $shipment['voucher_number'] . "\n"
            . ($parcel !== '' ? 'BOX NOW Parcel ID: ' . $parcel . "\n" : '')
            . 'Παρακολούθηση: ' . self::trackingUrl($tracking_template, $code) . "\n";
    }

    /**
     * A returns email.
     * @param string $event received | created | rejected
     * @param array  $row   return request (hydrated)
     * @param array  $order order row (firstname, order_id)
     * @return array ['subject', 'html', 'text'] | [] for an unknown event
     */
    public static function returnEmail(string $event, array $row, array $order, string $instructions = ''): array {
        $n = (int)($order['order_id'] ?? $row['order_id'] ?? 0);
        $first = trim(html_entity_decode((string)($order['firstname'] ?? ''), ENT_QUOTES, 'UTF-8'));
        $hello = $first !== '' ? sprintf('Γεια σας %s,', $first) : 'Γεια σας,';
        if ($event === 'received') {
            $subject = sprintf('Επιστροφή παραγγελίας #%d: το αίτημα ελήφθη', $n);
            $heading = 'Το αίτημα επιστροφής σας ελήφθη';
            $lines = [sprintf('Το αίτημα επιστροφής για την παραγγελία #%d ελήφθη και θα εξεταστεί από το κατάστημα. Θα ενημερωθείτε για την εξέλιξή του.', $n)];
        } elseif ($event === 'created') {
            $subject = sprintf('Επιστροφή παραγγελίας #%d: δημιουργήθηκε η αποστολή', $n);
            $heading = 'Η επιστροφή σας δημιουργήθηκε';
            $lines = [];
            foreach (CCReturnsRules::creation_parts($row) as $part) {
                $lines[] = $part[0] . $part[1];
            }
        } elseif ($event === 'rejected') {
            $subject = sprintf('Επιστροφή παραγγελίας #%d: το αίτημα απορρίφθηκε', $n);
            $heading = 'Το αίτημα επιστροφής σας απορρίφθηκε';
            $lines = [sprintf('Το αίτημα επιστροφής για την παραγγελία #%d απορρίφθηκε.', $n), 'Για περισσότερες πληροφορίες μπορείτε να επικοινωνήσετε με το κατάστημα.'];
        } else {
            return [];
        }
        $items = [];
        foreach ((array)($row['items'] ?? []) as $line) {
            $items[] = (string)($line['name'] ?? 'Προϊόν') . ' × ' . (int)($line['qty'] ?? 0);
        }
        $method = CCReturnsRules::method_label((string)($row['method'] ?? ''));

        $html = '<div style="font-family:Arial,sans-serif; font-size:14px; color:#2c3338; max-width:600px;">'
            . '<h2 style="font-size:18px; color:#1d2327;">' . self::e($heading) . '</h2>'
            . '<p>' . self::e($hello) . '</p><p>';
        foreach ($lines as $i => $l) {
            $html .= ($i ? '<br>' : '') . ($i === 0 && $event === 'created' ? '<strong>' . self::e($l) . '</strong>' : self::e($l));
        }
        $html .= '</p>';
        if ($event === 'created' && trim($instructions) !== '') {
            $html .= '<p>' . nl2br(self::e($instructions)) . '</p>';
        }
        $html .= '<p>' . self::e(sprintf('Παραγγελία #%d', $n)) . '</p><ul>';
        foreach ($items as $it) {
            $html .= '<li>' . self::e($it) . '</li>';
        }
        $html .= '</ul><p>' . self::e('Τρόπος επιστροφής: ' . $method) . '</p></div>';

        $text = $heading . "\n\n" . $hello . "\n\n" . implode("\n", $lines) . "\n"
            . ($event === 'created' && trim($instructions) !== '' ? "\n" . $instructions . "\n" : '')
            . "\n" . sprintf('Παραγγελία #%d', $n) . "\n- " . implode("\n- ", $items) . "\n\nΤρόπος επιστροφής: " . $method . "\n";
        return ['subject' => $subject, 'html' => $html, 'text' => $text];
    }

    /**
     * Customer email when a delivered order is completed automatically (WooCommerce's «Completed order»).
     * @param array $order    order row (order_id, firstname, total, currency_code ...)
     * @param array $items    [['name' => ..., 'quantity' => ...], ...]
     * @param array $shipment cc_shipments row (for the tracking block)
     * @return array ['subject', 'html', 'text']
     */
    public static function orderCompletedEmail(array $order, array $items, array $shipment, string $tracking_template): array {
        $n = (int)($order['order_id'] ?? 0);
        $first = trim(html_entity_decode((string)($order['firstname'] ?? ''), ENT_QUOTES, 'UTF-8'));
        $hello = $first !== '' ? sprintf('Γεια σας %s,', $first) : 'Γεια σας,';
        $heading = 'Η παραγγελία σας ολοκληρώθηκε';
        $line = sprintf('Η παραγγελία #%d παραδόθηκε. Σας ευχαριστούμε!', $n);
        $lines = [];
        foreach ($items as $it) {
            $lines[] = html_entity_decode((string)($it['name'] ?? 'Προϊόν'), ENT_QUOTES, 'UTF-8') . ' × ' . (int)($it['quantity'] ?? 0);
        }
        $html = '<div style="font-family:Arial,sans-serif; font-size:14px; color:#2c3338; max-width:600px;">'
            . '<h2 style="font-size:18px; color:#1d2327;">' . self::e($heading) . '</h2>'
            . '<p>' . self::e($hello) . '</p><p>' . self::e($line) . '</p>'
            . self::trackingHtml($shipment, $tracking_template);
        if ($lines) {
            $html .= '<ul>';
            foreach ($lines as $l) {
                $html .= '<li>' . self::e($l) . '</li>';
            }
            $html .= '</ul>';
        }
        $html .= '</div>';
        $text = $heading . "\n\n" . $hello . "\n\n" . $line . "\n\n" . self::trackingText($shipment, $tracking_template)
            . ($lines ? "\n- " . implode("\n- ", $lines) . "\n" : '');
        return ['subject' => sprintf('Η παραγγελία #%d ολοκληρώθηκε', $n), 'html' => $html, 'text' => $text];
    }

    /**
     * Email «Νέο αίτημα επιστροφής» to the shop (every customer request, with or without approval).
     * @param array $order order row (firstname, lastname, email, order_id)
     * @return array ['subject', 'html', 'text']
     */
    public static function adminNewReturnEmail(array $row, array $order, string $requests_url, string $order_url): array {
        $n = (int)($order['order_id'] ?? $row['order_id'] ?? 0);
        $name = trim(html_entity_decode((string)($order['firstname'] ?? '') . ' ' . (string)($order['lastname'] ?? ''), ENT_QUOTES, 'UTF-8'));
        $lines = [sprintf('Ο πελάτης %s ζήτησε επιστροφή για την παραγγελία #%d.', $name !== '' ? $name : (string)($order['email'] ?? ''), $n)];
        foreach ((array)($row['items'] ?? []) as $line) {
            $lines[] = '• ' . (string)($line['name'] ?? 'Προϊόν') . ' × ' . (int)($line['qty'] ?? 0);
        }
        $method = (string)($row['method'] ?? '');
        $lines[] = 'Τρόπος επιστροφής: ' . CCReturnsRules::method_label($method);
        if ($method === CCReturnsRules::METHOD_PICKUP) {
            $label = CCReturnsRules::pickup_text($row['pickup_date'] ?? '', $row['pickup_time_from'] ?? '', $row['pickup_time_to'] ?? '');
            if ($label !== '') {
                $lines[] = 'Παραλαβή: ' . $label;
            }
        }
        if (trim((string)($row['reason'] ?? '')) !== '') {
            $lines[] = 'Αιτιολογία: ' . trim((string)$row['reason']);
        }
        $status = (string)($row['status'] ?? '');
        if ($status === CCReturnsRules::ST_PENDING) {
            $lines[] = 'Περιμένει την έγκρισή σας.';
        } elseif (trim((string)($row['return_awb'] ?? '')) !== '') {
            $lines[] = 'Η αποστολή επιστροφής δημιουργήθηκε αυτόματα. Αριθμός αποστολής επιστροφής: ' . $row['return_awb'];
        } else {
            $lines[] = 'Κατάσταση: ' . CCReturnsRules::status_label($status);
        }
        $subject = sprintf('Νέο αίτημα επιστροφής για την παραγγελία #%d', $n);
        $heading = 'Νέο αίτημα επιστροφής';
        $html = '<div style="font-family:Arial,sans-serif; font-size:14px; color:#2c3338; max-width:600px;">'
            . '<h2 style="font-size:18px; color:#1d2327;">' . self::e($heading) . '</h2><p>';
        foreach ($lines as $i => $l) {
            $html .= ($i ? '<br>' : '') . self::e($l);
        }
        $html .= '</p><p><a href="' . self::e($requests_url) . '" style="color:#2271b1;">Αιτήματα επιστροφής</a>'
            . ($order_url !== '' ? ' · <a href="' . self::e($order_url) . '" style="color:#2271b1;">Παραγγελία #' . $n . '</a>' : '') . '</p></div>';
        $text = $heading . "\n\n" . implode("\n", $lines) . "\n\nΑιτήματα επιστροφής: " . $requests_url . "\n"
            . ($order_url !== '' ? 'Παραγγελία: ' . $order_url . "\n" : '');
        return ['subject' => $subject, 'html' => $html, 'text' => $text];
    }

    private static function e(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}
