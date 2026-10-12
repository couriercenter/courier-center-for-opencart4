<?php
/**
 * CCOrderAdapter — OpenCart port
 * Wraps an OpenCart order array + products array to expose the same
 * interface that CCShipmentBuilder expects (mirrors WC_Order methods).
 *
 * For a courier/shipping extension we deliver to the SHIPPING address,
 * so every getter prefers shipping_* fields, then falls back to
 * payment_* fields, then to the customer-level fields.
 *
 * PHP 7.2+ syntax (no typed properties) - shared by the OpenCart 3 and 4 extensions.
 */

namespace Opencart\Extension\Couriercenter\Library;

class CCOrderAdapter {

    /** @var array */
    private $order;
    /** @var array from model_sale_order->getProducts($order_id) */
    private $products;

    /**
     * @param array $order    Row from model_sale_order->getOrder($order_id)
     * @param array $products Rows from model_sale_order->getProducts($order_id)
     */
    public function __construct(array $order, array $products = []) {
        $this->order    = $order;
        $this->products = $products;
    }

    /**
     * Pick the first non-empty value across shipping → payment → customer-level keys.
     */
    private function pick(string $shipping_key, string $payment_key = '', string $fallback_key = ''): string {
        $v = trim((string)($this->order[$shipping_key] ?? ''));
        if ($v !== '') return $v;
        if ($payment_key) {
            $v = trim((string)($this->order[$payment_key] ?? ''));
            if ($v !== '') return $v;
        }
        if ($fallback_key) {
            $v = trim((string)($this->order[$fallback_key] ?? ''));
            if ($v !== '') return $v;
        }
        return '';
    }

    /**
     * Value of one of the order's address custom fields (OpenCart custom_field_id), shipping address
     * first, then the payment address; '' when empty. Used for a separate "street number" field.
     */
    public function get_custom_field_value($custom_field_id): string {
        $id = trim((string)$custom_field_id);
        if ($id === '') {
            return '';
        }
        foreach (['shipping_custom_field', 'payment_custom_field'] as $key) {
            $cf = $this->order[$key] ?? [];
            if (!is_array($cf)) {
                $cf = json_decode((string)$cf, true);
            }
            if (is_array($cf) && isset($cf[$id]) && is_scalar($cf[$id]) && trim((string)$cf[$id]) !== '') {
                return trim(html_entity_decode((string)$cf[$id], ENT_QUOTES, 'UTF-8'));
            }
        }
        return '';
    }

    public function get_id(): int {
        return (int)($this->order['order_id'] ?? 0);
    }

    public function get_billing_first_name(): string {
        return $this->pick('shipping_firstname', 'payment_firstname', 'firstname');
    }

    public function get_billing_last_name(): string {
        return $this->pick('shipping_lastname', 'payment_lastname', 'lastname');
    }

    public function get_billing_company(): string {
        return $this->pick('shipping_company', 'payment_company');
    }

    public function get_billing_address_1(): string {
        return $this->pick('shipping_address_1', 'payment_address_1');
    }

    public function get_billing_address_2(): string {
        return $this->pick('shipping_address_2', 'payment_address_2');
    }

    public function get_billing_city(): string {
        return $this->pick('shipping_city', 'payment_city');
    }

    public function get_billing_postcode(): string {
        $pc = $this->pick('shipping_postcode', 'payment_postcode');
        return preg_replace('/\s+/', '', $pc);
    }

    public function get_billing_phone(): string {
        return $this->pick('telephone');
    }

    /**
     * Returns 2-letter ISO country code (e.g. 'GR').
     * getOrder() derives *_iso_code_2 via a country-table join.
     */
    public function get_billing_country(): string {
        $c = $this->pick('shipping_iso_code_2', 'payment_iso_code_2');
        return $c !== '' ? $c : 'GR';
    }

    /** The customer's checkout comment (order['comment']), as plain text. */
    public function get_customer_note(): string {
        return trim(html_entity_decode((string)($this->order['comment'] ?? ''), ENT_QUOTES, 'UTF-8'));
    }

    public function get_total(): float {
        return (float)($this->order['total'] ?? 0);
    }

    /**
     * Payment method code.
     * OpenCart 3 (and 4.0.0-4.0.1): order['payment_code'], e.g. "cod" - order['payment_method'] is only the title there.
     * OpenCart 4.0.2+: order['payment_method'] = ['code' => 'cod.cod', 'name' => ...] (or that JSON as a string).
     */
    public function get_payment_method(): string {
        $code = $this->order['payment_code'] ?? '';
        if (is_string($code) && trim($code) !== '') {
            return trim($code);
        }
        $pm = $this->order['payment_method'] ?? '';
        if (is_array($pm)) {
            return (string)($pm['code'] ?? '');
        }
        // Older shape / already-decoded string
        return (string)$pm;
    }

    /**
     * Cash-on-delivery detection — OC4 COD code is typically 'cod.cod' or 'cod'.
     */
    public function is_cod(): bool {
        $code = strtolower($this->get_payment_method());
        return $code !== '' && strpos($code, 'cod') !== false;
    }

    /**
     * Returns the products array for weight/dimension calculation.
     * Each row has: weight, length, width, height, quantity
     */
    public function get_items(): array {
        return $this->products;
    }

    public function raw(): array {
        return $this->order;
    }
}
