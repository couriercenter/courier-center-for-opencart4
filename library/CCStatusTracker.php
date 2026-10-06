<?php
/**
 * Shipment status tracking for the Courier Center extension (shared by OpenCart 3 and 4):
 * one implementation for the order panel, the bulk action and the cron job.
 * PHP 7.2+ syntax.
 */

namespace Opencart\Extension\Couriercenter\Library;

class CCStatusTracker {

    const FINAL_CODES     = ['29', '25', '99', '14', '87', '95'];
    const DELIVERED_CODES = ['29', '87'];
    const MIN_INTERVAL    = 3600;

    /** @var object */
    private $db;
    /** @var string */
    private $prefix;
    /** @var CCApi */
    private $api;
    /** @var int */
    private $auto_complete_status_id;
    /** @var CCOrderHistory */
    private $history;

    public function __construct($db, string $prefix, CCApi $api, int $auto_complete_status_id = 0) {
        $this->db = $db;
        $this->prefix = $prefix;
        $this->api = $api;
        $this->auto_complete_status_id = $auto_complete_status_id;
        $this->history = new CCOrderHistory($db, $prefix);
    }

    /**
     * Status of a GetShipmentDetails response. The API nests it under
     * ShipmentDetails[0].ShipmentInfo (that is what the WooCommerce plugin reads);
     * the top-level keys used by the first OpenCart 4 release are a fallback.
     *
     * @return array ['code' => string, 'desc' => string, 'action' => string]
     */
    public static function parse_status(array $result): array {
        $info = $result['ShipmentDetails'][0]['ShipmentInfo'] ?? null;
        if (is_array($info) && (isset($info['ShipmentStatus']) || isset($info['ShipmentStatusDesc']))) {
            return [
                'code'   => trim((string)($info['ShipmentStatus'] ?? '')),
                'desc'   => trim((string)($info['ShipmentStatusDesc'] ?? '')),
                'action' => trim((string)($info['CollectionStatus'] ?? '')),
            ];
        }
        return [
            'code'   => trim((string)($result['StatusCode'] ?? $result['DeliveryStatus'] ?? '')),
            'desc'   => trim((string)($result['StatusDescription'] ?? $result['DeliveryStatusDescription'] ?? '')),
            'action' => '',
        ];
    }

    /** Final status: polling stops (same list as the WooCommerce plugin). */
    public static function is_final(string $code): bool {
        return in_array($code, self::FINAL_CODES, true);
    }

    /** Delivered status: triggers the optional auto-complete of the order. */
    public static function is_delivered(string $code): bool {
        return in_array($code, self::DELIVERED_CODES, true);
    }

    /**
     * Ask the API for one shipment's status and store it.
     *
     * @param array  $shipment cc_shipments row (order_id, voucher_number, status_code, ...)
     * @param string $source   '' (order panel), 'Bulk' or 'Cron' - shown in the history note
     * @return array ['success','error','code','desc','final','changed','completed']
     */
    public function refresh(array $shipment, string $source = ''): array {
        $out = ['success' => false, 'error' => '', 'code' => '', 'desc' => '', 'final' => false, 'changed' => false, 'completed' => false];
        $order_id = (int)($shipment['order_id'] ?? 0);
        $awb = (string)($shipment['voucher_number'] ?? '');
        if ($order_id <= 0 || $awb === '') {
            $out['error'] = 'Δεν υπάρχει αποστολή';
            return $out;
        }

        $result = $this->api->get_shipment_details($awb);
        if (isset($result['success']) && $result['success'] === false) {
            $out['error'] = (string)($result['error'] ?? 'Σφάλμα API');
            return $out;
        }

        $status = self::parse_status($result);
        if ($status['code'] === '' && $status['desc'] === '') {
            // Remember the check (throttling) but keep the last known status.
            $this->db->query("UPDATE `" . $this->prefix . "cc_shipments` SET `last_checked_at` = '" . time() . "' WHERE `order_id` = '" . $order_id . "'");
            $out['error'] = 'Δεν βρέθηκε status στην απάντηση του API.';
            return $out;
        }

        $code  = $status['code'];
        $final = self::is_final($code);
        $this->db->query("UPDATE `" . $this->prefix . "cc_shipments` SET `status_code` = '" . $this->db->escape($code) . "', `status_desc` = '" . $this->db->escape($status['desc']) . "', `is_final` = '" . ($final ? 1 : 0) . "', `last_checked_at` = '" . time() . "', `status_updated_at` = NOW() WHERE `order_id` = '" . $order_id . "'");

        $out['success'] = true;
        $out['code'] = $code;
        $out['desc'] = $status['desc'];
        $out['final'] = $final;

        if ($code !== '' && $code !== (string)($shipment['status_code'] ?? '')) {
            $label  = $source !== '' ? '[' . $source . '] ' : '';
            $action = $status['action'] !== '' ? ' (Action: ' . $status['action'] . ')' : '';
            $this->history->add($order_id, '📍 ' . $label . 'Courier Center status: ' . $code . ' — ' . $status['desc'] . $action);
            $out['changed'] = true;
            $out['completed'] = $this->maybe_auto_complete($order_id, $code, $label);
        }
        return $out;
    }

    /**
     * Cron pass: refresh every active (not voided, not final) shipment that was
     * not checked during the last MIN_INTERVAL seconds.
     *
     * @return array ['checked','updated','skipped','failed','lines' => string[]]
     */
    public function run(int $now = 0, int $pause_us = 250000): array {
        $now = $now > 0 ? $now : time();
        $report = ['checked' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'lines' => []];
        $rows = $this->db->query("SELECT * FROM `" . $this->prefix . "cc_shipments` WHERE `is_voided` = 0 AND `is_final` = 0 AND `voucher_number` != ''")->rows;
        foreach ($rows as $row) {
            $last = (int)($row['last_checked_at'] ?? 0);
            if ($last > 0 && ($now - $last) < self::MIN_INTERVAL) {
                $report['skipped']++;
                continue;
            }
            $report['checked']++;
            $r = $this->refresh($row, 'Cron');
            if ($r['success']) {
                $report['updated']++;
                $report['lines'][] = 'UPDATE order #' . (int)$row['order_id'] . ': ' . $r['code'] . ' — ' . $r['desc'] . ($r['final'] ? ' [FINAL]' : '');
            } else {
                $report['failed']++;
                $report['lines'][] = 'ERROR order #' . (int)$row['order_id'] . ': ' . $r['error'];
            }
            if ($pause_us > 0) {
                usleep($pause_us);
            }
        }
        return $report;
    }

    private function maybe_auto_complete(int $order_id, string $code, string $label): bool {
        if ($this->auto_complete_status_id <= 0 || !self::is_delivered($code)) {
            return false;
        }
        $cur = $this->db->query("SELECT `order_status_id` FROM `" . $this->prefix . "order` WHERE `order_id` = '" . $order_id . "' LIMIT 1");
        if (!$cur->num_rows || (int)$cur->row['order_status_id'] === $this->auto_complete_status_id) {
            return false;
        }
        return $this->history->add($order_id, '🚚 ' . $label . 'Courier Center: Η αποστολή παραδόθηκε — αυτόματη ολοκλήρωση παραγγελίας.', $this->auto_complete_status_id);
    }
}
