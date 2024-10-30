<?php

/**
 * Bread Finance admin fields
 * 
 * @package Bread_finance/Classes
 */

namespace Bread_Finance\Classes;


if (!defined('ABSPATH')) {
    exit;
}

Class Bread_Finance_Form_Fields {

    private $bread_config;

    private static $instance;

    public function __construct() {
        if (!$this->bread_config) {
            $this->bread_config = \Bread_Finance\Classes\Config\Bread_Config::instance();
        }
    }

    public static function instance() {
        if (null == self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function fields($text_domain) {
        $general = array(
            'enabled' => array(
                'title' => esc_html__('Enable / Disable', $text_domain),
                'label' => esc_html__('Enable this gateway', $text_domain),
                'type' => 'checkbox',
                'default' => 'no',
            ),
            'title' => array(
                'title' => esc_html__('Title', $text_domain),
                'type' => 'text',
                'desc_tip' => esc_html__('Payment method title that the customer will see during checkout.', $text_domain),
                'default' => esc_html__('Pay Over Time', $text_domain),
            ),
            'description' => array(
                'title' => esc_html__('Description', $text_domain),
                'type' => 'textarea',
                'desc_tip' => esc_html__('Payment method description that the customer will see during checkout.', $text_domain),
                'default' => esc_html__($this->bread_config->get('tenant_name') . ' lets you pay over time for the things you need.', $text_domain),
            ),
            'display_icon' => array(
                'title' => esc_html__('Display ' . $this->bread_config->get('tenant_name') . ' Icon', $text_domain),
                'label' => esc_html__('Display the ' . $this->bread_config->get('tenant_name') . ' icon next to the payment method title during checkout.', $text_domain),
                'type' => 'checkbox',
                'default' => 'no'
            ),
            'pre_populate' => array(
                'title' => esc_html__('Auto-Populate Forms', $text_domain),
                'label' => esc_html__('Auto-populate ' . $this->bread_config->get('tenant_name') . ' form fields for logged-in WooCommerce users.', $text_domain),
                'type' => 'checkbox',
                'default' => 'yes',
            ),
            'default_payment' => array(
                'title' => esc_html__($this->bread_config->get('tenant_name') . ' as Default', $text_domain),
                'label' => esc_html__('Upon successful customer prequalification at product and category pages, set ' . $this->bread_config->get('tenant_name') . ' as the default payment option at checkout.', $text_domain),
                'type' => 'checkbox',
                'default' => 'yes',
            ),
            'auto_cancel' => array(
                'title' => esc_html__('Auto-cancel Failed Split-Pay Orders (Classic)', $text_domain),
                'label' => esc_html__('When credit cards are declined, this will automatically cancel the pending transaction.', $text_domain),
                'type' => 'checkbox',
                'default' => 'no',
                'custom_attributes' => [
                    'versions' => ['classic']
                ]
            ),
            'sentry_enabled' => array(
                'title' => esc_html__('Send Error Information to ' . $this->bread_config->get('tenant_name'), $text_domain),
                'label' => esc_html__('Proactively send information about any ' . $this->bread_config->get('tenant_name') . ' related issues.', $text_domain),
                'type' => 'checkbox',
                'default' => 'no',
            ),
            'set_embedded' => array(
                'title' => esc_html__('Embedded Checkout', $text_domain),
                'label' => esc_html__('The embedded functionality renders the bread checkout experience directly on the page instead of as a pop-up modal.', $text_domain),
                'type' => 'checkbox',
                'default' => 'no',
            ),
        );
        $environment = array(
            'api_settings' => array(
                'title' => esc_html__('API Environment Settings', $text_domain),
                'type' => 'title'
            ),
            'env_bread_api' => array(
                'title' => esc_html__($this->bread_config->get('tenant_name') . ' API version', $text_domain),
                'type' => 'select',
                'desc_tip' => esc_html__('Select the ' . $this->bread_config->get('tenant_name') . ' API version to use for transactions. Contact your ' . $this->bread_config->get('tenant_name') . ' success manager if you are not sure what this is', $text_domain),
                'default' => $this->bread_config->get('default_sdk_version'),
                'options' => $this->bread_config->get('sdk_versions')
            ),
            'environment' => array(
                'title' => esc_html__('Environment', $text_domain),
                'type' => 'select',
                'desc_tip' => esc_html__('Select the gateway environment to use for transactions', $text_domain),
                'default' => 'sandbox',
                'options' => array(
                    'sandbox' => 'Sandbox',
                    'production' => 'Production'
                )
            ),            
        );
        
        $platform_credentials = array(
            'api_platform_settings' => array(
                'title' => esc_html__($this->bread_config->get('tenant_name') . ' Platform Credentials', $text_domain),
                'type' => 'title'
            ),
            'sandbox_api_key' => array(
                'title' => esc_html__('Sandbox API Key', $text_domain),
                'type' => 'text',
                'desc_tip' => esc_html__('Your ' . $this->bread_config->get('tenant_name') . ' Sandbox API Key')
            ),
            'sandbox_api_secret_key' => array(
                'title' => esc_html__('Sandbox API Secret Key', $text_domain),
                'type' => 'text',
                'desc_tip' => esc_html__('Your ' . $this->bread_config->get('tenant_name') . ' Sandbox API Secret Key')
            ),
            'sandbox_integration_key' => array(
                'title' => esc_html__('Sandbox Integration Key', $text_domain),
                'type' => 'text',
                'desc_tip' => esc_html__('Your ' . $this->bread_config->get('tenant_name') . ' Pay Sandbox integration key. This will be provided by your customer success manager')
            ),
            'production_api_key' => array(
                'title' => esc_html__('Production API Key', $text_domain),
                'type' => 'text',
                'desc_tip' => esc_html__('Your ' . $this->bread_config->get('tenant_name') . ' Production API Key')
            ),
            'production_api_secret_key' => array(
                'title' => esc_html__('Production API Secret Key', $text_domain),
                'type' => 'text',
                'desc_tip' => esc_html__('Your ' . $this->bread_config->get('tenant_name') . ' Production API Secret Key')
            ),
            'production_integration_key' => array(
                'title' => esc_html__('Production Integration Key', $text_domain),
                'type' => 'text',
                'desc_tip' => esc_html__('Your ' . $this->bread_config->get('tenant_name') . ' Pay production integration key. This will be provided by your customer success manager')
            )
        );
        
        $classic_credentials = array(
            'api_classic_settings' => array(
                'title' => esc_html__($this->bread_config->get('tenant_name') . ' Classic Credentials', $text_domain),
                'type' => 'title',
                'custom_attributes' => [
                    'versions' => ['classic']
                ]
            ),
            'sandbox_classic_api_key' => array(
                'title' => esc_html__('Classic Sandbox Public Key', $text_domain),
                'type' => 'text',
                'desc_tip' => esc_html__('Your ' . $this->bread_config->get('tenant_name') . ' Sandbox Public Key'),
                'custom_attributes' => [
                    'versions' => ['classic']
                ]
            ),
            'sandbox_classic_api_secret_key' => array(
                'title' => esc_html__('Classic Sandbox Secret Key', $text_domain),
                'type' => 'text',
                'desc_tip' => esc_html__('Your ' . $this->bread_config->get('tenant_name') . ' Sandbox Secret Key'),
                'custom_attributes' => [
                    'versions' => ['classic']
                ]
            ),
            'production_classic_api_key' => array(
                'title' => esc_html__('Classic Production Public Key', $text_domain),
                'type' => 'text',
                'desc_tip' => esc_html__('Your ' . $this->bread_config->get('tenant_name') . ' Production Public API Key'),
                'custom_attributes' => [
                    'versions' => ['classic']
                ]
            ),
            'production_classic_api_secret_key' => array(
                'title' => esc_html__('Classic Production Secret Key', $text_domain),
                'type' => 'text',
                'desc_tip' => esc_html__('Your ' . $this->bread_config->get('tenant_name') . ' Production Secret Key'),
                'custom_attributes' => [
                    'versions' => ['classic']
                ]
            ),
        );
        
        $button_appearance = array(
            'button_appearance' => array(
                'title' => esc_html__('Button Appearance', $text_domain),
                'type' => 'title',
            ),
            'button_custom_css' => array(
                'title' => esc_html__('Custom CSS (Classic)', $text_domain),
                'type' => 'textarea',
                'description' => __('Overwrite the default ' . $this->bread_config->get('tenant_name') . ' Classic CSS with your own. More information <a href="http://docs.getbread.com/docs/manual-integration/button-styling/" target="blank">here</a>.', $text_domain),
                'default' => '',
                'custom_attributes' => [
                    'versions' => ['classic']
                ]
            ),
            'button_size' => array(
                'title' => esc_html__('Button Size (Classic)', $text_domain),
                'type' => 'select',
                'default' => 'default',
                'options' => array(
                    'default' => 'Default (200px x 50px)',
                    'custom' => 'Custom (Using CSS)'
                ),
                'custom_attributes' => [
                    'versions' => ['classic']
                ]
            ),
            'button_options_category' => array(
                'title' => esc_html__('Category Page Options', $text_domain),
                'type' => 'title',
            ),
            'button_as_low_as_category' => array(
                'title' => esc_html__('As Low As (Classic)', $text_domain),
                'type' => 'checkbox',
                'label' => esc_html__('Display price per month to logged out users using the lowest available APR and longest term length offered.', $text_domain),
                'default' => 'yes',
                'custom_attributes' => [
                    'versions' => ['classic']
                ]
            ),
            'button_act_as_label_category' => array(
                'title' => esc_html__('Act as Label', $text_domain),
                'type' => 'checkbox',
                'label' => esc_html__('Prevent ' . $this->bread_config->get('tenant_name') . ' modal from loading after prequalification. (Not recommended)', $text_domain),
                'default' => 'no'
            ),
            'button_location_category' => array(
                'title' => esc_html__('Button Placement', $text_domain),
                'type' => 'select',
                'description' => esc_html__('Location on the category pages where the ' . $this->bread_config->get('tenant_name') . ' button should appear', $text_domain),
                'options' => array(
                    'after_shop_loop_item:before' => esc_html__('Before Add to Cart Button', $text_domain),
                    'after_shop_loop_item:after' => esc_html__('After Add to Cart Button', $text_domain),
                    '' => esc_html__("Don't Display Button on Category Pages", $text_domain)
                ),
                'default' => 'woocommerce_after_shop_loop_item:after'
            ),
            'button_options_product' => array(
                'title' => esc_html__('Product Page Options', $text_domain),
                'type' => 'title',
            ),
            'button_checkout_product' => array(
                'title' => esc_html__('Allow Checkout (Classic)', $text_domain),
                'type' => 'checkbox',
                'label' => esc_html__('Allow users to complete checkout from the product page after prequalification.', $text_domain),
                'default' => 'no',
                'custom_attributes' => [
                    'versions' => ['classic']
                ]
            ),
            'button_as_low_as_product' => array(
                'title' => esc_html__('As Low As (Classic)', $text_domain),
                'type' => 'checkbox',
                'label' => esc_html__('Display price per month to logged out users using the lowest available APR and longest term length offered.', $text_domain),
                'default' => 'yes',
                'custom_attributes' => [
                    'versions' => ['classic']
                ]
            ),
            'button_act_as_label_product' => array(
                'title' => esc_html__('Act as Label', $text_domain),
                'type' => 'checkbox',
                'label' => esc_html__('Prevent ' . $this->bread_config->get('tenant_name') . ' modal from loading after prequalification. (Not recommended)', $text_domain),
                'default' => 'no'
            ),
            'button_location_product' => array(
                'title' => esc_html__('Button Placement', $text_domain),
                'type' => 'select',
                'description' => esc_html__('Location on the product pages where the ' . $this->bread_config->get('tenant_name') . ' button should appear', $text_domain),
                'options' => array(
                    'before_single_product_summary' => esc_html__('Before Product Summary', $text_domain),
                    'before_add_to_cart_form' => esc_html__('Before Add to Cart Button', $text_domain),
                    'after_add_to_cart_form' => esc_html__('After Add to Cart Button', $text_domain),
                    'after_single_product_summary' => esc_html__('After Product Summary', $text_domain),
                    'get_price_html' => esc_html__('After Product Price', $text_domain),
                    '' => esc_html__("Don't Display Button on Product Pages", $text_domain)
                ),
                'default' => 'after_add_to_cart_form'
            ),
            'button_options_cart' => array(
                'title' => esc_html__('Cart Summary Page Options', $text_domain),
                'type' => 'title',
            ),
            'button_checkout_cart' => array(
                'title' => esc_html__('Allow Checkout (Classic)', $text_domain),
                'type' => 'checkbox',
                'label' => esc_html__('Allow users to complete checkout from the cart page after prequalification.', $text_domain),
                'default' => 'no',
                'custom_attributes' => [
                    'versions' => ['classic']
                ]
            ),
            'button_as_low_as_cart' => array(
                'title' => esc_html__('As Low As (Classic)', $text_domain),
                'type' => 'checkbox',
                'label' => esc_html__('Display price per month to logged out users using the lowest available APR and longest term length offered.', $text_domain),
                'default' => 'yes',
                'custom_attributes' => [
                    'versions' => ['classic']
                ]
            ),
            'button_act_as_label_cart' => array(
                'title' => esc_html__('Act as Label', $text_domain),
                'type' => 'checkbox',
                'label' => esc_html__('Prevent ' . $this->bread_config->get('tenant_name') . ' modal from loading after prequalification. (Not recommended)', $text_domain),
                'default' => 'no'
            ),
            'button_location_cart' => array(
                'title' => esc_html__('Button Placement', $text_domain),
                'type' => 'select',
                'description' => esc_html__('Location on the cart summary page where the ' . $this->bread_config->get('tenant_name') . ' button should appear', $text_domain),
                'options' => array(
                    'after_cart_totals' => esc_html__('After Cart Totals', $text_domain),
                    '' => esc_html__("Don't Display Button on Cart Summary Page", $text_domain)
                ),
                'default' => 'after_cart_totals'
            ),
            'button_options_checkout' => array(
                'title' => esc_html__('Checkout Page Options', $text_domain),
                'type' => 'title',
            ),
            'button_checkout_checkout' => array(
                'title' => esc_html__('Show ' . $this->bread_config->get('tenant_name') . ' as Payment', $text_domain),
                'type' => 'checkbox',
                'label' => esc_html('Enable ' . $this->bread_config->get('tenant_name') . ' as a payment option on the checkout page.', $text_domain),
                'default' => 'yes'
            ),
            'button_as_low_as_checkout' => array(
                'title' => esc_html__('As Low As (Classic)', $text_domain),
                'type' => 'checkbox',
                'label' => esc_html__('Display price per month to logged out users using the lowest available APR and longest term length offered.', $text_domain),
                'default' => 'yes',
                'custom_attributes' => [
                    'versions' => ['classic']
                ]
            ),
            'button_show_splitpay_label' => array(
                'title' => esc_html__('Show Splitpay label (Classic)', $text_domain),
                'type' => 'checkbox',
                'label' => esc_html__('Show Splitpay label on Checkout page', $text_domain),
                'default' => 'yes',
                'custom_attributes' => [
                    'versions' => ['classic']
                ]
            )
        );
        $button_defaults = array(
            'button_defaults' => array(
                'title' => esc_html__('Button Defaults', $text_domain),
                'type' => 'title'
            ),
            'button_placeholder' => array(
                'title' => esc_html__('Button Placeholder', $text_domain),
                'type' => 'textarea',
                'description' => esc_html__('Custom HTML to show as a placeholder for ' . $this->bread_config->get('tenant_name') . ' buttons that have not yet been rendered.', $text_domain),
            ),
        );
        
        $admin_carts = array(
            'admin_cart_settings' => array(
                'title' => esc_html__('Bread Admin Carts', $text_domain),
                'type' => 'title'
            ),
            'sandbox_platform_merchantId' => array(
                'title' => esc_html__('Sandbox Merchant ID', $text_domain),
                'type' => 'text',
                'desc_tip' => esc_html__('Your Bread Sandbox Merchant ID. Contact your Bread representative')
            ),
            'sandbox_platform_programId' => array(
                'title' => esc_html__('Sandbox Program ID', $text_domain),
                'type' => 'text',
                'desc_tip' => esc_html__('Your Bread Sandbox ProgramId. Contact your Bread representative')
            ),
            'production_platform_merchantId' => array(
                'title' => esc_html__('Production Merchant ID', $text_domain),
                'type' => 'text',
                'desc_tip' => esc_html__('Your Bread Production Merchant ID. Contact your Bread representative')
            ),
            'production_platform_programId' => array(
                'title' => esc_html__('Production Program ID', $text_domain),
                'type' => 'text',
                'desc_tip' => esc_html__('Your Bread Production Program ID. Contact your Bread representative')
            ),
        );

        $advanced = array(
            'advanced_settings_title' => array(
                'title' => esc_html__('Advanced Settings (requires authorization from your ' . $this->bread_config->get('tenant_name') . ' representative)', $text_domain),
                'type' => 'title',
            ),
            'advanced_settings' => array(
                'type' => 'advanced_settings',
            ),
        );
        $settings = $this->mergeWithCondition($this->bread_config->get('sdk_versions'), $general, $environment, $platform_credentials, $classic_credentials, $button_appearance, $button_defaults, $admin_carts, $advanced);
        return apply_filters('bread_finance_wc_gateway_settings', $settings);
    }

    /**
     * Merges arrays, conditionally includes fields with matching versions.
     *
     * @param array $versions Associative array of versions e.g., ['classic' => 'Classic', 'bread_2' => Platform']
     * @param array ...$fields Variable number of arrays to be merged.
     * @return array Merged array.
     */
    public function mergeWithCondition($versions, ...$fields) {
        $settings = array();
    
        foreach ($fields as $field) {
            foreach ($field as $key => $value) {
                if (isset($value['custom_attributes']['versions'])) {
                    if (array_intersect(array_keys($versions), $value['custom_attributes']['versions'])) {
                        $settings[$key] = $value;
                    }
                } else {
                    $settings[$key] = $value;
                }
            }
        }
    
        return $settings;
    }
}