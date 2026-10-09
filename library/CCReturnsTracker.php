<?php
/**
 * Returns: status tracking of the return shipments (with the «ΑΣΚΟΠΗ ΠΡΟΣΕΛΕΥΣΗ» rule). It never
 * touches the order (status, history): the return and the order's commercial state are independent.
 * Port of CC_Returns_Tracker (WooCommerce 1.6.0). Shared by OpenCart 3 and 4, PHP 7.2+.
 */

namespace Opencart\Extension\Couriercenter\Library;

class CCReturnsTracker {

    const FINAL_AFTER   = 5184000; // 60 days after the request → tracking stops
    const RECHECK_AFTER = 3600;
    const CRON_REFRESH  = 100;
    const CRON_VERIFY   = 50;

    /** @var CCReturnsService */
    private $service;

    public function __construct(CCReturnsService $service) {
        $this->service = $service;
    }

    /** @return array ['ok' => true, 'row'] | ['ok' => false, 'error'] (the row is left unchanged) */
    public function refresh(int $id): array {
        $repo = $this->service->repo();
        $row = $repo->get($id);
        if (!$row || $row['status'] !== CCReturnsRules::ST_CREATED || trim((string)$row['return_awb']) === '') {
            return ['ok' => false, 'error' => 'Η επιστροφή δεν έχει αποστολή για παρακολούθηση.'];
        }
        $res = $this->service->api()->get_shipment_details($row['return_awb']);
        $info = null;
        if (($res['Result'] ?? '') === 'Success' && !empty($res['ShipmentDetails']) && is_array($res['ShipmentDetails'])) {
            foreach ($res['ShipmentDetails'] as $detail) {
                $candidate = $detail['ShipmentInfo'] ?? null;
                if (is_array($candidate) && ($info === null || trim((string)($candidate['ShipmentAwb'] ?? '')) === $row['return_awb'])) {
                    $info = $candidate;
                }
            }
        }
        if ($info === null) {
            $msg = !empty($res['Errors'][0]['Message']) ? (string)$res['Errors'][0]['Message'] : (string)($res['error'] ?? 'Μη έγκυρη απάντηση του API');
            return ['ok' => false, 'error' => $msg];
        }
        $status = (string)($info['ShipmentStatus'] ?? '');
        $desc = (string)($info['ShipmentStatusDesc'] ?? '');
        $collection = (string)($info['CollectionStatus'] ?? '');
        $refs = [];
        foreach (['Reference1', 'Reference2', 'Reference3', 'Reference4'] as $k) {
            $refs[] = (string)($info[$k] ?? '');
        }
        $created = strtotime((string)$row['created_at']);
        $final = CCStatusTracker::is_final($status)
            || ($collection !== '' && (int)$collection === CCReturnsRules::COLLECTION_CANCELLED)
            || ($created !== false && $created < time() - self::FINAL_AFTER);
        $repo->update($row['id'], [
            'shipment_status'      => $status,
            'shipment_status_desc' => self::cut($desc, 100),
            'collection_status'    => $collection,
            'display_status'       => self::cut(CCReturnsRules::display_status($status, $desc, $collection, $refs), 100),
            'tracking_final'       => $final ? 1 : 0,
            'last_tracking_at'     => CCReturnsRepo::now(),
        ]);
        return ['ok' => true, 'row' => $repo->get($row['id'])];
    }

    /**
     * Cron step (after the normal shipments): release stale locks, verify the uncertain ones,
     * refresh the active returns. @return string[] report lines
     */
    public function cron(int $pause_us = 500000): array {
        $lines = [];
        $released = $this->service->releaseStaleLocks();
        if ($released) {
            $lines[] = 'RETURNS stale locks → verification: ' . $released;
        }
        foreach ($this->service->repo()->query(['status' => CCReturnsRules::ST_VERIFY, 'limit' => self::CRON_VERIFY, 'order' => 'ASC']) as $row) {
            $r = $this->service->verify($row['id']);
            $lines[] = 'RETURN #' . $row['id'] . ' verify: ' . ($r['ok'] ? 'created' : $r['error']);
        }
        $first = true;
        foreach ($this->service->repo()->dueForTracking(self::CRON_REFRESH, self::RECHECK_AFTER) as $id) {
            if (!$first && $pause_us > 0) {
                usleep($pause_us);
            }
            $first = false;
            $r = $this->refresh($id);
            $lines[] = 'RETURN #' . $id . ': ' . ($r['ok'] ? ($r['row']['display_status'] !== '' ? $r['row']['display_status'] : $r['row']['shipment_status']) : 'ERROR ' . $r['error']);
        }
        return $lines;
    }

    private static function cut(string $s, int $max): string {
        return function_exists('mb_substr') ? mb_substr($s, 0, $max, 'UTF-8') : substr($s, 0, $max);
    }
}
