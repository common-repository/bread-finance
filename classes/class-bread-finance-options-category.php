<?php

/**
 * Category page button helper
 */

namespace Bread_Finance\Classes;

class Bread_Finance_Options_Category extends Bread_Finance_Options_Cart {
    
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
    
    public function get_options( $config) {
        $gateway = $this->bread_finance_plugin->get_bread_gateway();
        $bread_config = $gateway->bread_config;
        $bread_version = $gateway->get_configuration_setting('env_bread_api');
        if($bread_version === 'bread_2') {
            $options = array(
                'buttonId' => $config['opts']['buttonId'],
                'allowCheckout' => false, // disable checkout from category pages
            );

            $items = $this->getItemsCategory($options, $config);
            $options['items'] = $items;
            $active_currency = $gateway->bread_finance_utilities->get_active_currency();
            $options['currency'] = $active_currency;

            return array_merge($options, $this->getBillingContact(), $this->getShippingContact());

        } else {
            $options = array(
                'buttonId' => $config['opts']['buttonId'],
                'asLowAs' => $this->bread_finance_utilities->toBool($gateway->get_configuration_setting('button_as_low_as_category')),
                'actAsLabel' => $this->bread_finance_utilities->toBool($gateway->get_configuration_setting('button_act_as_label_category')),
                'allowCheckout' => false, // disable checkout from category pages
                'showInWindow' => $gateway->default_show_in_window(),
            );

            if ($customCSS = $gateway->get_configuration_setting('button_custom_css')) {
                $options['customCSS'] = $customCSS;
            }

            if ($gateway->is_targeted_financing_enabled()) {
                $options['financingProgramId'] = $gateway->get_financing_program_id();
                $options['tfThreshold'] = $this->bread_finance_utilities->priceToCents($gateway->get_tf_price_threshold());
            }

            $items = $this->getItemsCategory($options, $config);
            $enableHealthcareMode = $gateway->is_healthcare_mode();

            if (!$enableHealthcareMode) {
                if (!empty($items)) {
                    $options['items'] = $items;
                }
            } else {
                $options['healthcareMode'] = true;
            }

            return array_merge($options, $this->getBillingContact(), $this->getShippingContact());
        }
    }
    
    /**
     * @return array
     */
    public function getItemsCategory(&$options, $config) {
        $product = wc_get_product($config['productId']);

        switch ($product->get_type()) {
            case 'simple':
                return $this->getItemsSimple($options, $config);
            case 'grouped':
                return $this->getItemsGrouped($options, $config);
            case 'variable':
                return $this->getItemsVariable($options, $config);
            case 'composite':
                return $this->getItemsComposite($options, $config);
            case 'bundle':
                return $this->getItemsBundle($options, $config);
            default:
                return array();
        }
    }

    public function getItemsSimple(&$options, $config) {
        $enableHealthcareMode = $this->bread_finance_plugin->get_bread_gateway()->is_healthcare_mode();
        if ($enableHealthcareMode) {
            $product = wc_get_product($config['productId']);
            $options['customTotal'] = $this->bread_finance_utilities->priceToCents($product->get_price());
        }
        return array($this->getItem(wc_get_product($config['productId'])));
    }

    public function getItemsGrouped(&$options, $config) {
        /*
         * Borrowed From `WC_Product_Grouped->get_price_html`
         */

        /** @var \WC_Product_Grouped $product */
        $product = wc_get_product($config['productId']);
        $children = array_filter(array_map('wc_get_product', $product->get_children()), 'wc_products_array_filter_visible_grouped');

        $prices = array();

        /** @var \WC_Product $child */
        foreach ($children as $child) {
            if ('' !== $child->get_price()) {
                $prices[] = $this->bread_finance_utilities->priceToCents($child->get_price());
            }
        }

        $options['allowCheckout'] = false;
        $options['asLowAs'] = true;
        $options['customTotal'] = min($prices);

        return array();
    }

    public function getItemsVariable(&$options, $config) {
        /*
         * Borrowed from `WC_Products_Variable->get_price_html`
         */

        $options['allowCheckout'] = false;
        $options['asLowAs'] = true;

        /** @var \WC_Product_Variable $product */
        $product = wc_get_product($config['productId']);

        $prices = $product->get_variation_prices();

        if (empty($prices['price'])) {
            $options['customTotal'] = $this->bread_finance_utilities->priceToCents($product->get_price());
        } else {
            $variationPrices = array_map(function ($price) {
                return $this->bread_finance_utilities->priceToCents($price);
            }, $prices['price']);

            $options['customTotal'] = min($variationPrices);
        }

        return array();
    }

    public function getItemsComposite(&$options, $config) {

        /** @var \WC_Product_Composite $product */
        $product = wc_get_product($config['productId']);
        $compositePrice = $product->get_composite_price() ?: 0;
        $productTotal = $product->get_price() ?: 0;

        $options['allowCheckout'] = false;
        $options['asLowAs'] = true;
        $options['customTotal'] = $this->bread_finance_utilities->priceToCents($compositePrice) ?: $this->bread_finance_utilities->priceToCents($productTotal);

        return array();
    }

    public function getItemsBundle(&$options, $config) {

        /** @var \WC_Product_Bundle $product */
        $product = wc_get_product($config['productId']);
        $bundlePrice = $product->get_bundle_price() ?: 0;
        $productTotal = $product->get_price() ?: 0;

        $options['allowCheckout'] = false;
        $options['asLowAs'] = true;
        $options['customTotal'] = $this->bread_finance_utilities->priceToCents($bundlePrice) ?: $this->bread_finance_utilities->priceToCents($productTotal);

        return array();
    }

}