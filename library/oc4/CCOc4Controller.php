<?php
/**
 * Helpers shared by the extension's OpenCart 4 controllers (admin + catalog).
 * Uses OpenCart's $this->db / config / url / session / user / log / response / load.
 */

namespace Opencart\Extension\Couriercenter\Library\Oc4;

use Opencart\Extension\Couriercenter\Library\CCMailContent;
use Opencart\Extension\Couriercenter\Library\CCReturnsRules;
use Opencart\Extension\Couriercenter\Library\CCReturnsService;
use Opencart\Extension\Couriercenter\Library\CCVoucherService;

trait CCOc4Controller {

    protected function ccService(): CCVoucherService {
        return new CCVoucherService($this->db, $this->config, DB_PREFIX);
    }

    /** Returns service with the shop's mail (customer emails) and log (problems only). */
    protected function ccReturns(): CCReturnsService {
        return new CCReturnsService($this->db, DB_PREFIX, $this->config, null,
            function ($event, $id) {
                $this->ccSendReturnMail((string)$event, (int)$id);
            },
            function ($level, $message) {
                if ($level !== 'info') {
                    $this->log->write('Courier Center returns [' . $level . ']: ' . $message);
                }
            });
    }

    protected function ccCanModify(): bool {
        return $this->user->hasPermission('modify', CCOc4Setup::BASE_ROUTE);
    }

    /** Admin URL with user_token and plain '&' (templates escape it). */
    protected function ccLink(string $route, string $args = ''): string {
        $token = $this->session->data['user_token'] ?? '';
        $args = 'user_token=' . $token . ($args !== '' ? '&' . ltrim($args, '&') : '');
        return str_replace('&amp;', '&', $this->url->link($route, $args));
    }

    /** Storefront URL with plain '&'. */
    protected function ccCatalogLink(string $route, string $args = ''): string {
        return str_replace('&amp;', '&', $this->url->link($route, $args));
    }

    /** OpenCart stores form input HTML-escaped: decode before using it as text. */
    protected function ccText($value): string {
        return html_entity_decode((string)$value, ENT_QUOTES, 'UTF-8');
    }

    protected function ccJson(array $data): void {
        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($data));
    }

    protected function ccDeny(): void {
        $this->ccJson(['success' => false, 'error' => 'Δεν έχετε δικαίωμα τροποποίησης του Courier Center.']);
    }

    /** Plain-text error, then exit (download endpoints). */
    protected function ccTextError(int $status, string $message): void {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: text/plain; charset=utf-8');
        }
        echo $message;
        exit;
    }

    /** Send a PDF, then exit (bypasses OpenCart's output compression, which would break Content-Length). */
    protected function ccSendPdf(string $pdf, string $filename, string $disposition = 'inline'): void {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        $filename = preg_replace('/[^A-Za-z0-9_.-]/', '', $filename);
        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . ($disposition === 'attachment' ? 'attachment' : 'inline') . '; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($pdf));
        header('Cache-Control: no-cache');
        echo $pdf;
        exit;
    }

    /** One of the returns emails (received / created / rejected) to the order's customer. */
    protected function ccSendReturnMail(string $event, int $id): void {
        $svc = new CCReturnsService($this->db, DB_PREFIX, $this->config);
        $row = $svc->repo()->get($id);
        if (!$row) {
            return;
        }
        $order = $svc->getOrder((int)$row['order_id']);
        $to = trim((string)($order['email'] ?? ''));
        if ($to === '') {
            return;
        }
        $instructions = $row['method'] === CCReturnsRules::METHOD_BOXNOW ? $svc->settings()->boxnowInstructions() : $svc->settings()->dropoffInstructions();
        $m = CCMailContent::returnEmail($event, $row, $order, $instructions);
        if ($m) {
            $this->ccMail($to, $m['subject'], $m['html']);
        }
    }

    /** Send with the shop's mail settings (OpenCart 4 sends nothing without a mail engine). Errors go to the log. */
    protected function ccMail(string $to, string $subject, string $html): void {
        $engine = (string)$this->config->get('config_mail_engine');
        if ($engine === '') {
            return;
        }
        try {
            $option = [
                'parameter'     => $this->config->get('config_mail_parameter'),
                'smtp_hostname' => $this->config->get('config_mail_smtp_hostname'),
                'smtp_username' => $this->config->get('config_mail_smtp_username'),
                'smtp_password' => html_entity_decode((string)$this->config->get('config_mail_smtp_password'), ENT_QUOTES, 'UTF-8'),
                'smtp_port'     => $this->config->get('config_mail_smtp_port'),
                'smtp_timeout'  => $this->config->get('config_mail_smtp_timeout'),
            ];
            $mail = new \Opencart\System\Library\Mail($engine, $option);
            $mail->setTo($to);
            $mail->setFrom((string)$this->config->get('config_email'));
            $mail->setSender(html_entity_decode((string)$this->config->get('config_name'), ENT_QUOTES, 'UTF-8'));
            $mail->setSubject($subject);
            $mail->setHtml($html);
            $mail->send();
        } catch (\Throwable $e) {
            $this->log->write('Courier Center mail: ' . $e->getMessage());
        }
    }

    /** Request row for the admin views (returns page + order panel). */
    protected function ccReturnRow(array $r): array {
        $items = [];
        foreach ($r['items'] as $line) {
            $items[] = (string)($line['name'] ?? '') . ' × ' . (int)($line['qty'] ?? 0);
        }
        $st = $r['status'];
        $size = $r['locker_size'];
        return [
            'id'           => (int)$r['id'],
            'order_id'     => (int)$r['order_id'],
            'status'       => $st,
            'status_label' => CCReturnsRules::status_label($st),
            'method_label' => CCReturnsRules::method_label($r['method']),
            'locker_label' => isset(CCReturnsRules::LOCKER_SIZES[$size]) ? CCReturnsRules::LOCKER_SIZES[$size]['label'] : '',
            'items'        => implode(', ', $items),
            'reason'       => (string)$r['reason'],
            'original_awb' => (string)$r['original_awb'],
            'return_awb'   => (string)$r['return_awb'],
            'rsn'          => (string)$r['return_shipment_number'],
            'tracking'     => ($r['display_status'] !== '' && $r['display_status'] !== 'None') ? (string)$r['display_status'] : '',
            'accounts'     => trim((string)$r['original_shipper_code'] . ($r['return_account_code'] !== '' && $r['return_account_code'] !== $r['original_shipper_code'] ? ' → ' . $r['return_account_code'] : '')),
            'last_error'   => (string)$r['last_error'],
            'created_at'   => (string)$r['created_at'],
            'can_approve'  => $st === CCReturnsRules::ST_PENDING,
            'can_reject'   => in_array($st, [CCReturnsRules::ST_PENDING, CCReturnsRules::ST_APPROVED, CCReturnsRules::ST_FAILED], true),
            'can_create'   => in_array($st, [CCReturnsRules::ST_APPROVED, CCReturnsRules::ST_FAILED], true),
            'can_verify'   => $st === CCReturnsRules::ST_VERIFY,
            'can_track'    => $st === CCReturnsRules::ST_CREATED,
            'print_url'    => $st === CCReturnsRules::ST_CREATED ? $this->ccLink(CCOc4Setup::BASE_ROUTE . '_returns.voucher', 'id=' . (int)$r['id']) : '',
        ];
    }

    protected function ccReturnActionUrls(): array {
        $urls = [];
        foreach (['approve', 'reject', 'create', 'verify', 'track'] as $a) {
            $urls[$a] = $this->ccLink(CCOc4Setup::BASE_ROUTE . '_returns.' . $a);
        }
        return $urls;
    }
}
