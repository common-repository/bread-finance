<?php

/**
 * Remove Bread related plugin configurations
 * 
 * @since 3.0.7
 * @author Kip, Maritim Bread Financial
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
};

$options = array(
    'enabled',
    'title',
    'description',
    'display_icon',
    'pre_populate',
    'default_payment',
    'auto_cancel',
    'sentry_enabled',
    'api_settings',
    'env_bread_api',
    'environment',
    'sandbox_api_key',
    'sandbox_api_secret_key',
    'sandbox_integration_key',
    'production_api_key',
    'production_api_secret_key',
    'production_integration_key',
    'button_appearance',
    'button_custom_css',
    'button_size',
    'button_options_category',
    'button_as_low_as_category',
    'button_act_as_label_category',
    'button_location_category',
    'button_options_product',
    'button_checkout_product',
    'button_as_low_as_product',
    'button_act_as_label_product',
    'button_location_product',
    'button_options_cart',
    'button_checkout_cart',
    'button_as_low_as_cart',
    'button_act_as_label_cart',
    'button_location_cart',
    'button_options_checkout',
    'button_checkout_checkout',
    'button_as_low_as_checkout',
    'button_show_splitpay_label',
    'button_defaults',
    'button_placeholder'
);

foreach($options as $option) {
    if(get_option($option)) {
        delete_option($option);
    }
}