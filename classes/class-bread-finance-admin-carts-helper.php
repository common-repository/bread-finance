<?php 

/**
 * Bread Admin carts helper
 * 
 * @author Maritim, Kip
 * @since 3.3.1
 */

namespace Bread_Finance\Classes;

class Bread_Finance_Admin_Carts_Helper {
    
    /**
     * @var $api_version
     */
    public $api_version;
    
    /**
     *
     * @var $bread_finance_utilities 
     */
    public $bread_finance_utilities = false;
    
    /**
     * Reference singleton instance of this class
     * 
     * @var $instance
     */
    private static $instance;
    
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
    
    /**
     * Init
     */
    public function __construct() {
        if(!$this->bread_finance_utilities) {
            $this->bread_finance_utilities = \Bread_Finance\Classes\Bread_Finance_Utilities::instance();
        }
    }
    
    /**
     * Create cart opts for platform
     * 
     * @param $order The order we are processing the cart for
     * @return array 
     */
    public function create_cart_opts_platform($order, $merchantId, $programId) {
        $orderRef = strval($order->get_id());
        
        $opts = array(
                "callbackURL" => home_url() . '?orderRef=' . $orderRef . '&action=callback',
                "checkoutCompleteUrl" => home_url() . '?orderRef=' . $orderRef . '&action=checkout-complete',
                "checkoutErrorUrl" => home_url() . '?orderRef=' . $orderRef . '&action=checkout-error',
                "orderReference" => $orderRef,
                "merchantID" => $merchantId,
                "programID" => $programId
            );
            
            $opts['contact'] = array(
                'name' => array(
                    'givenName' => $order->get_billing_first_name(),
                    'familyName' => $order->get_billing_last_name(),
                ),
                'phone' => substr(preg_replace('/[^0-9]/', '', $order->get_billing_phone()), - 10),
                'shippingAddress' => array(
                    'firstName' => $order->get_shipping_first_name(),
                    'lastName' => $order->get_shipping_last_name(),
                    "address1" => $order->get_shipping_address_1(),
                    "address2" => $order->get_shipping_address_2(),
                    "locality" => $order->get_shipping_city(),
                    "postalCode" => $order->get_shipping_postcode(),
                    "region" => $order->get_shipping_state(),
                    "country" => $order->get_shipping_country()
                ),
                'billingAddress' => array(
                    'firstName' => $order->get_billing_first_name(),
                    'lastName' => $order->get_billing_last_name(),
                    "address1" => $order->get_billing_address_1(),
                    "address2" => $order->get_billing_address_2(),
                    "locality" => $order->get_billing_city(),
                    "postalCode" => $order->get_billing_postcode(),
                    "region" => $order->get_billing_state(),
                    "country" => $order->get_shipping_country()
                ),
                'email' => $order->get_billing_email(),                
            );
            
            //Calculate taxes
            if ($this->bread_finance_utilities->isAvataxEnabled()) {
                wc_avatax()->get_order_handler()->calculate_order_tax($order);
            }
            $tax_amount = $this->bread_finance_utilities->priceToCents($order->get_cart_tax() + $order->get_shipping_tax());
            
            //Calculate discounts
            $discount_amount = $this->bread_finance_utilities->priceToCents($order->get_discount_total());
            
            //Calculate shipping
            $shipping_amount = intval($order->get_shipping_total() * 100);
            
            //Get order currency
            $order_currency = $order->get_currency();

            $subtotal = $order->get_subtotal() * 100;

            /* Add line items */
            $items = array();
            $total = 0;
            foreach ($order->get_items() as $item_id => $item_data) {
                $product = wc_get_product($item_data['product_id']);
                if (!$product)
                    break;

                $imageId = $product->get_image_id();
                $imageUrl = $imageId ? wp_get_attachment_image_src($imageId)[0] : "";
                $detailUrl = get_permalink($product->get_id()) ?: "";
                $itemTotal = ($item_data->get_total() * 100);
                
                $item = array(                    
                    "name" => $product->get_name(),
                    "category" => '',
                    "quantity" => $item_data->get_quantity(),
                    "unitPrice" => array(
                      "currency" => "$order_currency",
                      "value" => $itemTotal
                    ),
                    "unitTax" => array(
                        "currency" => "$order_currency",
                        "value" => 0
                    ), 
                    "sku" => $product->get_sku(),
                    "itemUrl" => $detailUrl,
                    "imageUrl" => $imageUrl,
                    "description" => $product->get_description(),
                    "shippingCost" => array(
                        
                    ),
                    "shippingProvider" => "string",
                    "shippingDescription" => "string",
                    "shippingTrackingNumber" => "string",
                    "shippingTrackingUrl" => "string"
                );
                array_push($items, $item);
                $total += ($itemTotal * $item_data->get_quantity());
            }
            $opts["items"] = $items;
            
            $opts['order'] = array(
                "subTotal" => array(
                    "currency" => "$order_currency",
                    "value" => $subtotal
                ),
                "totalDiscounts" => array(
                    "currency" => "$order_currency",
                    "value" => $discount_amount
                ),
                "totalPrice" => array(
                    "currency" => "$order_currency",
                    "value" => ($order->get_total() * 100)
                ),
                "totalShipping" => array(
                    "currency" => "$order_currency",
                    "value" => $shipping_amount
                ),      
                "totalTax" => array(
                    "currency" => "$order_currency",
                    "value" => $tax_amount
                ),
                "discountCode" => "Discounts: " . implode(", ", $order->get_coupon_codes()),
            );
            
            return $opts;
    }
    
    /**
     * Validate cart opts for platform
     * 
     * @param $opts
     * @return string
     */
    public function validate_cart_opts_platform($opts) {
        if(isset($opts['order']['totalPrice']['value']) && $opts['order']['totalPrice']['value'] === 0) {
            return "total";
        }
        
        if (strlen($opts['contact']['phone']) === 0) {
            return "Phone Number";
        }

        $items = array(
            "firstName", "lastName", "address1", "locality", "region", "postalCode"
        );

        /* Check if billing contact is complete */
        foreach ($items as $item) {
            if (strlen($opts['contact']['billingAddress'][$item]) === 0) {
                return "billing " . $item;
            }
        }

        /* If shipping option provided, check if shipping contact is complete */
        if (count($opts['contact']['shippingAddress']) > 0) {
            foreach ($items as $item) {
                if (strlen($opts['contact']['shippingAddress'][$item]) === 0) {
                    return "shipping " . $item;
                }
            }
        }

        return "";
    }
    
    /**
     * Update platform cart custom fields
     * 
     * @param $order
     * @param $bread_cart
     * @return null
     */
    public function update_platform_cart_custom_fields($order, $bread_cart, $bread_api) {
        $bread_cart_link = isset($bread_cart["checkoutUrl"]) ? $bread_cart["checkoutUrl"] : null;
        $bread_cart_id = isset($bread_cart["id"]) ? $bread_cart["id"] : null;

        if ($bread_cart_link === null) {
            $order->add_order_note("Error: An error occurred. Please check the request body and try again.");
            //$this->log_Bread_issue("error", "[WCGateway] Bread cart link is null", $bread_cart);
        } else {
            $order->add_order_note("Bread cart link successfully created under the Custom Fields section. " . $bread_cart_link);
            if ($order->meta_exists("bread_cart_id")) {
                $bread_api->expireBreadCart($order->get_meta("bread_cart_id"));
                $order->update_meta_data("bread_cart_id", $bread_cart_id);
            } else {
                $order->add_meta_data("bread_cart_id", $bread_cart_id);
            }
        }

        if ($order->meta_exists("bread_cart_link")) {
            $order->update_meta_data("bread_cart_link", $bread_cart_link);
        } else {
            $order->add_meta_data("bread_cart_link", $bread_cart_link);
        }
        
        if(!$order->meta_exists("bread_api_version")) {
            $order->add_meta_data("bread_api_version", 'bread_2');
        }
        
        $order->save();
    }
}