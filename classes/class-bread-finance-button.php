<?php 

/**
 * Button Manager
 */

namespace Bread_Finance\Classes;


class Bread_Finance_Button {
    
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
     * Plugin manager instance
     */
    public $bread_finance_plugin = false;
    
    /**
     * Utility Helper
     */
    public $bread_finance_utilities = false;

    public $bread_config = false;

    public $tenant_prefix;
    
    
    public function __construct() {
        if(!$this->bread_finance_plugin) {
            $this->bread_finance_plugin = Bread_Finance_Plugin::instance();
        }
        
        if(!$this->bread_finance_utilities) {
            $this->bread_finance_utilities = Bread_Finance_Utilities::instance();
        }

        if(!$this->bread_config) {
            $this->bread_config = \Bread_Finance\Classes\Config\Bread_Config::instance();
            $this->tenant_prefix = $this->bread_config->get('tenant_prefix');
        }
        add_action('wp',array($this, 'add_template_hooks'));
    }
    
    /**
     * Add template hooks for the bread button
     */
    public function add_template_hooks() {
        if (!$this->bread_finance_utilities->tenant_currency_equals_woocommerce_currency()) {
            return;
        }
        $use_custom_size = $this->bread_finance_plugin->get_bread_gateway()->get_configuration_setting('button_size') === 'custom';
        $wcAjax        = defined( 'WC_DOING_AJAX' ) ? $_GET['wc-ajax'] : false;
        
        //Category page Hooks
        $button_location_category = $this->bread_finance_plugin->get_bread_gateway()->get_configuration_setting('button_location_category') ? : false;
        if($this->bread_finance_utilities->getPageType()=== 'category' && $button_location_category) {
            $category_hook = explode( ':', $button_location_category);
            add_action('woocommerce_' . $category_hook[0], function () use ($use_custom_size) {
                print $this->conditionally_render_bread_button($use_custom_size);
            }, ( $category_hook[1] === 'before' ) ? 9 : 11 );
        }
        
        // Product Page Hooks
        $button_location_product = $this->bread_finance_plugin->get_bread_gateway()->get_configuration_setting('button_location_product') ? : false;
        if ($this->bread_finance_utilities->getPageType() === 'product' && $button_location_product) {
            /**
             * Allow the merchant to display the Bread button under the product price
             * Woocommerce does not have a hook for placing items directly under price, so will 
             * hook onto filter_price and append the bread button under
             */
            if ($button_location_product == 'get_price_html') {
                add_filter('woocommerce_get_price_html', function($price) use ($use_custom_size) {
                    return $price . '<br />' .
                            $this->conditionally_render_bread_button($use_custom_size);
                });
            } else {
                add_action('woocommerce_' . $button_location_product, function () use ($use_custom_size) {
                    print $this->conditionally_render_bread_button($use_custom_size);
                });
            }
        }

        // Add splitpay price underneath product price
        add_action('woocommerce_single_product_summary', function() {
            print '<div class="splitpay-clickable-price" style="margin:0;"></div>';
        });

        $gateway = $this->bread_finance_plugin->get_bread_gateway();
        //Cart summary page hooks
        if ($this->bread_finance_utilities->getPageType() === 'cart_summary' || $wcAjax === 'update_shipping_method') {
            global $woocommerce;


            $items = $woocommerce->cart->get_cart();
            foreach ($items as $item) {
                $product_id = $item["product_id"];
                $products_to_exclude = explode(",", $gateway->get_products_to_exclude());
                if (in_array($product_id, $products_to_exclude)) {
                    return;
                }
            }

            $woocommerce->cart->calculate_totals();
            $cart_total = $woocommerce->cart->get_cart_contents_total();

            $price_threshold_enabled = $gateway->is_price_threshold_enabled();
            $bread_threshold_amount = $gateway->get_price_threshold();

            // The method get_cart_contents_total() returns a string value. Use floatval to check if cart total is under price threshold 
            if ($price_threshold_enabled && floatval($cart_total) < floatval($bread_threshold_amount)) {
                return;
            }
            
            $button_location_cart = $gateway->get_configuration_setting('button_location_cart');
            if ($button_location_cart) {
                add_action('woocommerce_' . $button_location_cart, function () use ($use_custom_size) {
                    print $this->render_bread_button(array('buttonId' => "{$this->tenant_prefix}_checkout_button", 'buttonLocation' => $this->bread_finance_utilities->getPageType()), array(), $use_custom_size);
                });
            }
        }

        //Checkout page
        $bread_version = $gateway->get_configuration_setting('env_bread_api');
        $set_embedded = $this->bread_finance_plugin->get_bread_gateway()->get_configuration_setting('set_embedded') ?: false;
        $is_checkout_block = $this->bread_finance_utilities->is_checkout_block();
        if($bread_version === 'bread_2' && ($this->bread_finance_utilities->getPageType() === 'checkout' || $is_checkout_block)) {
            add_action( 'woocommerce_after_checkout_form', function() {
                print "<div id='{$this->tenant_prefix}_checkout_placeholder'></div>";
                print $this->render_embedded_container();
            });
            add_action( 'woocommerce_blocks_enqueue_checkout_block_scripts_before', function() {
                print "<div id='{$this->tenant_prefix}_checkout_placeholder'></div>";
                print $this->render_embedded_container();
            });
        }
    }

    
    /**
     * 
     * @global type $product
     * @param bool $use_custom_size
     * @return type
     */
    public function conditionally_render_bread_button($use_custom_size) {
        global $product;
        $is_composite = $product->get_type() === 'composite';
        
        //Check if the product exists
        if(!wc_get_product($product->get_id())) {
            return;
        }
        
        //is the product type supported
        if (!$this->bread_finance_plugin->supports_product($product)) {
            return;
        }

        //Check if product should be excluded by product ID
        $products_to_exclude = explode(",", $this->bread_finance_plugin->get_bread_gateway()->get_products_to_exclude());
        if (in_array($product->get_id(), $products_to_exclude)) {
            return;
        }
        
        //Check if product should be excluded by price threshold
        $price_threshold_enabled = $this->bread_finance_plugin->get_bread_gateway()->is_price_threshold_enabled();
        $bread_threshold_amount = $this->bread_finance_plugin->get_bread_gateway()->get_price_threshold();
        $show_button_for_composite = $this->bread_finance_plugin->get_bread_gateway()->show_button_for_composite();
    
        if(!$price_threshold_enabled || ($is_composite && $show_button_for_composite) || ($product->get_price() >= $bread_threshold_amount)) {
            //We are rendering the Bread Button here
            return $this->show_bread_button($product->get_id(), $product->get_type(), $use_custom_size);
        }
        return;
    }
    
    /**
     * 
     * @param type $product_id
     * @param type $product_type
     * @param bool $use_custom_size
     * @return string
     */
    public function show_bread_button($product_id, $product_type,  $use_custom_size = false) {
        if(!$this->bread_finance_plugin->get_bread_gateway()->enabled) {
            return;
        }
        
        $button_id = "{$this->tenant_prefix}_checkout_button_" . $product_id;
        
        $meta = array(
            'productId' => $product_id,
            'productType' => $product_type
        );

        $opts = array(
                    'buttonId' => $button_id,
                    'buttonLocation' => $this->bread_finance_utilities->getPageType(),
                );
        
        //Render Bread Button
        return $this->render_bread_button($opts, $meta, $use_custom_size);
    }
    
    public function render_bread_button($opts, $meta = array(), $custom_size = false) {
        $data_bind_bread = $meta;
        $data_bind_bread['opts'] = $opts;
        $title = $this->bread_finance_plugin->get_bread_gateway()->get_configuration_setting('title');

        $button_placeholder = <<<EOT
            <div id="{$this->tenant_prefix}-placeholder" class="{$this->tenant_prefix}-placeholder">
                <div class="{$this->tenant_prefix}-placeholder-inner">
                    <div class="{$this->tenant_prefix}-placeholder-center">
                        <div id="{$this->tenant_prefix}-placeholder-center-inner" class="{$this->tenant_prefix}-placeholder-center-inner">
                            <span class="{$this->tenant_prefix}-placeholder-text">{$title}</span>
                        </div>
                    </div>
                </div>
                <div id="{$this->tenant_prefix}-placeholder-icon" class="{$this->tenant_prefix}-placeholder-icon"></div>
            </div>
        EOT;
        
        $placeholder_content = is_product() ? ($this->bread_finance_plugin->get_bread_gateway()->get_configuration_setting('button_placeholder') ?: $button_placeholder) : '';
        
        /* Add splitpay price underneath PDP and cart page Bread buttons */
        $split_pay_content = '<div class="splitpay-clickable-button" style="margin:0;"></div>';

        $button_prevent_content = is_product() ? '<div class="button-prevent" id="button-prevent" style="display:block;"> <span class="buy_error_tip override_tip" data-content="Please complete product configuration">&nbsp;</span></div>' : '';
        
        $button_id = $opts['buttonId'];
        $data_bind_attribute = "data-bind='tenant: " . json_encode($data_bind_bread) . "'";
        $data_bind_test_attribute = "data-testid='test-" . $data_bind_bread['opts']['buttonId'] . "'";
        return <<<EOT
            <div id="{$this->tenant_prefix}-btn-cntnr">
                <div id="{$button_id}" 
                    data-view-model="woocommerce-gateway-bread"
                    class="{$this->tenant_prefix}-checkout-button" 
                    data-{$this->tenant_prefix}-default-size="{$custom_size}" {$data_bind_attribute} {$data_bind_test_attribute}> {$placeholder_content} 
                </div>
                {$split_pay_content}
                {$button_prevent_content}
            </div>
            EOT;
    }

    public function render_embedded_container() {
        return '<div id="'. $this->tenant_prefix .'-checkout-embedded"</div>';
    }

}

Bread_Finance_Button::instance();
