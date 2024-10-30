<?php

/**
 * Product page helper
 * 
 * @author Maritim, Kip <kip.maritim@breadfinancial.com>
 * @copyright (c) 2023, BreadFinancial
 */

namespace Bread_Finance\Classes;

class Bread_Finance_Options_Product {

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
     * @var $bread_gateway
     */
    public $bread_gateway = false;

    /**
     * Init this
     */
    public function __construct() {
        if (!$this->bread_finance_plugin) {
            $this->bread_finance_plugin = Bread_Finance_Plugin::instance();
        }
        if (!$this->bread_finance_utilities) {
            $this->bread_finance_utilities = Bread_Finance_Utilities::instance();
        }
        
        if(!$this->bread_gateway) {
            $this->bread_gateway = $this->bread_finance_plugin->get_bread_gateway();
        }
    }

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
     * 
     * Fetch options object for product display
     * 
     * @param array $request
     * @return array
     */
    public function get_options($request) {
        $bread_version = $this->bread_gateway->get_configuration_setting('env_bread_api');
        switch($bread_version) {
            case 'bread_2';
                return $this->get_bread_2_options($request);
            case 'classic': 
            default:    
                return $this->get_bread_classic_options($request);
        }
    }
    
    /**
     * Get Bread platform options
     * 
     * @param array $request
     */
    public function get_bread_2_options($request){
        $config = $request['config'];
        $quantity = null;
        if(isset($request['quantity'])) {
            $quantity = $request['quantity'];
        }
        $options = array(
            'buttonId' => $config['opts']['buttonId'],
            'allowCheckout' => false
        );
        $active_currency = $this->bread_finance_utilities->get_active_currency();
        $options['currency'] = $active_currency;
        
        $productType = $config['productType'];
        $customTotal = 0;

        $product = wc_get_product($config['productId']);
        
        //Simple products
        if($productType === 'simple') {

            if (!is_object($product)) {
                return $options;
            }
            
            $item = $this->getItemForPlatform($product, $options['currency'], $quantity);
            $options['items'][] = $item;
            
            $options['customTotal'] = $item['unitPrice']['value'] * $item['quantity'];
        }
        
        //Variable products
        if ($productType === 'variable') {

            if (!is_object($product)) {
                return $options;
            }

            $item = $this->getItemForPlatform($product, $options['currency'], $quantity);

            if (isset($request['variation_id'])) {
                $variations = $product->get_available_variations();
                if (count($variations) >= 1) {
                    foreach ($variations as $variation) {
                        if ($request['variation_id'] == $variation['variation_id']) {
                            //Update to the variation price
                            $item['unitPrice']['value'] = $this->bread_finance_utilities->priceToCents($variation['display_price']);
                            $item['sku'] = $variation['sku'];
                        }
                    }
                }
            }
            $options['items'][] = $item;
            $options['customTotal'] = $item['unitPrice']['value'] * $item['quantity'];
        }
        
        //Grouped products
        if($productType === 'grouped') {
            $customTotal = 0;
            
            if (!is_object($product)) {
                return $options;
            }

            //When we first load, fetch minimum price
            if(is_null($quantity)) {
                $item = $this->getItemForPlatform($product, $options['currency'], $quantity);
                $options['items'][] = $item;
                $customTotal = $item['unitPrice']['value'];
            } else {             
                $children = $product->get_children();
                foreach($children as $childProduct) {
                    foreach($quantity as $id => $value) {
                        if($id == $childProduct && $value >= 1) {
                            $product = wc_get_product($childProduct);
                            $item = $this->getItemForPlatform($product, $options['currency'], $value);                           
                            $options['items'][] = $item;
                            $customTotal += $item['unitPrice']['value'] * $item['quantity'];
                        }
                    }
                }
            }
            $options['customTotal'] = $customTotal;
        }
        
        if($productType === 'composite') {
            $item = $this->getItemForPlatform($product, $options['currency'], $quantity);
            $compositePrice = $product->get_composite_price() ?: 0;
            $item['unitPrice']['value'] = $this->bread_finance_utilities->priceToCents($compositePrice);    
            $options['items'][] = $item;
            $customTotal = $item['unitPrice']['value'];
            $options['customTotal'] = $customTotal;
        }

        if (class_exists('WC_Product_Bundle') && $productType === 'bundle') {
            $item = $this->getItemForPlatform($product, $options['currency'], $quantity);
            $bundlePrice = $product->get_bundle_price() ?: 0;
            $item['unitPrice']['value'] = $this->bread_finance_utilities->priceToCents($bundlePrice);    
            $options['items'][] = $item;
            $options['customTotal'] = $item['unitPrice']['value'] * $item['quantity'];
        }
        
        $wooPaoCompatibility = $this->woocommerceProductAddOnsCompatibility($config['productId'], $request);
        if($wooPaoCompatibility['exists']) {
            $options['addons'] = $wooPaoCompatibility['addons'];
            $options['customTotal'] += $wooPaoCompatibility['addOnsTotal'];
        }
        
        $options['healthcareMode'] = $this->bread_gateway->is_healthcare_mode();
        
        return array_merge($options, $this->getBillingContact(), $this->getShippingContact());
    }
    
    /**
     * Get Bread classic options
     * 
     * @param array $request
     */
    public function get_bread_classic_options($request) {
        $config = $request['config'];
        $quantity = null;
        if(isset($request['quantity'])) {
            $quantity = $request['quantity'];
        }

        $options = array(
            'buttonId' => $config['opts']['buttonId'],
            'asLowAs' => $this->bread_finance_utilities->toBool($this->bread_gateway->get_configuration_setting('button_as_low_as_product')),
            'actAsLabel' => $this->bread_finance_utilities->toBool($this->bread_gateway->get_configuration_setting('button_act_as_label_product')),
            'allowCheckout' => $this->bread_finance_utilities->toBool($this->bread_gateway->get_configuration_setting('button_checkout_product')),
            'showInWindow' => $this->bread_gateway->default_show_in_window(),
        );
        
        $customCSS = $this->bread_gateway->get_configuration_setting('button_custom_css');
        if ($customCSS) {
            $options['customCSS'] = $customCSS;
        }
        
        
        $productType = $config['productType'];
        $customTotal = 0;
        
        //Simple products
        if($productType === 'simple') {
            $product = wc_get_product($config['productId']);

            if (!is_object($product)) {
                return $options;
            }
            
            $item = $this->getItem($product, $quantity);
            $options['items'][] = $item;
            
            $options['customTotal'] = $item['price'] * $item['quantity'];
        }
        
        //Variable products
        if ($productType === 'variable') {
            $product = wc_get_product($config['productId']);

            if (!is_object($product)) {
                return $options;
            }

            $item = $this->getItem($product, $quantity);

            if (isset($request['variation_id'])) {
                $variations = $product->get_available_variations();
                if (count($variations) >= 1) {
                    foreach ($variations as $variation) {
                        if ($request['variation_id'] == $variation['variation_id']) {
                            //Update to the variation price
                            $item['price'] = $this->bread_finance_utilities->priceToCents($variation['display_price']);
                            $item['sku'] = $variation['sku'];
                        }
                    }
                }
            }
            $options['items'][] = $item;
            $options['customTotal'] = $item['price'] * $item['quantity'];
        }
        
        //Grouped products
        if($productType === 'grouped') {
            $customTotal = 0;
            $product = wc_get_product($config['productId']);
            
            if (!is_object($product)) {
                return $options;
            }

            //When we first load, fetch minimum price
            if(is_null($quantity)) {
                $item = $this->getItem($product, $quantity);
                $options['items'][] = $item;
                $customTotal = $item['price'];
            } else {             
                $children = $product->get_children();
                foreach($children as $childProduct) {
                    foreach($quantity as $id => $value) {
                        if($id == $childProduct && $value >= 1) {
                            $product = wc_get_product($childProduct);
                            $item = $this->getItem($product, $value);
                            $options['items'][] = $item;
                            $customTotal += $item['price'];
                        }
                    }
                }
            }
            $options['customTotal'] = $customTotal;
        }
        
        if($productType === 'composite') {
            $product = wc_get_product($config['productId']);
            $item = $this->getItem($product, $quantity);
            $compositePrice = $product->get_composite_price() ?: 0;
            $item['price'] = $this->bread_finance_utilities->priceToCents($compositePrice);    
            $options['items'][] = $item;
            $customTotal = $item['price'];
            $options['customTotal'] = $customTotal;
        }
        
        $wooPaoCompatibility = $this->woocommerceProductAddOnsCompatibility($config['productId'], $request);
        if($wooPaoCompatibility['exists']) {
            $options['addons'] = $wooPaoCompatibility['addons'];
            $options['customTotal'] += $wooPaoCompatibility['addOnsTotal'];
        }
        
        $options['healthcareMode'] = $this->bread_gateway->is_healthcare_mode();
        
        
        $isTargetedFinancingEnabled = $this->bread_gateway->is_targeted_financing_enabled();
        $targetedFinancingThreshold = $this->bread_finance_utilities->priceToCents($this->bread_gateway->get_tf_price_threshold());
        if ($isTargetedFinancingEnabled && $options['customTotal'] >= $targetedFinancingThreshold) {
            $options['financingProgramId'] = $this->bread_gateway->get_financing_program_id();
        }

        return array_merge($options, $this->getBillingContact(), $this->getShippingContact());
    }
    
    /**
     * Gets the Bread `item` properties for a product.
     *
     * Variable, grouped and other product types eventually resolve to a simple or variation product
     * which have a common set of properties we can use to build out the item array.
     *
     * @param $product  \WC_Product
     *
     * @return array
     */
    protected function getItem($product, $quantity = null) {
        $item = array(
            'name' => wp_strip_all_tags($product->get_formatted_name()),
            'price' => $this->bread_finance_utilities->priceToCents($product->get_price()),
            'sku' => strval($product->get_id()),
            'detailUrl' => $product->get_permalink(),
            'quantity' => is_null($quantity) ? $product->get_min_purchase_quantity() : (int) $quantity
        );

        return array_merge($item, $this->getProductImageUrl($product));
    }
    
    /**
     * Gets the Bread `item` properties for a product on 2.0
     *
     * Variable, grouped and other product types eventually resolve to a simple or variation product
     * which have a common set of properties we can use to build out the item array.
     *
     * @param $product  \WC_Product
     *
     * @return array
     */
    protected function getItemForPlatform($product, $currency, $quantity = null) {
        $item = array(
            'name' => wp_strip_all_tags($product->get_formatted_name()),
            'quantity' => is_null($quantity) ? $product->get_min_purchase_quantity() : (int) $quantity,
            'shippingCost' => [
                'value' => 0,
                'currency' => $currency
            ],
            'shippingDescription' => '',
            'unitTax' => [
                'value' => 0,
                'currency' => $currency
            ],
            'unitPrice' => [
                'currency' => $currency,
                'value' => $this->bread_finance_utilities->priceToCents($product->get_price())
            ],
            'sku' => strval($product->get_id()),
        );

        return array_merge($item, $this->getProductImageUrl($product));
    }

    /**
     * @param $product  \WC_Product
     *
     * @return array
     */
    protected function getProductImageUrl($product) {
        $imageId = $product->get_image_id();
        if ($imageId) {
            return array('imageUrl' => wp_get_attachment_image_src($imageId)[0]);
        } else {
            return array();
        }
    }
    
    public function getBillingContact() {
        
        if ($this->bread_gateway->get_configuration_setting('pre_populate') === 'no') {
            return array();
        }
        
        /*
         * User has already pre-qualified. Do not send new contact information from these pages.
         */
        $qualstate = WC()->session->get('bread_qualstate') ?: 'NONE';
        if (in_array($qualstate, ['PREQUALIFIED', 'PARTIALLY_PREQUALIFIED'])) {
            return array($qualstate);
        }
        
        /*
         * User has not logged in or entered any checkout data.
         */
        if (WC()->customer->get_billing_address() === '') {
            return array();
        }

        $required = array('first_name', 'last_name', 'address_1', 'postcode', 'city', 'state', 'phone', 'email');

        
        $customer = WC()->customer;
        foreach ($required as $field) {
            if ("" === call_user_func(array($customer, 'get_billing_' . $field))) {
                return array();
            }
        }
        return array(
            'billingContact' => array(
                'firstName' => $customer->get_billing_first_name(),
                'lastName' => $customer->get_billing_last_name(),
                'address' => $customer->get_billing_address_1(),
                'address2' => $customer->get_billing_address_2(),
                'zip' => preg_replace('/[^0-9]/', '', $customer->get_billing_postcode()),
                'city' => $customer->get_billing_city(),
                'state' => $customer->get_billing_state(),
                'phone' => substr(preg_replace('/[^0-9]/', '', $customer->get_billing_phone()), - 10),
                'email' => $customer->get_billing_email()
            )
        );
    }
    
    public function getShippingContact() {
        
        if ($this->bread_gateway->get_configuration_setting('pre_populate') === 'no') {
            return array();
        }
        
        /*
         * User has already pre-qualified. Do not send new contact information from these pages.
         */
        $qualstate = WC()->session->get('bread_qualstate') ?: 'NONE';
        if (in_array($qualstate, ['PREQUALIFIED', 'PARTIALLY_PREQUALIFIED'])) {
            return array($qualstate);
        }
        
        /*
         * User has not logged in or entered any checkout data.
         */
        if (WC()->customer->get_shipping_address() === '') {
            return array();
        }

        $required = array('first_name', 'last_name', 'address_1', 'address_2', 'postcode', 'city', 'state', 'phone');

        
        $customer = WC()->customer;
        foreach ($required as $field) {
            if ("" === call_user_func(array($customer, 'get_shipping_' . $field))) {
                return array();
            }
        }
        return array(
            'shippingContact' => array(
                'firstName' => $customer->get_shipping_first_name(),
                'lastName' => $customer->get_shipping_last_name(),
                'address' => $customer->get_shipping_address_1(),
                'address2' => $customer->get_shipping_address_2(),
                'zip' => preg_replace('/[^0-9]/', '', $customer->get_shipping_postcode()),
                'city' => $customer->get_shipping_city(),
                'state' => $customer->get_shipping_state(),
                'phone' => substr(preg_replace('/[^0-9]/', '', $customer->get_shipping_phone()), - 10),
            )
        );
    }
    
    
    /*
     * Woocommerce product add ons compatibility
     * 
     * @param $productId
     * @param $request
     * @return array
     * @since 3.3.1
     */

    public function woocommerceProductAddOnsCompatibility($productId, $request) {
        
        $response = [
            'exists' => false,
            'errors' => false,
            'addOnsTotal' => 0,
            'addons' => []
        ];
        
        if (class_exists('WC_Product_Addons')) {
            $response['exists'] = true;
            $product_addons = \WC_Product_Addons_Helper::get_product_addons($productId);
            if (is_array($product_addons) && !empty($product_addons)) {
                $wc_pao_reflector = new \ReflectionClass('WC_Product_Addons');
                $classFileName = $wc_pao_reflector->getFileName();
                $directory = dirname($classFileName);

                foreach ($product_addons as $addon) {
                    // If type is heading, skip.
                    if ('heading' === $addon['type']) {
                        continue;
                    }

                    $value = wp_unslash(isset($request['addon-' . $addon['field_name']]) ? $request['addon-' . $addon['field_name']] : '');

                    switch ($addon['type']) {
                        case 'checkbox':
                            include_once $directory . '/includes/fields/class-wc-product-addons-field-list.php';
                            $field = new \WC_Product_Addons_Field_List($addon, $value);
                            break;
                        case 'multiple_choice':
                            switch ($addon['display']) {
                                case 'radiobutton':
                                    include_once $directory . '/includes/fields/class-wc-product-addons-field-list.php';
                                    $field = new \WC_Product_Addons_Field_List($addon, $value);
                                    break;
                                case 'images':
                                case 'select':
                                    include_once $directory . '/includes/fields/class-wc-product-addons-field-select.php';
                                    $field = new \WC_Product_Addons_Field_Select($addon, $value);
                                    break;
                            }
                            break;
                        case 'custom_text':
                        case 'custom_textarea':
                        case 'custom_price':
                        case 'input_multiplier':
                            include_once $directory . '/includes/fields/class-wc-product-addons-field-custom.php';
                            $field = new \WC_Product_Addons_Field_Custom($addon, $value);
                            break;
                        case 'file_upload':
                            include_once $directory . '/includes/fields/class-wc-product-addons-field-file-upload.php';
                            $field = new \WC_Product_Addons_Field_File_Upload($addon, $value);
                            break;
                    }

                    $data = $field->get_cart_item_data();

                    if (is_wp_error($data)) {
                        $response['errors'] = $data->get_error_message();
                    } elseif ($data) {
                        $response['addons'] = array_merge(apply_filters('woocommerce_product_addon_cart_item_data', $data, $addon, $productId, $request));

                        if (sizeof($response['addons']) >= 1) {
                            foreach ($response['addons'] as $addon) {
                                $response['addOnsTotal'] += $this->bread_finance_utilities->priceToCents($addon['price']);
                            }
                        }
                    }
                }
            }
        }
        return $response;
    }

}
