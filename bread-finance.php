<?php

/**
 * Plugin Name: Bread Pay
 * Description: Adds the Bread Pay Gateway to your WooCommerce site.
 * Author: Bread Financial
 * Author URI: https://payments.breadfinancial.com/
 * Version: 3.5.6
 * Text Domain: bread_finance
 * Domain Path: /i18n/languages/
 * WC requires at least: 3.0.0
 * WC tested up to: 7.3.0
 *
 *
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */
namespace Bread_Finance;

if (!defined('ABSPATH')) {
    die('Access denied.');
}

if (!class_exists(\Bread_Finance\Classes\Spyc::class)) {
    # Including this first so it can be used in Config.php
    require_once untrailingslashit(plugin_dir_path(__FILE__)) . '/classes/Spyc.php';
}

$bread_config = null;
$plugin_path = untrailingslashit(plugin_dir_path(__FILE__));

if (file_exists($plugin_path . '/classes/config/Config.php')) {
    require_once $plugin_path . '/classes/config/Config.php';
    $bread_config = new \Bread_Finance\Classes\Config\Bread_Config();
}



if (!class_exists(WC_Bread_Finance::class)) {

    /**
     * Class WC_Bread_Pay
     */
    class WC_Bread_Finance {

        /**
         * Reference singleton instance of this class
         * 
         * @var $instance
         */
        private static $instance;

        private $bread_config;

        private $plugin_path;

        /**
         * 
         * Return singleton instance of this class
         * 
         * @return object self::$instance
         */
        public static function instance($bread_config, $plugin_path) {
            if (null == self::$instance) {
                self::$instance = new self($bread_config, $plugin_path);
            }

            return self::$instance;
        }

        /**
         * Private clone method to prevent cloning of the instance of the
         * *Singleton* instance.
         *
         * @return void
         */
        private function __clone() {
            wc_doing_it_wrong(__FUNCTION__, __('Nope'), '1.0');
        }

        /**
         * Private unserialize method to prevent unserializing of the *Singleton*
         * instance.
         *
         * @return void
         */
        public function __wakeup() {
            wc_doing_it_wrong(__FUNCTION__, __('Nope'), '1.0');
        }

        /**
         * Notices
         * 
         * @var array
         */
        public $notices = array();

        protected function __construct($bread_config, $plugin_path) {
            add_action('admin_notices', array($this, 'admin_notices'), 15);
            add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'plugin_action_links'));
            add_action('in_plugin_update_message-bread-finance/bread-finance.php' , array($this, 'append_plugin_update_message'), 10, 2 );
            add_filter('plugin_row_meta',array($this, 'plugin_meta_links'),10,2);
            add_action('plugins_loaded', array($this, 'init'));
            $this->plugin_path = $plugin_path;
            $this->bread_config = $bread_config;
        }

        /**
         * Init plugin after plugins have been loaded
         */
        public function init() {
            //Load our gateway itself
            $this->init_gateway();
        }

        /**
         * Display any notices that we have so far
         */
        public function admin_notices() {
            foreach ((array) $this->notices as $key => $message) {
                echo "<div class='" . esc_attr($notice['class']) . "'><p>";
                echo wp_kses($notice['message'], array('a' => array('href' => array())));
                echo '</p></div>';
            }
        }

        /**
         * Adds plugin actions link
         * 
         * @param array $links Plugin action links for filtering
         * @return array Filter links
         */
        public function plugin_action_links($links) {
            $setting_link = $this->get_setting_link();
            $plugin_links = array(
                '<a href="' . $setting_link . '">' . __('Settings', $this->bread_config->get('text_domain')) . '</a>',
            );
            return array_merge($plugin_links, $links);
        }
        
        /**
         * Append plugin update message for < v3.3.0
         * 
         * @param $data
         * @param $response
         */
        public function append_plugin_update_message($data, $response) {
            if (version_compare('3.3.0', $data['new_version'], '>')) {
                return;
            }
            $update_notice = '<div class="wc_plugin_upgrade_notice">';

            // translators: placeholders are opening and closing tags. Leads to docs on version 2.0.0
            $update_notice .= sprintf(__('<p>NOTICE! Version ' . $data['new_version'] . ' is a major update and requires an update to your Bread Pay settings! '
                            . 'After upgrading to version ' . $data['new_version'] . ', be sure to input the correct Bread API credentials within the Bread Classic section of your plug-in settings. '
                            . '%sLearn more about the changes in version ' . $data['new_version'] . ' &raquo;%s</p>'
                            . '<p>Contact your Bread Pay representative if you are unsure what this change means for you</p></div>', 'your-plugin-text-domain'), '<a href="https://wordpress.org/plugins/bread-finance/#developers">', '</a>');

            echo wp_kses_post($update_notice);
        }

        /**
         * Plugin meta info
         * 
         * @param type $links
         * @param type $file
         * @return string
         */
        public function plugin_meta_links($links, $file) {
            if(strpos($file, basename(__FILE__))) {
                $links[] = '<a href="' . $this->bread_config->get('tenant_author_uri') . '" target="_blank" title="Get started"> Get Started </a>';
                $links[] = '<a href="' . $this->bread_config->get('tenant_docs_uri') . '" target="_blank" title="Docs"> Docs </a>';
            }
            return $links;
        }

        /**
         * Get setting link.
         *
         *
         * @return string Setting link
         */
        public function get_setting_link() {
            $section_slug = $this->bread_config->get('gateway_id');

            $params = array(
                'page' => 'wc-settings',
                'tab' => 'checkout',
                'section' => $section_slug,
            );

            $admin_url = add_query_arg($params, 'admin.php');
            return $admin_url;
        }

        /**
         * Include all the files needed for the plugin
         * 
         */
        public function init_gateway() {
            if (!class_exists('\WC_Payment_Gateway')) {
                return;
            }

            $tenant = strtoupper($this->bread_config->get('gateway_id'));
            
            //Require minimums and constants
            define('WC_' . $tenant . '_VERSION', '3.5.6');
            define('WC_' . $tenant . '_MIN_PHP_VER', '5.6.0');
            define('WC_' . $tenant . '_MIN_WC_VER', '3.4.0');
            define('WC_' . $tenant . '_MAIN_FILE', __FILE__);
            define('WC_' . $tenant . '_PLUGIN_PATH', $this->plugin_path);
            define('WC_' . $tenant . '_PLUGIN_URL', untrailingslashit(plugin_dir_url(__FILE__)));

            //Compability classes
            include_once $this->plugin_path . '/classes/compat/class-bread-finance-currency-abstract.php';
            include_once $this->plugin_path . '/classes/compat/class-bread-finance-currency-pboc.php';
            include_once $this->plugin_path . '/classes/compat/class-bread-finance-currency-woocs.php';
            include_once $this->plugin_path . '/classes/compat/class-bread-finance-currency.php';
            include_once $this->plugin_path . '/classes/compat/class-bread-finance-captcha.php';
            include_once $this->plugin_path . '/classes/compat/class-bread-finance-captcha-wpcaptcha.php';

            //Classes
            include_once $this->plugin_path . '/classes/class-bread-finance-admin-carts-helper.php';
            include_once $this->plugin_path . '/classes/class-bread-finance-utilities.php';
            include_once $this->plugin_path . '/classes/class-bread-finance-form-fields.php';
            include_once $this->plugin_path . '/classes/class-bread-finance-classic-api.php';
            include_once $this->plugin_path . '/classes/class-bread-finance-v2-api.php';
            include_once $this->plugin_path . '/classes/class-bread-finance-gateway.php';
            include_once $this->plugin_path . '/classes/class-bread-finance-plugin.php';
            include_once $this->plugin_path . '/classes/class-bread-finance-ajax.php';
            include_once $this->plugin_path . '/classes/class-bread-finance-options-cart.php';
            include_once $this->plugin_path . '/classes/class-bread-finance-options-checkout.php';
            include_once $this->plugin_path . '/classes/class-bread-finance-button-helper.php';
            include_once $this->plugin_path . '/classes/class-bread-finance-options-category.php';
            include_once $this->plugin_path . '/classes/class-bread-finance-options-product.php';
            include_once $this->plugin_path . '/classes/class-bread-finance-options-cart-checkout.php';
            include_once $this->plugin_path . '/classes/class-bread-finance-button.php';
            include_once $this->plugin_path . '/classes/class-bread-finance-logger.php';
            include_once $this->plugin_path . '/classes/class-bread-finance-api-factory.php';

            add_filter('woocommerce_payment_gateways', array($this, 'add_gateways'));
        }

        /**
         * Add the Gateway to Woocommerce
         * 
         * @params array $methods 
         * @return array $methods
         */
        public function add_gateways($methods) {
            $methods[] = \Bread_Finance\Classes\Bread_Finance_Gateway::class;
            return $methods;
        }

    }

}

// Declare Blocks compatibility
add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
});

// Hook in WooC Blocks integration. 
add_action( 'woocommerce_blocks_loaded', function () {
    if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
        return;
    }

    require_once plugin_dir_path(__FILE__) . 'classes/class-bread-finance-blocks.php';

    // Hook the registration function to the 'woocommerce_blocks_payment_method_type_registration' action
    add_action(
        'woocommerce_blocks_payment_method_type_registration',
        function( \Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
            $payment_method_registry->register( new \Bread_Finance\Classes\Bread_Finance_Blocks() );
        }
    );
});

$instance_name = 'wc_' . $bread_config->get('gateway_id');

$GLOBALS[$instance_name] = WC_Bread_Finance::instance($bread_config, $plugin_path);
