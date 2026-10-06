<?php
/**
 * Shipment status of a GetShipmentDetails response — shared by the order panel,
 * the bulk "Ενημέρωση Status" action and the cron script.
 */

namespace Opencart\Extension\Couriercenter\Library;

class CCStatusTracker {

    /** Final statuses — polling stops (same list as the WooCommerce plugin). */
    const FINAL_CODES = [
        '29',  // ΠΑΡΑΔΟΘΗΚΕ
        '25',  // ΕΠΙΣΤΡΑΦΗΚΕ ΣΤΟΝ ΑΠΟΣΤΟΛΕΑ
        '99',  // ΑΚΥΡΩΜΕΝΗ ΑΠΟΣΤΟΛΗ
        '14',  // ΑΚΥΡΩΜΕΝΗ ΠΑΡΑΓΓΕΛΙΑ
        '87',  // CLEVER POINT - ΠΑΡΑΛΗΦΘΗΚΕ
        '95',  // ΑΠΟΖΗΜΙΩΘΗΚΕ
    ];

    /** Delivered statuses — trigger the optional auto-complete of the order. */
    const DELIVERED_CODES = ['29', '87'];

    /**
     * The API nests the status under ShipmentDetails[0].ShipmentInfo (that is what
     * the WooCommerce plugin reads). The top-level StatusCode/DeliveryStatus keys
     * that 1.0.0 read do not exist in the response; they are kept as a fallback only.
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

    public static function is_final(string $code): bool {
        return in_array($code, self::FINAL_CODES, true);
    }

    public static function is_delivered(string $code): bool {
        return in_array($code, self::DELIVERED_CODES, true);
    }
}
