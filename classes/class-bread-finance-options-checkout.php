<?php

/**
 * Handle checkout options
 */

namespace Bread_Finance\Classes;

class Bread_Finance_Options_Checkout extends \Bread_Finance\Classes\Bread_Finance_Options_Cart {
    
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
    
    public function get_options($config = null, $form = array()) {
        $gateway = $this->bread_finance_plugin->get_bread_gateway();
        $bread_version = $gateway->get_configuration_setting('env_bread_api');
        $page_type = isset($_REQUEST['page_type']) ? $_REQUEST['page_type'] : '';
        if($bread_version === 'bread_2') {
            $options = array(
                'allowCheckout' => true,
                'setEmbedded' => $this->bread_finance_utilities->toBool($gateway->get_configuration_setting('set_embedded')),
                'buttonLocation' => $page_type
            );

            //Get cart totals
            $cartTotal = $this->bread_finance_utilities->priceToCents(WC()->cart->get_total('float'));
            $cartSubtotal = $this->bread_finance_utilities->priceToCents(WC()->cart->get_subtotal('float'));
            
            //Get Discounts
            $discountResponse = $this->getDiscounts();
            $discountTotal = $this->bread_finance_utilities->getDiscountTotal($discountResponse["discounts"] ?? array());
            
            /* 
             * Include shipping cost in tax calculations to ensure 
             * Avalara accounts for shipping tax amount 
             */
            $shippingCost = 0;
            $shippingResponse = $this->getShipping();
            if(isset($shippingResponse['shippingOptions'][0]['cost'])) {
                $shippingCost = $shippingResponse['shippingOptions'][0]['cost'];
            }
            $options['shippingCountry'] = WC()->customer->get_shipping_country();
            
            //Get tax
            $taxResponse = $this->getTax($shippingCost);
            $taxTotal = $taxResponse['tax'];
         
            //Get items

            $options['items'] = $this->getItems();

            /* Add all fees as line items because Bread SDK doesn't have fee or additional cost option */
            $fee_line_items = $this->getFeesAsLineItems();
            if ($fee_line_items) {
                $options['items'] = array_merge($options['items'], $fee_line_items);
                $cartSubtotal += array_sum(array_column($fee_line_items, 'price'));
            }

            //Totals
            $options['subTotal'] = $cartSubtotal;
            $options['customTotal'] = ($cartSubtotal + $shippingCost + $taxTotal) - $discountTotal;
            $options['cartTotal'] = $cartTotal;

            $bread_config = $gateway->bread_config;
            //Currency options
            $active_currency = $gateway->bread_finance_utilities->get_active_currency();
            $options['currency'] = $active_currency;

            return array_merge($options, $this->getBillingContact(), $this->getShippingContact(), $discountResponse, $taxResponse, $shippingResponse);

        } else {
            $options = array(
                'allowCheckout' => true,
                'asLowAs' => $this->bread_finance_utilities->toBool($gateway->get_configuration_setting('button_as_low_as_checkout')),
                'actAsLabel' => false,
                'buttonLocation' => $page_type,
                'showInWindow' => $gateway->default_show_in_window(),
                'disableEditShipping' => true,
            );

            $options['customTotal'] = $this->bread_finance_utilities->priceToCents(WC()->cart->get_total('float'));
            $cartSubtotal = $this->bread_finance_utilities->priceToCents(WC()->cart->get_subtotal('float'));

            /* Include shipping cost in tax calculations to ensure Avalara accounts for shipping tax amount */
            $shippingCost = 0;
            $shippingResponse = $this->getShipping();
            if(isset($shippingResponse['shippingOptions'][0]['cost'])) {
                $shippingCost = $shippingResponse['shippingOptions'][0]['cost'];
            }
            $taxResponse = $this->getTax($shippingCost);
            $discountResponse = $this->getDiscounts();

            /* If AvaTax is enabled, calculate customTotal using subtotal + shipping cost + tax - discounts */
            if ($this->bread_finance_utilities->isAvataxEnabled()) {
                $discountTotal = $this->bread_finance_utilities->getDiscountTotal($discountResponse["discounts"] ?: array());
                $cartSubtotal = $this->bread_finance_utilities->priceToCents(WC()->cart->get_subtotal('float'));
                $options['customTotal'] = ($cartSubtotal + $shippingCost + $taxResponse['tax']) - $discountTotal;
            }

            /* If healthcare mode enabled, do not add line items */
            $enableHealthcareMode = $gateway->is_healthcare_mode();
            if (!$enableHealthcareMode) {
                $options['items'] = $this->getItems();
                //unset($options["customTotal"]);
            } else {
                $options['healthcareMode'] = true;
            }

            /* If targeted financing enabled, compare cart subtotal with targeted financing threshold */
            $isTargetedFinancingEnabled = $gateway->is_targeted_financing_enabled();
            $targetedFinancingThreshold = $this->bread_finance_utilities->priceToCents($gateway->get_tf_price_threshold());
            if ($isTargetedFinancingEnabled && $cartSubtotal >= $targetedFinancingThreshold) {
                $options['financingProgramId'] = $gateway->get_financing_program_id();
            }
            
            return array_merge($options, $this->getBillingContact(), $this->getShippingContact(), $discountResponse, $taxResponse, $shippingResponse);
        }
    }
    
    /**
     * Get the total shipping for this order.
     *
     * @return array
     */
    public function getShipping() {

        if (!WC()->cart->needs_shipping()) {
            return array();
        }

        $chosenMethods = WC()->session->get('chosen_shipping_methods');

        /*
         * For single-package shipments we can use the chosen shipping method title, otherwise use a generic
         * title.
         */
        WC()->shipping()->calculate_shipping(WC()->cart->get_shipping_packages());
        if (count($chosenMethods) === 1) {
            $shippingMethods = $this->bread_finance_utilities->get_wc_shipping_methods();
            if ($shippingMethods) {
                $chosenMethod = $shippingMethods[explode(':', $chosenMethods[0])[0]];
                $shipping[] = array(
                    'typeId' => $chosenMethod->id,
                    'cost' => $this->bread_finance_utilities->priceToCents(WC()->cart->shipping_total),
                    'type' => $chosenMethod->method_title
                );
            } else {
                $shipping[] = array();
            }
        } else {
            $shipping[] = array(
                'typeId' => 0,
                'cost' => $this->bread_finance_utilities->priceToCents(WC()->cart->shipping_total),
                'type' => esc_html__('Shipping', $this->bread_finance_plugin->get_text_domain())
            );
        }

        return array('shippingOptions' => $shipping);
    }

    public function getTax($shippingCost) {
        $taxHelperResponse = $this->bread_finance_utilities->getTaxHelper($shippingCost);
        return (wc_tax_enabled()) ? array('tax' => $taxHelperResponse['tax']) : array('tax'=>0);
    }

    public function getFeesAsLineItems() {
        /*
         * Returns all fees as line item array. Fee price will be in cents
        */
        WC()->cart->calculate_fees();
        $fee_line_items = [];
        $fees = WC()->cart->get_fees();

        foreach ($fees as $fee) {
            $fee_amount = $this->bread_finance_utilities->priceToCents(floatval($fee->amount));
            $line_item = [
                "name" => $fee->name,
                "price" => $fee_amount,
                "quantity" => 1
            ];
            array_push($fee_line_items, $line_item);
        }

        return $fee_line_items;
    }

}
