<?php
/**
 * Returns: the Create Shipment payload of a return (Drop Off, Pickup or BOX NOW). Pure: validates the
 * request row and returns the payload; the normal shipment payload (CCShipmentBuilder) is untouched.
 * Port of CC_Return_Payload (WooCommerce 1.6.0). Shared by OpenCart 3 and 4, PHP 7.2+.
 */

namespace Opencart\Extension\Couriercenter\Library;

class CCReturnPayload {

    /**
     * @param array $req  request row: method, original_awb, reference_token, return_account_code,
     *                    requestor_account, bill_to, pickup_date, pickup_time_from, pickup_time_to,
     *                    shipper_details{name,address,city,postcode,phone,courier_notes}, locker_reference, order_id
     * @param array $ctx  ['UserAlias', 'CredentialValue', 'ApiKey']
     * @param float $weight_kg weight of the (single) parcel
     * @return array payload | ['error' => string]
     */
    public static function build(array $req, array $ctx, float $weight_kg): array {
        $method = (string)($req['method'] ?? '');
        if (!in_array($method, CCReturnsRules::METHODS_ORDER, true)) {
            return ['error' => 'Η επιλεγμένη μέθοδος επιστροφής δεν είναι διαθέσιμη.'];
        }
        $pickup = $method === CCReturnsRules::METHOD_PICKUP;
        $awb = trim((string)($req['original_awb'] ?? ''));
        if ($awb === '') {
            return ['error' => 'Λείπει ο αριθμός αποστολής της αρχικής παραγγελίας.'];
        }
        $token = trim((string)($req['reference_token'] ?? ''));
        if ($token === '') {
            return ['error' => 'Λείπει ο κωδικός αναφοράς του αιτήματος επιστροφής.'];
        }
        $account = trim((string)($req['return_account_code'] ?? ''));
        if (!CCReturnsRules::is_valid_account_code($account)) {
            return ['error' => 'Ο λογαριασμός επιστροφών δεν είναι έγκυρος.'];
        }
        $requestor = trim((string)($req['requestor_account'] ?? ''));
        if ($requestor === '') {
            return ['error' => 'Λείπει ο κωδικός πελάτη (Requestor) του καταστήματος.'];
        }
        $details = (isset($req['shipper_details']) && is_array($req['shipper_details'])) ? $req['shipper_details'] : [];
        $name = trim((string)($details['name'] ?? ''));
        if ($name === '') {
            return ['error' => 'Λείπει το όνομα του αποστολέα της επιστροφής.'];
        }
        $address = trim((string)($details['address'] ?? ''));
        if ($method !== CCReturnsRules::METHOD_BOXNOW && $address === '') {
            return ['error' => 'Λείπει η διεύθυνση του αποστολέα της επιστροφής.'];
        }
        $city = trim((string)($details['city'] ?? ''));
        $postcode = (string)preg_replace('/\s+/', '', (string)($details['postcode'] ?? ''));
        $date = (string)($req['pickup_date'] ?? '');
        $from = trim((string)($req['pickup_time_from'] ?? ''));
        $to = trim((string)($req['pickup_time_to'] ?? ''));
        if ($pickup) {
            if ($city === '') {
                return ['error' => 'Λείπει η πόλη της διεύθυνσης παραλαβής.'];
            }
            if (preg_match('/^\d{5}$/D', $postcode) !== 1) {
                return ['error' => 'Ο ΤΚ της διεύθυνσης παραλαβής πρέπει να έχει 5 ψηφία.'];
            }
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if (!$d || $d->format('Y-m-d') !== $date) {
                return ['error' => CCReturnsRules::MSG_PICKUP_DATE];
            }
            $window = CCReturnsRules::pickup_window_error($from, $to);
            if ($window !== '') {
                return ['error' => $window];
            }
        }
        $phone = (string)($details['phone'] ?? '');
        $picked = $method === CCReturnsRules::METHOD_BOXNOW
            ? CCReturnsRules::pick_phone($method, $phone, '')
            : CCReturnsRules::pick_phone($method, $phone, $phone);
        if (isset($picked['error'])) {
            return $picked;
        }
        $locker = '';
        if ($method === CCReturnsRules::METHOD_BOXNOW) {
            $locker = is_string($req['locker_reference'] ?? null) ? $req['locker_reference'] : '';
            if (!in_array($locker, ['1', '2', '3'], true)) {
                return ['error' => 'Επιλέξτε μέγεθος BOX NOW locker (Μικρό, Μεσαίο ή Μεγάλο).'];
            }
        }

        $shipper = ['CompanyName' => $name, 'ContactName' => $name, 'Country' => 'GR', 'Mobile1' => $picked['phone']];
        if ($method === CCReturnsRules::METHOD_BOXNOW) {
            $shipper = array_merge($shipper, CCReturnsRules::BOXNOW_SHIPPER);
        } elseif ($pickup) {
            $shipper['Address'] = $address;
            $shipper['City'] = $city;
            $shipper['Area'] = $city;
            $shipper['ZipCode'] = $postcode;
        } else {
            $shipper['Address'] = $address;
            $shipper = array_merge($shipper, CCReturnsRules::DROPOFF_SHIPPER);
        }

        $payload = [
            'Context'      => [
                'UserAlias'       => (string)($ctx['UserAlias'] ?? ''),
                'CredentialValue' => (string)($ctx['CredentialValue'] ?? ''),
                'ApiKey'          => (string)($ctx['ApiKey'] ?? ''),
            ],
            'shipmentDate' => date('Y-m-d'),
            'comments'     => 'ΕΠΙΣΤΡΟΦΗ OC-' . (int)($req['order_id'] ?? 0),
            'Requestor'    => ['CarrierBillingAccount' => $requestor],
            'Shipper'      => $shipper,
            // Consignee = the returns account (the API fills in its full data)
            'Consignee'    => ['CarrierBillingAccount' => $account],
            'BillTo'       => (($req['bill_to'] ?? '') === 'Consignee') ? 'Consignee' : 'Requestor',
            'Reference1'   => $awb,
            'Reference2'   => $token,
            'NoOfItems'    => 1,
            // Like the normal parcel, but always ONE (the original may have had several)
            'Items'        => [[
                'GoodsType'        => 'NoDocs',
                'Content'          => 'ΔΕΜΑΤΑ',
                'IsDangerousGoods' => false,
                'IsDryIce'         => false,
                'IsFragile'        => false,
                'Weight'           => ['Unit' => 'kg', 'Value' => $weight_kg],
            ]],
        ];
        if ($method === CCReturnsRules::METHOD_BOXNOW) {
            $payload['LockerPickupInfo'] = ['reference' => $locker];
        }
        if ($pickup) {
            // The pickup date goes in ShipmentDate (confirmed 2026-10-12); no window → the whole working day.
            unset($payload['shipmentDate']);
            $payload['ShipmentDate'] = $date;
            $notes = trim((string)preg_replace('/\s+/u', ' ', (string)($details['courier_notes'] ?? '')));
            $payload['CollectionInstructions'] = [
                'TimeFrom' => $from !== '' ? $from : CCReturnsRules::PICKUP_DEFAULT_FROM,
                'TimeTo'   => $to !== '' ? $to : CCReturnsRules::PICKUP_DEFAULT_TO,
                'Comments' => function_exists('mb_substr') ? mb_substr($notes, 0, CCReturnsRules::COURIER_NOTES_MAX, 'UTF-8') : substr($notes, 0, CCReturnsRules::COURIER_NOTES_MAX),
            ];
        }
        return $payload;
    }
}
