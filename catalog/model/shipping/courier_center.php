<?php
namespace Opencart\Catalog\Model\Extension\Couriercenter\Shipping;

class CourierCenter extends \Opencart\System\Engine\Model {

    /**
     * Called by OpenCart at checkout to get shipping quotes.
     *
     * Cost model: flat cost. Because OC4 selects the shipping method BEFORE the
     * payment method, a cash-on-delivery surcharge is offered as a SEPARATE
     * selectable quote ("Courier Center — Αντικαταβολή") so the customer can pick it.
     *
     * @param array $address Customer's shipping address
     * @return array
     */
    public function getQuote(array $address): array {
        if (!$this->config->get('shipping_courier_center_status')) {
            return [];
        }

        $this->load->language('extension/couriercenter/shipping/courier_center');

        // Geo-zone restriction (same logic as OC4 flat shipping)
        $geo_zone_id = (int)$this->config->get('shipping_courier_center_geo_zone_id');

        if ($geo_zone_id) {
            $query = $this->db->query(
                "SELECT * FROM `" . DB_PREFIX . "zone_to_geo_zone`
                 WHERE `geo_zone_id` = '" . $geo_zone_id . "'
                   AND `country_id` = '" . (int)$address['country_id'] . "'
                   AND (`zone_id` = '" . (int)$address['zone_id'] . "' OR `zone_id` = '0')"
            );
            $status = (bool)$query->num_rows;
        } else {
            $status = true;
        }

        if (!$status) {
            return [];
        }

        $tax_class_id = (int)$this->config->get('shipping_courier_center_tax_class_id');
        $cost         = (float)$this->config->get('shipping_courier_center_cost');
        $cod_fee      = (float)$this->config->get('shipping_courier_center_cod_fee');

        $quote_data = [];

        // ── Standard delivery ──────────────────────────────────────────
        $quote_data['courier_center'] = [
            'code'         => 'courier_center.courier_center',
            'name'         => $this->language->get('text_delivery'),
            'cost'         => $cost,
            'tax_class_id' => $tax_class_id,
            'text'         => $this->currency->format(
                $this->tax->calculate($cost, $tax_class_id, $this->config->get('config_tax')),
                $this->session->data['currency']
            ),
        ];

        // ── Cash on delivery (flat cost + COD surcharge) ───────────────
        if ($cod_fee > 0) {
            $cod_cost = $cost + $cod_fee;

            $quote_data['courier_center_cod'] = [
                'code'         => 'courier_center.courier_center_cod',
                'name'         => $this->language->get('text_delivery_cod'),
                'cost'         => $cod_cost,
                'tax_class_id' => $tax_class_id,
                'text'         => $this->currency->format(
                    $this->tax->calculate($cod_cost, $tax_class_id, $this->config->get('config_tax')),
                    $this->session->data['currency']
                ),
            ];
        }

        return [
            'code'       => 'courier_center',
            'name'       => $this->language->get('heading_title'),
            'quote'      => $quote_data,
            'sort_order' => $this->config->get('shipping_courier_center_sort_order'),
            'error'      => false,
        ];
    }
}
