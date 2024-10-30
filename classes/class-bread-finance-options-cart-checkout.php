<?php

/**
 * Cart page checkout
 */

namespace Bread_Finance\Classes;

class Bread_Finance_Options_Cart_Checkout extends \Bread_Finance\Classes\Bread_Finance_Options_Cart {
    
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
    
    public function __construct() {
        parent::__construct();
    }
    
    public function get_options($config, $form = array()) {
        $gateway = $this->bread_finance_plugin->get_bread_gateway();
        $bread_version = $gateway->get_configuration_setting('env_bread_api');
        if($bread_version === 'bread_2') {
            $options = array(
                'allowCheckout' => false,
                'buttonId' => $config['opts']['buttonId'],
            );

            if ($customCSS = $gateway->get_configuration_setting('button_custom_css')) {
                $options['customCSS'] = $customCSS;
            }

            if ($form) {
                $this->updateCartQuantities($form);
            }

            $options['items'] = $this->getItems();

            $cartTotal = $this->bread_finance_utilities->priceToCents(WC()->cart->get_total('float'));
            $options['customTotal'] = $cartTotal;
            $active_currency = $gateway->bread_finance_utilities->get_active_currency();
            $options['currency'] = $active_currency;

            return array_merge($options, $this->getShippingContact(), $this->getBillingContact(), $this->getDiscounts());

        } else {
            $options = array(
                'buttonId' => $config['opts']['buttonId'],
                'asLowAs' => $this->bread_finance_utilities->toBool($gateway->get_configuration_setting('button_as_low_as_cart')),
                'actAsLabel' => $this->bread_finance_utilities->toBool($gateway->get_configuration_setting('button_act_as_label_cart')),
                'allowCheckout' => $this->bread_finance_utilities->toBool($gateway->get_configuration_setting('button_checkout_cart')),
                'showInWindow' => $gateway->default_show_in_window(),
            );

            if ($customCSS = $gateway->get_configuration_setting('button_custom_css')) {
                $options['customCSS'] = $customCSS;
            }
            if ($form) {
                $this->updateCartQuantities($form);
            }

            $enableHealthcareMode = $gateway->is_healthcare_mode();
            $cartTotal = $this->bread_finance_utilities->priceToCents(WC()->cart->get_total('float'));
            if (!$enableHealthcareMode) {
                $options['items'] = $this->getItems();
            } else {
                $options['healthcareMode'] = true;
                $options['customTotal'] = $cartTotal;
            }

            $isTargetedFinancingEnabled = $gateway->is_targeted_financing_enabled();
            $targetedFinancingThreshold = $this->bread_finance_utilities->priceToCents($gateway->get_tf_price_threshold());
            if ($isTargetedFinancingEnabled && $cartTotal >= $targetedFinancingThreshold) {
                $options['financingProgramId'] = $gateway->get_financing_program_id();
            }
            
            $data = array_merge($options, $this->getShippingContact(), $this->getBillingContact(), $this->getDiscounts());
            $options['data'] = $data;
            return $data;
        }

    }
    
    /**
     * Update the active shopping cart quantities with those on the form at the time the Bread
     * button was clicked. Normally, a user would need to click 'Update Cart' to trigger a quantity
     * update, but we can't rely on a user to do so before clicking the Bread button.
     *
     * @param $form
     */
    private function updateCartQuantities($form) {

        foreach (WC()->cart->get_cart() as $id => $item) {

            $qtyField = array_filter($form, function ($field) use ($id) {
                return $field['name'] === sprintf('cart[%s][qty]', $id);
            });

            if (count($qtyField) === 1) {
                $qtyField = array_pop($qtyField);
                WC()->cart->set_quantity($id, intval($qtyField['value']));
            }
        }
    }
    
    /**
     * Get shopping cart items formatted for Bread `opts.items`
     *
     * NOTE: Cart items should always be discrete products.
     *
     * Grouped products for example, will appear in the cart as one line item per child product
     * selected. Similarly, composite products are added as multiple discrete products along with
     * the parent product. Variable & simple products are always added as a single Variation/Simple
     * product respectively.
     *
     * This is why here we can bypass the product-type button classes and treat every product  as
     * a simple product once it is in the cart.
     *
     * @return array
     */
    public function getItems() {

        /*
         * NOTE: In Variable products, the value in `$item['data']` is the selected variation of the main product.
         */
        $cart = WC()->cart->get_cart();

        $items = array();
        foreach ($cart as $id => $line) {
            $product = $line['data'];
            $item = $this->getItem($product);

            /*
             * Append extra item data to the item name (options, custom text, etc)
             */
            if (version_compare(WC()->version, '3.3.0', ">=")) {
                $item_data = wp_strip_all_tags(html_entity_decode(wc_get_formatted_cart_item_data($line, true)));
            } else {
                $item_data = wp_strip_all_tags(html_entity_decode(WC()->cart->get_item_data($line, true)));
            }

            if (strlen($item_data) > 0) {
                $item['name'] .= "\n" . $item_data;
            }

            /*
             * Using `line_subtotal` here since `line_total` is the discounted price. Discounts are applied
             * separately in the `discounts` element of the Bread options.
             */
            if (array_key_exists('composite_parent', $line)) {
                $composite_parent = $line['composite_parent'];
                $items[$composite_parent]['price'] += $this->bread_finance_utilities->priceToCents($line['line_subtotal'] / $items[$composite_parent]['quantity']);

                $item['price'] = 0;
            } else {
                $item['price'] = $this->bread_finance_utilities->priceToCents($line['line_subtotal'] / $line['quantity']);
            }

            $item['quantity'] = $line['quantity'];

            $item = array_merge($item, $this->getProductImageUrl($product));

            $items[$id] = $item;
        }

        return array_values($items);
    }

    public function getDiscounts() {

        /*
         * Borrowed from plugins/woocommerce/includes/wc-cart-functions.php->wc_cart_totals_coupon_html
         */
        $cart = WC()->cart;
        if (!$cart->has_discount()) {
            return array();
        }

        $discounts = array();

        /**
         * @var string $code
         * @var \WC_Coupon $coupon
         */
        foreach ($cart->get_coupons() as $code => $coupon) {
            if ($amount = $cart->get_coupon_discount_amount($code)) {
                $discounts[] = array(
                    'amount' => $this->bread_finance_utilities->priceToCents($amount),
                    'description' => $code
                );
            }
        }

        return array('discounts' => $discounts);
    }

}

