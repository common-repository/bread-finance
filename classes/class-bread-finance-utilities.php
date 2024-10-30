<?php

/**
 * Class Utility helper
 */

namespace Bread_Finance\Classes;


if(!defined('ABSPATH')) {
    exit;    
}

/**
 * Utility manager helper class
 * 
 */
class Bread_Finance_Utilities {
    
    /**
     * Our custom boolean values
     */
    private $boolvals = array( 'yes', 'on', 'true', 'checked' );

    
    /**
     * Reference singleton instance of this class
     * 
     * @var $instance
     */
    private static $instance;
    
    private $bread_config;
    /**
     * 
     * Return singleton instance of this class
     * 
     * @return object self::$instance
     */
    public static function instance() {
        if (null == self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function __construct() {
        if (!$this->bread_config) {
            $this->bread_config = \Bread_Finance\Classes\Config\Bread_Config::instance();
        }
    }
      
    /**
     * Capitalize the first letter and lower-case all remaining letters of a string.
     *
     * @param $string
     *
     * @return string
     */
    public function properCase($string) {
        return mb_strtoupper(substr($string, 0, 1)) . mb_strtolower(substr($string, 1));
    }

    /**
     * Checks the parameter, usually a string form value, for truthiness (i.e. yes, on, checked).
     * If the parameter is not a string value, use the native type-coercion function.
     *
     * @param $value mixed        The value to check.
     *
     * @return bool
     */
    public function toBool($value) {
        return is_string($value) ? in_array(strtolower($value), $this->boolvals) : boolval($value);
    }

    /**
     * Convert a price value in dollars to cents.
     *
     * @param $price
     *
     * @return int
     */
    public function priceToCents($price) {
        /**
         * Convert price to float
         * 
         * @since 3.3.0
         */
        try {
            $decimal_separator = wc_get_price_decimal_separator();
            if (!is_numeric(str_replace([',', $decimal_separator], ['', '.'], $price))) {
                throw new \InvalidArgumentException("Invalid price format");
            }
            $price = str_replace(',', '', $price);
            $floatPrice = floatval($price);

            if ($floatPrice < 0) {
                throw new \InvalidArgumentException("Negative price value");
            }

            $split_price = explode($decimal_separator, number_format($floatPrice, 2, '.', ''));

            $dollars = intval($split_price[0]) * 100;
            $cents = ( count($split_price) > 1 ) ? intval(str_pad($split_price[1], 2, '0')) : 0;

            return $dollars + $cents;
        } catch (\InvalidArgumentException $e) {
            throw $e;
        } catch (\Exception $e) {
            Bread_Finance_Logger::log( 'Error in priceToCents: ' . $e->getMessage() );
            return 0;
        }
    }

    /**
     * Convert a price value in cents to dollars.
     *
     * @param $price
     * @param $quantity
     *
     * @return float
     */
    public function priceToDollars($price, $quantity = 1) {
        return round($price / 100 * $quantity, 2);
    }

    /**
     * Get the current WooCommerce page type. If no page type can be determined, as can be the case when using
     * shortcode, default to 'Product'.
     *
     * NOTE: The return values of this function correspond with the Bread `buttonLocation` option allowed values.
     *
     * @return string
     */
    public function getPageType() {

        if (is_post_type_archive('product') || is_product_category() || is_shop()) {
            return 'category';
        }

        if (is_product()) {
            return 'product';
        }

        if (is_cart()) {
            return 'cart_summary';
        }

        if ($this->is_checkout_block()) {
            return 'checkout_block';
        }

        if (is_checkout()) {
            return 'checkout';
        }

        return 'other';
    }

    public function getProductType() {

        if (is_product()) {
            global $product, $post;

            if (is_string($product)) {
                if (!isset($post)) {
                    $post = get_page_by_path($product, OBJECT, 'product');
                }
                $currentProduct = wc_get_product($post->ID);
            } elseif (is_null($product)) {
                $currentProduct = wc_get_product($post->ID);
            } else {
                $currentProduct = $product;
            }

            if (is_object($currentProduct)) {
                return $currentProduct->get_type();
            }
        }

        return '';
    }

    public function validateCalculatedTotals($order, $transaction, $bread_finance_plugin) {
        $bread_api_version = $bread_finance_plugin->get_bread_gateway()->load_bread_env();
        if($bread_api_version === 'bread_2') {
            /* Validate calculated totals */
            if (abs($this->priceToCents($order->get_total()) - $transaction['totalAmount']['value']) > 2) {
                $message = esc_html__("Transaction amount does not equal order total.", $bread_finance_plugin->get_text_domain());
                $order->update_status("failed", $message);
                $this->json_error($this->bread_config->get('tenant_name') . " transaction total does not match order total.", $bread_finance_plugin->get_text_domain());
            }

            if (floatval($order->get_total_tax()) !== floatval($transaction['taxAmount']['value'] / 100)) {
                $order->add_order_note($this->bread_config->get('tenant_name') . " tax total does not match order tax total.");
            }

            if (floatval($order->get_shipping_total()) !== floatval($transaction['shippingAmount']['value'] / 100)) {
                $order->add_order_note($this->bread_config->get('tenant_name') . " shipping total does not match order shipping total.");
            }

        } else {
            /* Validate calculated totals */
            if (abs($this->priceToCents($order->get_total()) - $transaction['adjustedTotal']) > 2) {
                $message = esc_html__("Transaction amount does not equal order total.", $bread_finance_plugin->get_text_domain());
                $order->update_status("failed", $message);
                $this->json_error($this->bread_config->get('tenant_name') . " transaction total does not match order total.", $bread_finance_plugin->get_text_domain());
            }

            if (floatval($order->get_total_tax()) !== floatval($transaction['totalTax'] / 100)) {
                $order->add_order_note($this->bread_config->get('tenant_name') . " tax total does not match order tax total.");
            }

            if (floatval($order->get_shipping_total()) !== floatval($transaction['shippingCost'] / 100)) {
                $order->add_order_note($this->bread_config->get('tenant_name') . " shipping total does not match order shipping total.");
            }
        }
    }

    /**
     * wp_send_json_error sends json response back and halts further execution.
     */
    public function json_error($message, $text_domain = "") {
        wp_send_json_error(__($message, $text_domain));
    }

    /**
     * wp_send_json_success sends json response back and halts further execution.
     */
    public function json_success($message) {
        wp_send_json_success($message);
    }

    /**
     * Check if Avalara tax plugin exists and is enabled
     *
     * @return bool
     */
    public function isAvataxEnabled() {
        return function_exists('wc_avatax') && wc_avatax()->get_tax_handler()->is_enabled();
    }

    public function getTaxHelper($shippingCost) {
        $tax = 0;
        $cart = WC()->cart;

        /* For merchants using AvaTax, use Avalara method to calculate tax on virtual cart */
        if ($this->isAvataxEnabled()) {
            $cart->set_shipping_total($shippingCost); // At checkout, Avalara needs shipping cost to calculate shipping tax properly
            $avaResponse = wc_avatax()->get_api()->calculate_cart_tax($cart);
            $tax = $this->priceToCents($avaResponse->response_data->totalTax);
        } else {
            $tax = $this->priceToCents($cart->get_taxes_total());
        }

        return array('tax' => $tax);
    }

    public function getDiscountTotal($discounts) {
        return array_reduce($discounts, function($sum, $current) {
            return $sum += $current["amount"] ?: 0;
        }, 0);
    }

    /**
     * Get the active currency from third-party currency plugins.
     * Defaults to currency set in config.yaml or 'USD' if its not set
     */
    public function get_active_currency() {
        $bread_currency_instance = \Bread_Finance\Classes\Compat\Bread_Finance_Currency::instance();
        $bread_config_currency = $this->bread_config->get('currency', 'USD');
        return $bread_currency_instance->get_active_currency($bread_config_currency);
    }

    public function tenant_currency_equals_woocommerce_currency() {
        $active_currency = $this->get_active_currency();
        $bread_config_currency = $this->bread_config->get('currency', 'USD');

        return strcasecmp($active_currency, $bread_config_currency) == 0;
    }

    function get_base_store_address() {
        return [
            'address'   => WC()->countries->get_base_address(),
            'address2'  => WC()->countries->get_base_address_2(),
            'city'      => WC()->countries->get_base_city(),
            'zip'       => WC()->countries->get_base_postcode(),
            'country'   => WC()->countries->get_base_country(),
            'state'     => WC()->countries->get_base_state()
        ];
    }

    function get_checkout_block_pickup_location($pickupLocations) {
        if (count($pickupLocations) >= 1 && isset($pickupLocations[0]['address'])) {
            $address = $pickupLocations[0]['address'];
            return [
                'address'   => $address['address_1'],
                'address2'  => $address['address_2'] ?? '',
                'city'      => $address['city'],
                'zip'       => $address['postcode'],
                'country'   => $address['country'],
                'state'     => $address['state']
            ];
        }
    }

    function keys_present_and_not_null($data, $keys) {
        $missingOrNullKeys = [];

        foreach ($keys as $key) {
            if (!array_key_exists($key, $data) || is_null($data[$key])) {
                $missingOrNullKeys[] = $key;
            }
        }

        if (!empty($missingOrNullKeys)) {
            throw new \Exception("The following keys are missing or have null values: " . implode(', ', $missingOrNullKeys) . ".");
        }
        return true;
    }

    function is_checkout_block() {
        if (!function_exists('has_block')) {
            return false;
        }
        $checkout_page_id = wc_get_page_id( 'checkout' );
        return $checkout_page_id && has_block( 'woocommerce/checkout', $checkout_page_id );
    }

    function get_wc_shipping_methods() {
        return WC()->shipping()->get_shipping_methods();
    }

    function get_checkout_flags() {
        $captcha_plugin = \Bread_Finance\Classes\Compat\Bread_Finance_Captcha::instance();
        return "bread-{$captcha_plugin->get_post_key()}";
    }
}