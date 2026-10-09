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
    const CRON_LIMIT      = 200;

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
    /** @var int[] order statuses of orders still on their way (pending + processing) */
    private $active_statuses;
    /** @var int[] the shop's "complete" statuses */
    private $complete_statuses;

    /**
     * @param array $status_sets ['active' => int[], 'complete' => int[]] (CCVoucherService::statusSets()).
     *                           Auto-complete only moves orders that are in an active status, so a
     *                           cancelled / refunded order is never completed; the cron skips orders
     *                           that are neither active nor complete.
     */
    public function __construct($db, string $prefix, CCApi $api, int $auto_complete_status_id = 0, array $status_sets = []) {
        $this->db = $db;
        $this->prefix = $prefix;
        $this->api = $api;
        $this->auto_complete_status_id = $auto_complete_status_id;
        $this->history = new CCOrderHistory($db, $prefix);
        $this->active_statuses = array_values(array_filter(array_map('intval', $status_sets['active'] ?? [])));
        $this->complete_statuses = array_values(array_filter(array_map('intval', $status_sets['complete'] ?? [])));
    }

    /**
     * Status of a GetShipmentDetails response. The API nests it under
     * ShipmentDetails[0].ShipmentInfo (that is what the WooCommerce plugin reads);
     * the top-level keys used by the first OpenCart 4 release are a fallback.
     *
     * The details of an AWB also list every return shipment whose Reference1 is that AWB: the entry
     * whose ShipmentAwb is the requested AWB is used, the first one only as a fallback.
     *
     * @return array ['code' => string, 'desc' => string, 'action' => string]
     */
    public static function parse_status(array $result, string $awb = ''): array {
        $info = $result['ShipmentDetails'][0]['ShipmentInfo'] ?? null;
        if ($awb !== '' && isset($result['ShipmentDetails']) && is_array($result['ShipmentDetails'])) {
            foreach ($result['ShipmentDetails'] as $detail) {
                $candidate = $detail['ShipmentInfo'] ?? null;
                if (is_array($candidate) && trim((string)($candidate['ShipmentAwb'] ?? '')) === $awb) {
                    $info = $candidate;
                    break;
                }
            }
        }
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

    /** Minutes left before the shipment may be asked again (0 = now). Manual, bulk and cron share the limit. */
    public static function wait_minutes(array $shipment, int $now = 0): int {
        $now = $now > 0 ? $now : time();
        $last = (int)($shipment['last_checked_at'] ?? 0);
        $elapsed = $now - $last;
        if ($last <= 0 || $elapsed >= self::MIN_INTERVAL) {
            return 0;
        }
        return max(1, (int)ceil((self::MIN_INTERVAL - $elapsed) / 60));
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

        $status = self::parse_status($result, $awb);
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

        $label = $source !== '' ? '[' . $source . '] ' : '';
        if ($code !== '' && $code !== (string)($shipment['status_code'] ?? '')) {
            $action = $status['action'] !== '' ? ' (Action: ' . $status['action'] . ')' : '';
            $this->history->add($order_id, '📍 ' . $label . 'Courier Center status: ' . $code . ' — ' . $status['desc'] . $action);
            $out['changed'] = true;
        }
        // Also when the code did not change: a delivered order that stayed in an active status
        // (e.g. auto-complete was off at the time) is completed on the next refresh.
        $out['completed'] = $this->maybe_auto_complete($order_id, $code, $label);
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
        $rows = $this->db->query($this->cron_sql())->rows;
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

    /**
     * Active (not voided, not final) shipments, oldest check first, at most CRON_LIMIT. With known
     * status sets only orders in an active or complete status (cancelled / refunded ones are skipped).
     */
    private function cron_sql(): string {
        $sql = "SELECT s.* FROM `" . $this->prefix . "cc_shipments` s";
        $where = "s.`is_voided` = 0 AND s.`is_final` = 0 AND s.`voucher_number` != ''";
        $statuses = array_unique(array_merge($this->active_statuses, $this->complete_statuses));
        if ($statuses) {
            $sql .= " JOIN `" . $this->prefix . "order` o ON o.`order_id` = s.`order_id`";
            $where .= " AND o.`order_status_id` IN (" . implode(',', array_map('intval', $statuses)) . ")";
        }
        return $sql . " WHERE " . $where . " ORDER BY (s.`last_checked_at` IS NULL) DESC, s.`last_checked_at` ASC, s.`order_id` ASC LIMIT " . self::CRON_LIMIT;
    }

    private function maybe_auto_complete(int $order_id, string $code, string $label): bool {
        if ($this->auto_complete_status_id <= 0 || !self::is_delivered($code)) {
            return false;
        }
        $cur = $this->db->query("SELECT `order_status_id` FROM `" . $this->prefix . "order` WHERE `order_id` = '" . $order_id . "' LIMIT 1");
        if (!$cur->num_rows || (int)$cur->row['order_status_id'] === $this->auto_complete_status_id) {
            return false;
        }
        $current = (int)$cur->row['order_status_id'];
        if ($current <= 0 || in_array($current, $this->complete_statuses, true)) {
            return false; // abandoned checkout, or already in one of the shop's complete statuses
        }
        if ($this->active_statuses && !in_array($current, $this->active_statuses, true)) {
            return false; // cancelled / refunded / on hold by the merchant ...: never auto-completed
        }
        return $this->history->add($order_id, '🚚 ' . $label . 'Courier Center: Η αποστολή παραδόθηκε — αυτόματη ολοκλήρωση παραγγελίας.', $this->auto_complete_status_id);
    }
}
