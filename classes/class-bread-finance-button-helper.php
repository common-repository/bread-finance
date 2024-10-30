<?php

/**
 * Button Helper
 */

namespace Bread_Finance\Classes;

class Bread_Finance_Button_Helper {
    
    /**
     * @var     array       Global front-end options applied to all button instances.
     */
    private $button_options_global;

    /**
     * Reference singleton instance of this class
     * 
     * @var $instance
     */
    private static $instance;
    
    /**
     * @var $bread_finance_plugin
     */
    public $bread_finance_plugin = false;
    
    /**
     *
     * @var type 
     */
    public $bread_finance_utilities = false;
    
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
        $this->button_options_global = array();
        if(!$this->bread_finance_plugin) {
            $this->bread_finance_plugin = \Bread_Finance\Classes\Bread_Finance_Plugin::instance();
        }
        
        if(!$this->bread_finance_utilities) {
            $this->bread_finance_utilities = \Bread_Finance\Classes\Bread_Finance_Utilities::instance();
        }
    }
    
    public function get_bread_options() {
        $pageType = $_REQUEST['page_type'];

        switch ($pageType) {
            case 'category':
                return $this->get_bread_options_for_category();
            case 'cart_summary':
                return $this->get_bread_options_for_cart_checkout();
            case 'checkout':
                return $this->get_bread_options_for_checkout();
            case 'product':
            case 'other':
                return $this->get_bread_options_for_product();
            default:
                return array();
        }
    }
    
    public function get_bread_options_for_checkout() {
        $button_options_checkout = \Bread_Finance\Classes\Bread_Finance_Options_Checkout::instance();
        $button_options = array_merge($button_options_checkout->get_options(isset($_REQUEST['form']) ? $_REQUEST['form'] : [], $this->button_options_global));
        return $button_options;
    }
    
    /**
     * Get Bread Options for Category Pages
     *
     * @return array    Configuration options for multiple buttons.
     */
    public function get_bread_options_for_category() {

        $buttonClass = \Bread_Finance\Classes\Bread_Finance_Options_Category::instance();

        $buttonOptions = array();
        foreach ($_REQUEST['configs'] as $config) {
            array_push($buttonOptions, array_merge($buttonClass->get_options($config), $this->button_options_global));
        }

        return array_filter($buttonOptions, function ($item) {
            return $item !== null;
        });
    }
    
    /**
     * Get Bread options for Product page
     */
    public function get_bread_options_for_product() {
        $buttonClass = \Bread_Finance\Classes\Bread_Finance_Options_Product::instance();       
        $button_options = array_merge($buttonClass->get_options($_REQUEST), $this->button_options_global);
        return $button_options;
    }
    
    //Get checkout options for cart page checkout
    public function get_bread_options_for_cart_checkout() {
        $buttonClass = \Bread_Finance\Classes\Bread_Finance_Options_Cart_Checkout::instance();
        
        $config = $_REQUEST['config'];
	    $form   = $_REQUEST['form'];
        
        $buttonOptions = array_merge($buttonClass->get_options($config, $form), $this->button_options_global);
        
        return $buttonOptions;
    }

    
    /**
     * Update the shipping contact of the active cart session.
     *
     * This works for both the active & temporary carts since we are selectively swapping in
     * our custom session handler when calculating tax/shipping.
     *
     * @param $shippingContact array
     * @param $billingContact array|null
     *
     * @throws \WC_Data_Exception
     */
    public function update_cart_contact($shipping_contact, $billing_contact = null) {

        $customer = WC()->customer;
        $customer->set_shipping_address_1($shipping_contact['address']);
        $customer->set_shipping_address_2($shipping_contact['address2']);
        $customer->set_shipping_city($shipping_contact['city']);
        $customer->set_shipping_state($shipping_contact['state']);
        $customer->set_shipping_postcode($shipping_contact['zip']);
        $customer->set_shipping_country($shipping_contact['country']);

        if ($billing_contact) {
            $customer->set_billing_address_1($billing_contact['address']);
            $customer->set_billing_address_2($billing_contact['address2']);
            $customer->set_billing_city($billing_contact['city']);
            $customer->set_billing_state($billing_contact['state']);
            $customer->set_billing_postcode($billing_contact['zip']);
            $customer->set_billing_country($billing_contact['country']);
        }

        if (isset($shipping_contact['selectedShippingOption'])) {
            $chosen = $shipping_contact['selectedShippingOption']['typeId'];
            WC()->session->set('chosen_shipping_methods', array('0' => $chosen));
        }

        WC()->cart->calculate_totals();
    }
    
    /*
     * The following functions are for creating a 'virtual' cart for the purposes of calculating tax and shipping
     * only. The cart is not persisted in any way and should not affect the items a customer may already have in their
     * cart.
     *
     * NOTE: These functions still rely on WC()->cart and WC()->customer globals. However, since these functions
     *       should never be called other than via specific AJAX requests, it is assumed that WC()->session
     *       will be our custom session handler, `WC_Session_Handler_Bread`
     *
     * function createBreadCart:    Create a virtual cart from the button options & shipping contact passed in
     *                              as ajax parameters.
     *
     * function getShipping:        Gets the shipping options for the virtual cart.
     *
     * function getTax:             Gets the tax amount for the virtual cart.
     */

    public function create_bread_cart($buttonOpts, $shippingContact, $billingContact = null) {

        if (!( defined('DOING_AJAX') && DOING_AJAX )) {
            return false;
        }

        if (!in_array($_REQUEST['action'], ['bread_calculate_tax', 'bread_calculate_shipping'])) {
            return false;
        }

        try {
            $cart = WC()->cart;
            $cart->empty_cart(true);

            foreach ($buttonOpts['items'] as $item) {
                $cart->add_to_cart($item['sku'], intval($item['quantity']));
            }

            $this->update_cart_contact($shippingContact, $billingContact);
        } catch (\Exception $e) {
            \Bread_Finance\Classes\Bread_Finance_Logger::log( 'Error: ' . $e->getMessage() );
            return new \WP_Error('bread-error-cart', __('Error creating temporary cart.', $this->bread_finance_plugin->get_text_domain()));
        }

        return true;
    }

    public function getShipping() {

        if (!WC()->cart->needs_shipping()) {
            return array('success' => true, 'data' => array());
        }

        $shipping = array();

        /*
         * For multi-package shipments, we just need the total combined shipping per-method since Bread doesn't
         * have any concept of a multi-package order.
         */
        WC()->shipping()->calculate_shipping(WC()->cart->get_shipping_packages());
        foreach (WC()->shipping()->get_packages() as $i => $package) {

            /** @var \WC_Shipping_Rate $method */
            foreach ($package['rates'] as $method) {

                if (array_key_exists($method->id, $shipping)) {
                    $shipping[$method->id]['cost'] += $this->bread_finance_utilities->priceToCents($method->cost);
                } else {
                    $shipping[$method->id] = array(
                        'typeId' => $method->id,
                        'cost' => $this->bread_finance_utilities->priceToCents($method->cost),
                        'type' => $method->get_label()
                    );
                }
            }
        }

        return array('success' => true, 'data' => array('shippingOptions' => array_values($shipping)));
    }

    public function getTax() {
        // In Avalara, shipping tax is already accounted for at cart and PDP, pass in any parameter
        $taxHelperResponse = $this->bread_finance_utilities->getTaxHelper(0);
        return ( wc_tax_enabled() ) ? array('success' => true, 'data' => array('tax' => $taxHelperResponse['tax'])) : array('success' => true, 'data' => array());
    }

}