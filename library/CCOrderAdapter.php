<?php
/**
 * CCOrderAdapter — OpenCart port
 * Wraps an OpenCart order array + products array to expose the same
 * interface that CCShipmentBuilder expects (mirrors WC_Order methods).
 *
 * For a courier/shipping extension we deliver to the SHIPPING address,
 * so every getter prefers shipping_* fields, then falls back to
 * payment_* fields, then to the customer-level fields.
 */

namespace Opencart\Extension\Couriercenter\Library;

class CCOrderAdapter {

    private array $order;
    private array $products; // from model_sale_order->getProducts($order_id)

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

    public function get_total(): float {
        return (float)($this->order['total'] ?? 0);
    }

    /**
     * OC4 stores payment_method as JSON {"code":"cod.cod","name":"..."}.
     */
    public function get_payment_method(): string {
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
