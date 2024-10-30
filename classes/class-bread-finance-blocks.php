<?php

namespace Bread_Finance\Classes;

final class Bread_Finance_Blocks extends \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType {

    private $gateway;
    protected $name = 'bread_finance';

    protected $settings;

    protected $bread_config;

    public function initialize() {
        $this->settings = get_option("woocommerce_{$this->name}_settings", []);
        $this->gateway = new \Bread_Finance\Classes\Bread_Finance_Gateway();
        $this->bread_config = $this->gateway->bread_config;
    }

    /**
     * Returns if this payment method should be active. If false, the scripts will not be enqueued.
     *
     * @return boolean
     */
    public function is_active() {
        if (!$this->gateway->bread_finance_utilities->tenant_currency_equals_woocommerce_currency()) {
            return false;
        }
        return !empty( $this->settings['enabled'] ) && 'yes' === $this->settings['enabled'];
    }

    public function get_payment_method_script_handles() {
        $tenant = strtoupper($this->name);
        wp_register_script(
            "{$this->bread_config->get('text_domain')}-gateway-blocks-integration",
            plugins_url('assets/js/v2/checkout-blocks.js', constant('WC_' . $tenant . '_MAIN_FILE')),
            [
                'wc-blocks-registry',
                'wc-settings',
                'wp-element',
                'wp-html-entities',
                'wp-i18n',
                "{$this->bread_config->get('tenant_prefix')}-main"
            ],
            filemtime(plugin_dir_path( __FILE__ ) . '../assets/js/v2/checkout-blocks.js'),
            true
        );
        return [ "{$this->bread_config->get('text_domain')}-gateway-blocks-integration" ];
    }

    public function get_embedded() {
        return isset( $this->settings['set_embedded'] ) && 'yes' === $this->settings['set_embedded'];
    }

    public function get_payment_method_data() {
        return [
            'title' => $this->gateway->title,
            'description' => $this->gateway->description,
            'embedded' => $this->get_embedded(),
            'tenant_sdk' => $this->bread_config->get('tenant_sdk'),
            'enabled_for_shipping' => $this->bread_config->get('enabled_for_shipping', [])
        ];
    }

}