<?php
/**
 * "Test & Auto-fill": create a test shipment for an account, read the sender data the API keeps
 * for it (Shipper + PickupStation), void the test shipment. Used for the main account and for
 * every pickup point (sub-code). Shared by OpenCart 3 and 4, PHP 7.2+.
 */

namespace Opencart\Extension\Couriercenter\Library;

class CCAutofill {

    /**
     * @return array ['ok' => true, 'data' => [name, address, postcode, city, phone, station], 'warning' => string]
     *             | ['ok' => false, 'error' => string] (+ 'account_ok' => true when the account works but has no sender data)
     */
    public static function fetch(CCApi $api, array $context, string $account): array {
        $account = trim($account);
        if ($account === '') {
            return ['ok' => false, 'error' => 'Συμπλήρωσε πρώτα τον κωδικό.'];
        }
        $create = $api->create_shipment(self::payload($context, $account));
        if (isset($create['success']) && $create['success'] === false) {
            return ['ok' => false, 'error' => 'Βήμα 1 — ' . (string)($create['error'] ?? 'Αποτυχία δημιουργίας test αποστολής')];
        }
        $awb = (string)($create['ShipmentNumber'] ?? $create['VoucherNumber'] ?? $create['AwbNumber'] ?? '');
        if ($awb === '') {
            return ['ok' => false, 'error' => 'Το API δεν επέστρεψε αριθμό για την test αποστολή.'];
        }
        $details = $api->get_shipment_details($awb);
        $info = $details['ShipmentDetails'][0]['ShipmentInfo'] ?? [];
        $shipper = is_array($info['Shipper'] ?? null) ? $info['Shipper'] : [];
        $data = [
            'name'     => trim((string)($shipper['CompanyName'] ?? '')),
            'address'  => trim((string)($shipper['Address'] ?? '')),
            'postcode' => trim((string)($shipper['ZipCode'] ?? '')),
            'city'     => trim((string)($shipper['City'] ?? '')),
            'phone'    => trim((string)($shipper['Phones'] ?? '')),
            'station'  => trim((string)($info['PickupStation'] ?? '')),
        ];
        $warning = $api->void_shipment($awb) === true ? '' : 'Η test αποστολή ' . $awb . ' δεν ακυρώθηκε αυτόματα. Ακύρωσέ την από το Courier Center.';
        if ($data['name'] === '' && $data['address'] === '' && $data['postcode'] === '') {
            return ['ok' => false, 'account_ok' => true, 'warning' => $warning, 'error' => 'Ο κωδικός δουλεύει, αλλά το API δεν επέστρεψε στοιχεία αποστολέα. Συμπλήρωσέ τα χειροκίνητα.' . ($warning !== '' ? ' ' . $warning : '')];
        }
        return ['ok' => true, 'data' => $data, 'warning' => $warning];
    }

    /** The test shipment: Requestor = Shipper = the account being checked. */
    public static function payload(array $context, string $account): array {
        return [
            'Context'      => ['UserAlias' => (string)($context['UserAlias'] ?? ''), 'CredentialValue' => (string)($context['CredentialValue'] ?? ''), 'ApiKey' => (string)($context['ApiKey'] ?? '')],
            'shipmentDate' => date('Y-m-d'),
            'comments'     => 'TEST AUTOFILL - DELETE',
            'Requestor'    => ['CarrierBillingAccount' => $account],
            'Shipper'      => ['CarrierBillingAccount' => $account, 'CompanyName' => 'TEST', 'Address' => 'TEST',
                'ZipCode' => '10431', 'City' => 'ΑΘΗΝΑ', 'Phones' => '2101234567', 'Country' => 'GREECE', 'CountryCode' => 'GR'],
            'Consignee'    => ['CompanyName' => 'TEST', 'ContactName' => 'TEST', 'Address' => 'ΑΘΗΝΑ', 'City' => 'ΑΘΗΝΑ',
                'Area' => 'ΑΘΗΝΑ', 'ZipCode' => '10431', 'Country' => 'GR', 'Mobile1' => '2101234567'],
            'BillTo'       => 'Requestor',
            'BasicService' => '211',
            'Reference1'   => 'TEST-AUTOFILL',
            'Items'        => [['GoodsType' => 'NoDocs', 'Content' => 'TEST', 'IsDangerousGoods' => false,
                'IsDryIce' => false, 'IsFragile' => false, 'Weight' => ['Unit' => 'kg', 'Value' => 1.0]]],
        ];
    }
}
