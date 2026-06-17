<?php
/**
 * CCShipmentBuilder — OpenCart port
 * Accurate port of class-cc-shipment-builder.php (WooCommerce plugin)
 * Replaces WC_Order with CCOrderAdapter, WP_Error with string|true returns,
 * get_option() with $settings array, CC_City_Scope with CCCityScope
 */

namespace Opencart\Extension\Couriercenter\Library;

class CCShipmentBuilder {

    const SERVICE_CODES = [
        'next_day'    => '211',
        'same_day_3h' => '031',
        'same_day_5h' => '051',
    ];

    const DEFAULT_WEIGHT_KG = 1.0;

    private CCOrderAdapter $order;
    private array $settings;
    private int $parcel_count;

    /**
     * @param CCOrderAdapter $order
     * @param array $settings Keys: user_alias, credential_value, api_key, billing_account,
     *                               shipper_name, shipper_address, shipper_postal, shipper_city,
     *                               shipper_phone, shipper_station (derived from postcode)
     * @param int $parcel_count
     */
    public function __construct(CCOrderAdapter $order, array $settings, int $parcel_count = 1) {
        $this->order        = $order;
        $this->settings     = $settings;
        $this->parcel_count = max(1, $parcel_count);
    }

    /**
     * Validate required settings
     *
     * @return true|string Returns true on success, error message string on failure
     */
    public function validate_settings(): true|string {
        $required = [
            'user_alias'      => 'User Alias',
            'credential_value'=> 'Credential Value',
            'api_key'         => 'API Key',
            'billing_account' => 'Carrier Billing Account',
            'shipper_name'    => 'Επωνυμία αποστολέα',
            'shipper_address' => 'Διεύθυνση αποστολέα',
            'shipper_postal'  => 'ΤΚ αποστολέα',
            'shipper_city'    => 'Πόλη αποστολέα',
            'shipper_phone'   => 'Τηλέφωνο αποστολέα',
        ];
        $missing = [];
        foreach ($required as $key => $label) {
            if (empty($this->settings[$key])) $missing[] = $label;
        }
        if ($missing) return 'Λείπουν ρυθμίσεις: ' . implode(', ', $missing);
        return true;
    }

    /**
     * Validate the order (consignee data, weight/dimension limits)
     *
     * @return true|string Returns true on success, error message string on failure
     */
    public function validate_order(): true|string {
        $first    = $this->order->get_billing_first_name();
        $last     = $this->order->get_billing_last_name();
        $address  = $this->order->get_billing_address_1();
        $city     = $this->order->get_billing_city();
        $postcode = $this->order->get_billing_postcode();
        $phone    = $this->order->get_billing_phone();

        if (empty($first) && empty($last)) return 'Λείπει το όνομα παραλήπτη.';
        if (empty($address))               return 'Λείπει η διεύθυνση παραλήπτη.';
        if (empty($city))                  return 'Λείπει η πόλη παραλήπτη.';
        if (empty($postcode))              return 'Λείπει ο ΤΚ παραλήπτη.';
        if (empty($phone))                 return 'Λείπει το τηλέφωνο παραλήπτη.';

        if (!preg_match('/^\d{5}$/', $postcode)) {
            return 'Μη έγκυρος ΤΚ παραλήπτη. Πρέπει να είναι 5 ψηφία (π.χ. 12241).';
        }

        $weight = $this->get_order_weight();
        if ($weight > 30) {
            return sprintf('Το βάρος (%.1f kg) υπερβαίνει το μέγιστο των 30 kg ανά τεμάχιο.', $weight);
        }

        $dimensions = $this->get_order_dimensions();
        if (!empty($dimensions)) {
            $l = (float)$dimensions['length'];
            $w = (float)$dimensions['width'];
            $h = (float)$dimensions['height'];

            if (max($l, $w, $h) > 180) {
                return sprintf('Η μεγαλύτερη πλευρά (%.0f cm) υπερβαίνει το μέγιστο των 180 cm.', max($l, $w, $h));
            }
            $girth = $l + 2 * ($w + $h);
            if ($girth > 300) {
                return sprintf('Ο συνολικός όγκος αποστολής (%.0f cm) υπερβαίνει το μέγιστο. Επικοινωνήστε με την Courier Center.', $girth);
            }
        }

        $country = $this->order->get_billing_country();
        if ($country !== 'GR' && $this->is_cod()) {
            return 'Η αντικαταβολή δεν επιτρέπεται για αποστολές εξωτερικού.';
        }

        return true;
    }

    /**
     * Build the full payload for POST /api/Shipment
     *
     * @param string $service_type 'next_day' | 'same_day_3h' | 'same_day_5h'
     * @param bool   $boxnow       Whether to use BOX NOW locker delivery
     * @param string $return_option 'none' | 'optional' | 'mandatory'
     */
    public function build_payload(string $service_type = 'next_day', bool $boxnow = false, string $return_option = 'none'): array {
        if ($service_type === 'next_day' && !empty($this->settings['shipper_station'])) {
            $result        = CCCityScope::resolve_next_day_service(
                $this->settings['shipper_station'],
                $this->order->get_billing_postcode()
            );
            $basic_service = $result['service_code'];
        } else {
            $basic_service = self::SERVICE_CODES[$service_type] ?? '211';
        }

        $payload = [
            'Context'      => [
                'UserAlias'       => $this->settings['user_alias'],
                'CredentialValue' => $this->settings['credential_value'],
                'ApiKey'          => $this->settings['api_key'],
            ],
            'shipmentDate' => date('Y-m-d'),
            'comments'     => 'Order #' . $this->order->get_id(),
            'Requestor'    => [
                'CarrierBillingAccount' => $this->settings['billing_account'],
            ],
            'Shipper'      => [
                'CarrierBillingAccount' => $this->settings['billing_account'],
                'CompanyName'           => $this->settings['shipper_name'],
                'ContactName'           => $this->settings['shipper_name'],
                'Address'               => $this->settings['shipper_address'],
                'City'                  => $this->settings['shipper_city'],
                'Area'                  => $this->settings['shipper_city'],
                'ZipCode'               => $this->settings['shipper_postal'],
                'Country'               => 'GR',
                'Mobile1'               => $this->settings['shipper_phone'],
            ],
            'Consignee'    => $this->build_consignee(),
            'BillTo'       => 'Requestor',
            'BasicService' => $basic_service,
            'Reference1'   => 'OC-' . $this->order->get_id(),
            'NoOfItems'    => $this->parcel_count,
            'Items'        => $this->build_items(),
        ];

        if ($this->is_cod()) {
            $payload['CODs'] = [[
                'Type'   => 'Cash',
                'Amount' => ['Currency' => 'EUR', 'Value' => $this->order->get_total()],
            ]];
        }

        if ($boxnow) {
            // BOX NOW: locker code would come from order meta / session data
            $payload['LockerDeliveryInfo'] = ['Prefix' => 'BOXNOW'];
        }

        if ($return_option === 'mandatory') {
            $payload['IsMandatoryPickup'] = true;
            $payload['GenerateReturnAWB'] = true;
        } elseif ($return_option === 'optional') {
            $payload['IsMandatoryPickup'] = false;
            $payload['GenerateReturnAWB'] = true;
        }

        return $payload;
    }

    private function build_consignee(): array {
        $first   = $this->order->get_billing_first_name();
        $last    = $this->order->get_billing_last_name();
        $name    = trim($first . ' ' . $last);
        $company = $this->order->get_billing_company() ?: $name;

        $address = trim(
            $this->order->get_billing_address_1() . ' ' .
            $this->order->get_billing_address_2()
        );

        $country = $this->order->get_billing_country() ?: 'GR';

        return [
            'CompanyName' => $company,
            'ContactName' => $name,
            'Address'     => $address,
            'City'        => $this->order->get_billing_city(),
            'Area'        => $this->order->get_billing_city(),
            'ZipCode'     => $this->order->get_billing_postcode(),
            'Country'     => $country,
            'CountryCode' => $country,
            'Mobile1'     => $this->order->get_billing_phone(),
        ];
    }

    private function build_items(): array {
        $dimensions = $this->get_order_dimensions();
        $weight     = $this->get_order_weight();

        $item = [
            'GoodsType'        => 'NoDocs',
            'Content'          => 'ΔΕΜΑΤΑ',
            'IsDangerousGoods' => false,
            'IsDryIce'         => false,
            'IsFragile'        => false,
            'Weight'           => ['Unit' => 'kg', 'Value' => $weight],
        ];

        if (!empty($dimensions)) {
            $item['Length'] = ['Unit' => 'cm', 'Value' => (float)$dimensions['length']];
            $item['Width']  = ['Unit' => 'cm', 'Value' => (float)$dimensions['width']];
            $item['Height'] = ['Unit' => 'cm', 'Value' => (float)$dimensions['height']];
        }

        return array_fill(0, $this->parcel_count, $item);
    }

    private function get_order_weight(): float {
        $weight = 0.0;
        foreach ($this->order->get_items() as $product) {
            $w   = (float)($product['weight'] ?? 0);
            $qty = (int)($product['quantity'] ?? 1);
            $weight += $w * $qty;
        }
        return $weight > 0 ? $weight : self::DEFAULT_WEIGHT_KG;
    }

    private function get_order_dimensions(): array {
        foreach ($this->order->get_items() as $product) {
            $l = (float)($product['length'] ?? 0);
            $w = (float)($product['width']  ?? 0);
            $h = (float)($product['height'] ?? 0);
            if ($l > 0 && $w > 0 && $h > 0) {
                return ['length' => $l, 'width' => $w, 'height' => $h];
            }
        }
        return [];
    }

    private function is_cod(): bool {
        return $this->order->is_cod();
    }
}
