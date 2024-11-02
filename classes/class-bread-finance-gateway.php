<?php
/*
 * Class file for Bread_finance_Gateway class
 * 
 * @package Bread_finance/Classes
 */

namespace Bread_Finance\Classes;


if (!defined('ABSPATH')) {
    exit;
}

if (class_exists('\WC_Payment_Gateway')) {

    /**
     * Bread_Finance_Gateway class
     * 
     * @extends WC_payment_Gateway
     */
    class Bread_Finance_Gateway extends \WC_payment_Gateway {
        

        public $sp_decline_message = false;

        /**
         * Utility helper class
         */
        public $bread_finance_utilities = false;

        /**
         * Bread API
         */
        public $bread_finance_api = false;

        public $bread_finance_plugin = false;

        public $bread_config;

        public $bread_finance_form_fields;
        
        /**
         * App logger helper
         */
        public $log;

        public function __construct() {
            $this->bread_config = \Bread_Finance\Classes\Config\Bread_Config::instance();
            $this->bread_finance_utilities = \Bread_Finance\Classes\Bread_Finance_Utilities::instance();
            $this->id = $this->bread_config->get('gateway_id');
            $this->plugin_version = constant('WC_' . strtoupper($this->bread_config->get('gateway_id')) . '_VERSION');
            $this->method_title = __($this->bread_config->get('tenant_name') . " v" . $this->plugin_version, $this->bread_config->get('text_domain'));
            $this->method_description = __("Allow customers to pay for their purchase over time using " . $this->bread_config->get('tenant_name') . " financing.", $this->bread_config->get('text_domain'));
            $this->has_fields = false;
            $this->supports = array('refunds', 'products');
            $this->main_file_path = constant('WC_' . $this->bread_config->get_constant('gateway_id') . '_MAIN_FILE');
            $this->plugin_path = constant('WC_' . $this->bread_config->get_constant('gateway_id') . '_PLUGIN_PATH');

            // Load the form fields.
            $this->init_form_fields();

            $this->init_settings();

            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->enabled = $this->get_option('enabled');

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'save_advanced_settings'));
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'validate_product_id_list'));

            //Validate API keys
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'validate_api_keys'), 11);

            add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));

            add_action('woocommerce_checkout_update_order_review', array($this, 'handle_checkout_update_order_review'));
            add_action('woocommerce_after_checkout_validation', array($this, 'prevent_order_creation_during_validation'), 10, 2);
            add_action('before_woocommerce_init', array($this, 'anonymize_tax_and_shipping_ajax'));
            add_action('before_woocommerce_init', array($this, 'init_bread_cart'));
            add_action('woocommerce_init', array($this, 'empty_bread_cart'));
            add_action('woocommerce_before_checkout_process', array($this, 'external_plugin_compatibility'));
            add_action('init', array($this, 'add_rewrite_tags'));
            add_filter('update_user_metadata', array($this, 'prevent_bread_cart_persistence'), 10, 5);
            add_action('template_redirect', array($this, 'process_bread_cart_order'));
            add_action('woocommerce_add_to_cart', array($this, 'handle_bread_cart_action'), 99, 6);

            add_filter('woocommerce_order_status_completed', array($this, 'settle_transaction'));
            add_filter('woocommerce_order_status_cancelled', array($this, 'cancel_transaction'));
            add_filter('woocommerce_order_status_refunded', array($this, 'process_refund'));

            add_action('added_post_meta', array($this, 'sendAdvancedShipmentTrackingInfo'), 10, 4);
            add_action('updated_post_meta', array($this, 'sendAdvancedShipmentTrackingInfo'), 10, 4);
            
            add_action('woocommerce_order_status_on-hold_to_processing', array($this, 'settle_order'));
            add_action('woocommerce_order_status_on-hold_to_completed', array($this, 'settle_order'));
            
            add_action('woocommerce_order_actions',array($this,'add_create_cart_options'));
            add_action('woocommerce_order_action_create_bread_cart_link', array($this, 'create_bread_cart_link'));
            add_action('woocommerce_order_action_email_bread_cart_link', array($this, 'email_bread_cart_link'));

            add_filter( 'query_vars', array($this, 'custom_bread_vars'));

            $this->sp_decline_message = <<<EOD
            The credit/debit card portion of your transaction was declined.
            Please use a different card or contact your bank. Otherwise, you can still check out with
            an amount covered by your loan capacity.
            EOD;
        }

        public function init_form_fields() {
            $this->bread_finance_form_fields = Bread_Finance_Form_Fields::instance();
            $this->form_fields = $this->bread_finance_form_fields->fields($this->bread_config->get('text_domain'));
        }


        public function handle_checkout_update_order_review() {
            if(!$this->bread_finance_utilities->tenant_currency_equals_woocommerce_currency() && $this->bread_finance_utilities->getPageType() === 'checkout') {
                add_filter('woocommerce_available_payment_gateways', function($available_gateways) {
                    unset($available_gateways[$this->id]);
                    return $available_gateways;
                });
            }
        }

        public function enqueue_scripts() {

            if ('yes' !== $this->enabled) {
                return;
            }

            if (!$this->bread_finance_utilities->tenant_currency_equals_woocommerce_currency()) {
                return;
            }

            $bread_version = $this->get_configuration_setting('env_bread_api');
            if ($bread_version && $bread_version === 'bread_2') {
                //Add Bread SDK
                wp_register_script(
                        "{$this->bread_config->get('tenant_prefix')}-sdk",
                        $this->load_sdk(),
                        array(),
                        null,
                        true
                );

                //Add JS Helper
                wp_register_script(
                        'knockout',
                        plugins_url('assets/js/v2/knockout-3.5.1.js', $this->main_file_path),
                        array(),
                        $this->plugin_version,
                        true
                );

                wp_register_script(
                        'knockback',
                        plugins_url('assets/js/v1/mwp/knockback.min.js', $this->main_file_path),
                        array(),
                        $this->plugin_version,
                        true
                );

                wp_register_script(
                        'mwp-settings',
                        plugins_url('assets/js/v1/mwp/mwp.settings.js', $this->main_file_path),
                        array('mwp', 'knockback'),
                        $this->plugin_version,
                        true
                );

                wp_register_script(
                        'mwp',
                        plugins_url('assets/js/v1/mwp/mwp.framework.js', $this->main_file_path),
                        array('jquery', 'underscore', 'backbone', 'knockout'),
                        $this->plugin_version,
                        true
                );

                wp_localize_script('mwp', 'mw_localized_data', array(
                    'ajaxurl' => admin_url('admin-ajax.php'),
                    'ajaxnonce' => wp_create_nonce('mwp-ajax-nonce'),
                ));

                wp_register_script(
                    "{$this->bread_config->get('tenant_prefix')}-util",
                    plugins_url('assets/js/v2/util.js', $this->main_file_path),
                    array("{$this->bread_config->get('tenant_prefix')}-sdk", 'mwp'),
                    $this->is_production() ? $this->plugin_version: filemtime(plugin_dir_path( __FILE__ ) . '../assets/js/v2/util.js'),
                    true
                );

                //Add JS Helper
                wp_register_script(
                        "{$this->bread_config->get('tenant_prefix')}-main",
                        plugins_url('assets/js/v2/main.js', $this->main_file_path),
                        array("{$this->bread_config->get('tenant_prefix')}-sdk", 'mwp'),
                        $this->is_production() ? $this->plugin_version: filemtime(plugin_dir_path( __FILE__ ) . '../assets/js/v2/main.js'),
                        true
                );

                //Localize params
                $params = array(
                    'page_type' => $this->bread_finance_utilities->getPageType(),
                    'integration_key' => $this->get_integration_key(),
                    'product_type' => $this->bread_finance_utilities->getProductType(),
                    'gateway_token' => $this->bread_config->get('gateway_id'),
                    'debug' => $this->bread_finance_utilities->toBool($this->get_configuration_setting('debug')),
                    'sentry_enabled' => $this->bread_finance_utilities->toBool($this->get_configuration_setting('sentry_enabled')),
                    'tenant_prefix' => $this->bread_config->get('tenant_prefix'),
                    'tenant_sdk' => $this->bread_config->get('tenant_sdk'),
                    'set_embedded' => $this->bread_finance_utilities->toBool($this->get_configuration_setting('set_embedded')),
                    'ajaxurl' => admin_url('admin-ajax.php'),
                    'ajaxnonce' => wp_create_nonce('mwp-ajax-nonce'),
                    'enabled_for_shipping' => $this->bread_config->get('enabled_for_shipping', []),
                    'checkout_flags' => $this->bread_finance_utilities->get_checkout_flags()
                );

                //Enqueue scripts
                wp_localize_script("{$this->bread_config->get('tenant_prefix')}-main", "mw_localized_data", $params);
                
                //Add styling
                wp_register_style(
                        "{$this->bread_config->get('tenant_prefix')}-main",
                        plugins_url("assets/css/{$this->bread_config->get('tenant_prefix')}.css", $this->main_file_path),
                        array(),
                        $this->is_production() ? $this->plugin_version : filemtime(plugin_dir_path( __FILE__ ) . "../assets/css/{$this->bread_config->get('tenant_prefix')}.css")
                );
                
                wp_enqueue_script("{$this->bread_config->get('tenant_prefix')}-sdk");
                wp_enqueue_script("{$this->bread_config->get('tenant_prefix')}-util");
                wp_enqueue_script("{$this->bread_config->get('tenant_prefix')}-main");
                wp_enqueue_style("{$this->bread_config->get('tenant_prefix')}-main");

                //Defer sdk loading
                add_filter('script_loader_tag', array($this, 'add_defer_tags_to_scripts'));
            } else {
                //Register Bread scripts
                wp_register_script(
                        "{$this->bread_config->get('tenant_prefix')}-api",
                        $this->get_checkout_url() . "/{$this->bread_config->get('tenant_prefix')}.js",
                        array('jquery-serialize-object', 'jquery-ui-dialog'),
                        $this->plugin_version,
                        true
                );

                wp_register_script(
                        'knockout',
                        plugins_url('assets/js/v1/mwp/knockout.min.js', $this->main_file_path),
                        array(),
                        $this->plugin_version,
                        true
                );

                wp_register_script(
                        'knockback',
                        plugins_url('assets/js/v1/mwp/knockback.min.js', $this->main_file_path),
                        array(),
                        $this->plugin_version,
                        true
                );

                wp_register_script(
                        'jquery-loading-overlay',
                        plugins_url('assets/js/v1/mwp/jquery.loading-overlay.min.js', $this->main_file_path),
                        array(),
                        $this->plugin_version,
                        true
                );

                wp_register_script(
                        'mwp-settings',
                        plugins_url('assets/js/v1/mwp/mwp.settings.js', $this->main_file_path),
                        array('mwp', 'knockback'),
                        $this->plugin_version,
                        true
                );

                wp_register_script(
                        'mwp',
                        plugins_url('assets/js/v1/mwp/mwp.framework.js', $this->main_file_path),
                        array('jquery', 'underscore', 'backbone', 'knockout'),
                        $this->plugin_version,
                        true
                );

                wp_localize_script('mwp', 'mw_localized_data', array(
                    'ajaxurl' => admin_url('admin-ajax.php'),
                    'ajaxnonce' => wp_create_nonce('mwp-ajax-nonce'),
                ));

                //Register main JS
                wp_register_script(
                        "{$this->bread_config->get('tenant_prefix')}-main",
                        plugins_url('assets/js/v1/main.js', $this->main_file_path),
                        array('mwp'),
                        $this->plugin_version,
                        true
                );

                $main_localize_params = array(
                    'page_type' => $this->bread_finance_utilities->getPageType(),
                    'product_type' => $this->bread_finance_utilities->getProductType(),
                    'gateway_token' => $this->bread_config->get('gateway_id'),
                    'bread_api_key' => $this->get_classic_api_key(),
                    'show_splitpay_label' => $this->bread_finance_utilities->toBool($this->get_configuration_setting('button_show_splitpay_label')),
                    'debug' => $this->bread_finance_utilities->toBool($this->get_configuration_setting('debug')),
                    'sentry_enabled' => $this->bread_finance_utilities->toBool($this->get_configuration_setting('sentry_enabled'))
                );
                wp_localize_script("{$this->bread_config->get('tenant_prefix')}-main", "mw_localized_data", $main_localize_params);

                //Add styling
                wp_register_style(
                        "{$this->bread_config->get('tenant_prefix')}-main",
                        plugins_url("assets/css/{$this->bread_config->get('tenant_prefix')}.css", $this->main_file_path),
                        array(),
                        $this->plugin_version
                );

                //Add the api key
                add_filter('script_loader_tag', array($this, 'add_api_key_to_script'), 10, 3);

                wp_enqueue_script("{$this->bread_config->get('tenant_prefix')}-api");
                wp_enqueue_script("{$this->bread_config->get('tenant_prefix')}-main");
                wp_enqueue_style("{$this->bread_config->get('tenant_prefix')}-main");

            }
        }

        /**
         * @param $order_id
         * @return array[]
         */
        public function process_payment($order_id) {
            if (!array_key_exists('bread_tx_token', $_REQUEST) && !array_key_exists('bread_tx_token', $_POST)) {
                $this->log(
                        __FUNCTION__,
                        "Error in processing payment: $this->method_title transaction token does not exist"
                );
                return $this->error_result(esc_html__("Missing $this->method_title transaction token.", $this->bread_config->get('text_domain')));
            }
            $bread_version = $this->get_option('env_bread_api') ? $this->get_option('env_bread_api') : $this->bread_config->get('default_sdk_version');
            
            if ($bread_version === 'bread_2') {
                return $this->process_bread_2_checkout($order_id);
            } elseif ($bread_version === 'classic') {
                return $this->process_bread_classic_checkout($order_id);
            }
        }

        public function settle_transaction($order_id) {
            $order = wc_get_order($order_id);
            if ($order->get_payment_method() === $this->bread_config->get('gateway_id')) {
                $bread_env = $this->load_bread_env();
                
                $order_api_version = $order->get_meta('bread_api_version');
                if($order_api_version && in_array($order_api_version, ['bread_2','classic'])) {
                    $bread_env = $order_api_version;
                }
                $bread_api = $this->load_bread_api_version($bread_env);
                
                $transactionId = $order->get_meta('bread_tx_id');
                $transactionStatus = $order->get_meta('bread_tx_status');

                // Temporary fix for orders marked as unsettled instead of authorized.
                if ($transactionStatus === 'unsettled' || $transactionStatus === 'pending') {
                    $transactionStatus = 'authorized';
                }

                if ('settled' === $transactionStatus) {
                    return true;
                }

                $tx_duplicate = $this->parse_api_response($bread_api->getTransaction($transactionId));
                if (strtolower($tx_duplicate['status']) === 'settled') {
                    return true;
                }

                if (strtolower($tx_duplicate['status']) === 'authorized') {
                    $transactionStatus = 'authorized';
                }

                if ('authorized' !== strtolower($transactionStatus)) {
                    if ($transactionStatus === '') {
                        $transactionStatus = 'undefined';
                    }
                    $error = new \WP_Error('bread-error-settle', __("Transaction status is $transactionStatus. Unable to settle.", $this->bread_config->get('text_domain')));
                    $order->update_status('on-hold', $error->get_error_message());
                    return $error;
                }

                $tx = '';
                if ($bread_env === 'bread_2') {
                    $tx = $this->parse_api_response($bread_api->settleTransaction($transactionId, $tx_duplicate['totalAmount']['value'], $tx_duplicate['totalAmount']['currency']));
                } else {
                    $tx = $this->parse_api_response($bread_api->settleTransaction($transactionId));
                }
                if ($this->has_error($tx)) {
                    $tx_duplicate = $this->parse_api_response($bread_api->getTransaction($transactionId));
                    if (strtolower($tx_duplicate['status']) === 'settled') {
                        $order->update_meta_data('bread_tx_status', 'settled');
                        return true;
                    }

                    $error = new \WP_Error('bread-error-settle', $tx['error']);
                    $order->update_status('on-hold', $error->get_error_message());
                    return $error;
                }

                $this->add_order_note($order, $tx);
                $this->updateOrderTxStatus($order, $tx);
                $order->save();

                return true;
            }
        }
        
        /**
         * Settle a bread transaction when a bread order is completed
         */
        public function settle_order($order_id) {
            $order   = wc_get_order( $order_id );
            $gateway_id = $this->bread_config->get('gateway_id');
            $payment_method = $order->get_payment_method();
            if ($order->get_payment_method() !== $this->bread_config->get('gateway_id')) {
                $this->log(
                    __FUNCTION__,
                    "Skipping settle $this->method_title on platform order: $order_id because payment_method: $payment_method does not match Gateway Id: $gateway_id"
            );
                return;
            }
            
            $result = $this->settle_transaction($order_id);
            if (is_wp_error($result)) {
                $errorMessage = $result->get_error_message();
                $order->add_order_note($errorMessage);

                $tx_id = $order->get_meta('bread_tx_id');
                $this->log_Bread_issue("error", "[Plugin] " . $errorMessage, array('orderId' => $order_id, 'txId' => $tx_id));
            }
        }
        
        /**
         * 
         * @param type $order_id
         * @return \WP_Error|boolean
         */
        public function cancel_transaction($order_id) {
            $order = wc_get_order($order_id);
            if ($order->get_payment_method() === $this->bread_config->get('gateway_id')) {
                $bread_env = $this->load_bread_env();
                
                $order_api_version = $order->get_meta('bread_api_version');
                if($order_api_version && in_array($order_api_version, ['bread_2','classic'])) {
                    $bread_env = $order_api_version;
                }
                $bread_api = $this->load_bread_api_version($bread_env);

                $transactionId = $order->get_meta('bread_tx_id');
                $transactionStatus = $order->get_meta('bread_tx_status');

                if (in_array($transactionStatus, ['pending', 'canceled', 'refunded'])) {
                    return $this->add_note_error($order, new \WP_Error('bread-error-cancel', __("Transaction status is $transactionStatus. Unable to cancel.", $this->bread_config->get('text_domain'))));
                }

                $trx = $this->parse_api_response($bread_api->getTransaction($transactionId));

                if ($this->has_error($trx)) {
                    $error = new \WP_Error('bread-error-cancel', $trx['error']);
                    return $error;
                }

                if (strtolower($trx['status']) === 'cancelled') {
                    $this->add_order_note($order, $trx);
                    $this->updateOrderTxStatus($order, $trx);
                    $order->save();
                    return true;
                }

                $transactionStatus = strtolower($trx['status']);

                if ('authorized' === strtolower($transactionStatus)) {
                    if ($bread_env === 'bread_2') {
                        $tx = $this->parse_api_response($bread_api->cancelTransaction($transactionId, $trx['totalAmount']['value'], $trx['totalAmount']['currency']));
                    } else {
                        $tx = $this->parse_api_response($bread_api->cancelTransaction($transactionId));
                    }
                    
                    if ($this->has_error($tx)) {
                        return $this->add_note_error($order, new \WP_Error('bread-error-cancel', $tx['error']));
                    }
                    
                    $this->add_order_note($order, $tx);
                    $this->updateOrderTxStatus($order, $tx);
                    $order->save();
                }

                return true;
            }
        }

        public function process_refund($order_id, $amount = null, $reason = '') {
            $order = wc_get_order($order_id);
            
            if (!$order) {
                return new \WP_Error(
                        'bread-error-refund',
                        __('Refund failed: Unable to retrieve customer order')
                );
            }

            $payment_method = $order->get_payment_method();
                      
            if ($payment_method === $this->bread_config->get('gateway_id')) {
                
                $order_total = floatval( $order->get_total() );
		if ( ! $amount ) {
			$amount = $order_total;
		}                

                if ($amount == '0.00') {
                    return new \WP_Error(
                            'bread-error-refund',
                            __('Refund failed: Refund amount specified is $0.00')
                    );
                }

                $bread_env = $this->load_bread_env();
                
                $order_api_version = $order->get_meta('bread_api_version');
                if($order_api_version && in_array($order_api_version, ['bread_2','classic'])) {
                    $bread_env = $order_api_version;
                }
                $bread_api = $this->load_bread_api_version($bread_env);
                
                $bread_utilities = Bread_Finance_Utilities::instance();

                $transactionId = $order->get_meta('bread_tx_id');
                $refundAmount = $bread_utilities->priceToCents($amount);
                
                if ($bread_env === 'bread_2') {
                    $tx = $this->parse_api_response($bread_api->refundTransaction($transactionId, $refundAmount, $order->get_currency()));
                } else {
                    $tx = $this->parse_api_response($bread_api->refundTransaction($transactionId, $refundAmount));
                }

                if ($this->has_error($tx)) {
                    return new \WP_Error('bread-error-refund', $tx['error']);
                }

                if ($order->get_total() === $order->get_total_refunded() && strtolower($tx['status']) === 'refunded') {
                    $order->update_status('refunded');
                }

                $this->updateOrderTxStatus($order, $tx);
                $this->add_note_refunded($order, $tx, $amount);

                $order->save();

                return true;
            }
        }

        /**
         * @param $order_id
         * @return array[]
         */
        public function process_bread_2_checkout($order_id) {
            try {

                if (!$this->bread_finance_plugin) {
                    $this->bread_finance_plugin = Bread_Finance_Plugin::instance();
                }

                $this->log(
                        __FUNCTION__,
                        "Process $this->method_title platform order. #" . $order_id
                );

                $this->bread_finance_api = $this->load_bread_api_version("bread_2");
                #$this->bread_finance_api = Bread_Finance_V2_Api::instance();

                $txToken = null;
                if (isset($_REQUEST['bread_tx_token'])) {
                    $txToken = $_REQUEST['bread_tx_token'];
                } elseif (isset($_POST['bread_tx_token'])) {
                    $txToken = $_POST['bread_tx_token'];
                }

                $order = wc_get_order($order_id);

                $transaction = $this->parse_api_response($this->bread_finance_api->getTransaction($txToken));
                $this->log(
                        __FUNCTION__,
                        'Bread order info: ' . json_encode($transaction)
                );

                if ($this->has_error($transaction)) {
                    return $this->error_result($transaction);
                }
                $order->add_meta_data('bread_tx_id', $transaction['id']);
                $order->add_meta_data('bread_api_version', 'bread_2');
                $order->add_meta_data('payment_method', $this->id);
                $order->save();

                // Validate Transaction Amount is within 2 cents
                $validate_totals_response = $this->bread_finance_utilities->validateCalculatedTotals($order, $transaction, $this->bread_finance_plugin);
                $this->log(
                        __FUNCTION__,
                        'Validate order amount. Response: ' . json_encode($validate_totals_response)
                );
                if (is_wp_error($validate_totals_response)) {
                    wc_add_notice("An error occurred. $this->method_title transaction total does not match order total. Please try again.", 'error');
                    return $this->error_result($validate_totals_response);
                }

                // Authorize Transaction
                $authorized_transaction = $this->parse_api_response($this->bread_finance_api->authorizeTransaction($txToken, $transaction['totalAmount']['value'], $transaction['totalAmount']['currency'], $order_id));
                $this->log(
                        __FUNCTION__,
                        'Authorization request details. #' . json_encode($authorized_transaction)
                );
                if ($this->has_error($authorized_transaction)) {
                    return $this->error_result($authorized_transaction);
                }
                            
                // Validate Transaction Status / set order status
                if (strtoupper($authorized_transaction['status']) !== 'AUTHORIZED') {
                    $message = esc_html__('Transaction status is not currently AUTHORIZED. Order Status: ' . $authorized_transaction['status'], $this->bread_config->get('text_domain'));
                    $order->update_status('failed', $message);
                    $order->save();
                    return $this->error_result($message);
                }
                $this->add_order_note($order, $authorized_transaction);
                $order->update_status('on-hold');

                // Update billing contact from bread transaction
                $contact = array_merge(
                        array(
                            'lastName' => $authorized_transaction['billingContact']['name']['familyName'],
                            'firstName' => $authorized_transaction['billingContact']['name']['givenName'],
                            'address2' => '',
                            'country' => $order->get_billing_country()
                        ),
                        $authorized_transaction['billingContact']
                );

                $order->set_address(array(
                    'first_name' => $contact['firstName'],
                    'last_name' => $contact['lastName'],
                    'address_1' => $contact['address']['address1'],
                    'address_2' => $contact['address']['address2'],
                    'city' => $contact['address']['locality'],
                    'state' => $contact['address']['region'],
                    'postcode' => $contact['address']['postalCode'],
                    'country' => $contact['address']['country'],
                    'email' => $contact['email'],
                    'phone' => $contact['phone']
                        ), 'billing');

                $this->updateOrderTxStatus($order, $authorized_transaction);
                $order->save();
                
                //Attach orderId to the breadTranasction
                $merchantOrderId = $order->get_id();
                $updateOrderDetails = $this->bread_finance_api->updateTransactionMerchantOrderId($txToken, $merchantOrderId);
                $this->log(
                        __FUNCTION__,
                        'Update orderId on merchant portal. ' . json_encode($updateOrderDetails)
                );
                
                // Settle Bread transaction (if auto-settle enabled)
                if ($this->is_auto_settle()) {
                    $this->log(
                            __FUNCTION__,
                            "#$order_id. Auto settle order enabled"
                    );
                    $transactionId = $order->get_meta('bread_tx_id');
                    $transactionStatus = strtolower($order->get_meta('bread_tx_status'));

                    // Temporary fix for orders marked as unsettled instead of authorized.
                    if ($transactionStatus === 'unsettled' || $transactionStatus === 'pending') {
                        $transactionStatus = 'authorized';
                    }

                    if ('settled' !== $transactionStatus) {

                        if (strtolower($transactionStatus) === 'authorized') {
                            $transactionStatus = 'authorized';
                        }

                        if ('authorized' !== strtolower($transactionStatus)) {
                            if ($transactionStatus === '') {
                                $transactionStatus = 'undefined';
                            }
                            $error = new \WP_Error('bread-error-settle', __("Transaction status is $transactionStatus. Unable to settle.", $this->bread_config->get('text_domain')));
                            $order->update_status('on-hold', $error->get_error_message());
                            $order->save();
                        } else {
                            $tx = '';
                            $tx = $this->parse_api_response($this->bread_finance_api->settleTransaction($transactionId, $transaction['totalAmount']['value'], $transaction['totalAmount']['currency']));
                            $this->log(
                                    __FUNCTION__,
                                    "#$order_id. Settle transaction details: " . json_encode($tx)
                            );

                            if ($this->has_error($tx)) {
                                $tx_duplicate = $this->parse_api_response($this->bread_finance_api->getTransaction($transactionId));
                                if (strtolower($tx_duplicate['status']) === 'settled') {
                                    $order->update_meta_data('bread_tx_status', 'settled');
                                    $order->update_status('processing');
                                } else {
                                    $error = new \WP_Error('bread-error-settle', $tx['error']);
                                    $order->update_status('on-hold', $error->get_error_message());
                                }
                            } else {
                                $this->add_order_note($order, $tx);
                                $this->updateOrderTxStatus($order, $tx);
                                $order->update_status('processing');
                            }
                            $order->save();
                        }
                    }
                }

                /**
                 * To reduce stock from Bread plugin, uncomment below
                 */
                //wc_reduce_stock_levels( $order );
                WC()->cart->empty_cart();

                return array(
                    'result' => 'success',
                    'redirect' => $this->get_return_url($order)
                );
            } catch (\Exception $e) {
                Bread_Finance_Logger::log( 'Error: ' . $e->getMessage() );
                return array(
                    'result' => 'failure',
                    'redirect' => ''
                );
            } 
        }

        /**
         * @param $order_id
         * @return array[]
         */
        public function process_bread_classic_checkout($order_id) {
            try {

                if (!$this->bread_finance_plugin) {
                    $this->bread_finance_plugin = Bread_Finance_Plugin::instance();
                }

                $this->log(
                        __FUNCTION__,
                        "Process $this->method_title classic order. #" . $order_id
                ); 
                
                $this->bread_finance_api = Bread_Finance_Classic_Api::instance();

                $txToken = $_REQUEST['bread_tx_token'];
                $order = wc_get_order($order_id);

                $transaction = $this->parse_api_response($this->bread_finance_api->getTransaction($txToken));
                if ($this->has_error($transaction)) {
                    return $this->error_result($transaction);
                }
                
                $this->log(
                        __FUNCTION__,
                        "$this->method_title transaction details: " . json_encode($transaction)
                );
                
                $order->add_meta_data('bread_tx_id', $transaction['breadTransactionId']);
                $order->add_meta_data('bread_api_version', 'classic');
                $order->save();

                // Validate Transaction Amount is within 2 cents
                $this->log(
                        __FUNCTION__,
                        "Order Total: " . $order->get_total() . " .. Trx Total: " . $transaction['adjustedTotal']
                );
                $validate_totals_response = $this->bread_finance_utilities->validateCalculatedTotals($order, $transaction, $this->bread_finance_plugin);
                $this->log(
                        __FUNCTION__,
                        'Transaction totals validation: ' . json_encode($validate_totals_response)
                );
                if (is_wp_error($validate_totals_response)) {
                    wc_add_notice("An error occurred. $this->method_title transaction total does not match order total. Please try again.", 'error');
                    return $this->error_result($validate_totals_response);
                }

                // Authorize Transaction
                $authorized_transaction = $this->parse_api_response($this->authorize_transaction($transaction['breadTransactionId'], $order_id));
                $this->log(
                        __FUNCTION__,
                        'Transaction authorization status: ' . json_encode($authorized_transaction)
                );
                if ($this->has_error($authorized_transaction)) {
                    if ($this->is_split_pay_decline($authorized_transaction['error'])) {
                        $this->handle_split_pay_decline($order);
                        wc_add_notice($this->sp_decline_message, 'error');
                    }
                    return $this->error_result($authorized_transaction);
                }

                // Validate Transaction Status / set order status
                if (strtoupper($authorized_transaction['status']) !== 'AUTHORIZED') {
                    $message = esc_html__('Transaction status is not currently AUTHORIZED', $this->bread_config->get('text_domain'));
                    $order->update_status('failed', $message);
                    $order->save();
                    return $this->error_result($message);
                }
                $this->add_order_note($order, $authorized_transaction);
                $order->update_status('on-hold');

                // Update billing contact from bread transaction
                $name = explode(' ', $authorized_transaction['billingContact']['fullName']);
                $contact = array_merge(
                        array(
                            'lastName' => array_pop($name),
                            'firstName' => implode(' ', $name),
                            'address2' => '',
                            'country' => $order->get_billing_country()
                        ),
                        $authorized_transaction['billingContact']
                );

                $order->set_address(array(
                    'first_name' => $contact['firstName'],
                    'last_name' => $contact['lastName'],
                    'address_1' => $contact['address'],
                    'address_2' => $contact['address2'],
                    'city' => $contact['city'],
                    'state' => $contact['state'],
                    'postcode' => $contact['zip'],
                    'country' => $contact['country'],
                    'email' => $contact['email'],
                    'phone' => $contact['phone']
                        ), 'billing');

                $this->updateOrderTxStatus($order, $authorized_transaction);
                $order->save();

                // Settle Bread transaction (if auto-settle enabled)
                if ($this->is_auto_settle()) {
                    //@todo Settle this transaction on API
                    $order->update_status('processing');
                }

                /**
                 * To reduce stock from Bread plugin, uncomment below
                 */
                //wc_reduce_stock_levels( $order );
                WC()->cart->empty_cart();

                return array(
                    'result' => 'success',
                    'redirect' => $this->get_return_url($order)
                );
            } catch (\Exception $e) {
                Bread_Finance_Logger::log( 'Error: ' . $e->getMessage() );
                return array(
                    'result' => 'failure',
                    'redirect' => ''
                );
            }
        }

        //------------------------------------------------------------------------
        //Custom Bread functions 
        //------------------------------------------------------------------------

        /**
         * 
         * @param type $errorMessage
         * @return type
         */
        public function is_split_pay_decline($errorMessage) {
            $spDeclineDescription = "There's an issue with authorizing the credit card portion";
            return strpos($errorMessage, $spDeclineDescription) !== false;
        }

        /**
         * 
         * @param type $order
         * @return type
         */
        public function handle_split_pay_decline($order) {

            $orderNote = "Transaction FAILED to authorize. The credit/debit card portion of this transaction was declined.";

            if ($this->get_option('auto_cancel') === 'yes') {
                $tx_id = $order->get_meta('bread_tx_id');
                $canceledTx = $this->bread_finance_api->cancelTransaction($tx_id);
                if ($canceledTx["error"]) {
                    $orderNote .= " Call to cancel $this->method_title transaction FAILED. " . $canceledTx["description"];
                } else {
                    $orderNote .= " $this->method_title transaction successfully canceled.";
                    $order->add_meta_data('bread_tx_status', 'canceled');
                }
                $order->update_status("failed", $orderNote);
            } else {
                $order->add_meta_data('bread_tx_status', 'pending');
                $order->add_order_note($orderNote);
            }

            $order->save();
            return;
        }

        /**
         * Add a Bread status note to the order. Automatically calls the corresponding note function based
         * on the current transaction status.
         *
         * @param $order
         * @param $tx
         */
        public function add_order_note($order, $tx) {
            if (strtolower($tx['status']) === 'cancelled') {
                $tx['status'] = 'canceled';
            }
            call_user_func_array(array($this, 'add_note_' . strtolower($tx['status'])), array($order, $tx));
        }

        /**
         * @param $order \WC_Order
         * @param $tx array
         */
        private function add_note_authorized($order, $tx) {
            $bread_env = $this->load_bread_env();
            if ($bread_env === 'bread_2') {
                $note = $this->method_title . " Transaction Authorized for " . wc_price($tx['adjustedAmount']['value'] / 100) . ".";
                $note .= " (Transaction ID " . $tx['id'] . ")";
                $order->add_order_note($note);
            } else {
                $note = $this->method_title . " Transaction Authorized for " . wc_price($tx['adjustedTotal'] / 100) . ".";
                $note .= " (Transaction ID " . $tx['breadTransactionId'] . ")";
                $order->add_order_note($note);
            }
        }

        private function add_note_pending($order, $tx) {
            $bread_env = $this->load_bread_env();
            if ($bread_env === 'bread_2') {
                $order->add_order_note($this->method_title . " Transaction ID " . $tx['id'] . " Pending.");
            } else {
                $order->add_order_note($this->method_title . " Transaction ID " . $tx['breadTransactionId'] . " Pending.");
            }
        }

        /**
         * @param $order \WC_Order
         * @param $tx array
         */
        private function add_note_settled($order, $tx) {
            $bread_env = $this->load_bread_env();
            if ($bread_env === 'bread_2') {
                $order->add_order_note($this->method_title . " Transaction ID " . $tx['id'] . " Settled.");
            } else {
                $order->add_order_note($this->method_title . " Transaction ID " . $tx['breadTransactionId'] . " Settled.");
            }
        }

        /**
         * @param $order \WC_Order
         * @param $tx array
         */
        private function add_note_refunded($order, $tx, $amount = null) {
            $refundAmount = $amount ? ' ' . wc_price($amount) . ' ' : '';
            $bread_env = $this->load_bread_env();
            if ($bread_env === 'bread_2') {
                $order->add_order_note($this->method_title . " Transaction ID " . $tx['id'] . $refundAmount . " Refunded.");
            } else {
                $order->add_order_note($this->method_title . " Transaction ID " . $tx['breadTransactionId'] . $refundAmount . " Refunded.");
            }
        }

        /**
         * @param $order \WC_Order
         * @param $tx array
         */
        private function add_note_canceled($order, $tx) {
            $bread_env = $this->load_bread_env();
            if ($bread_env === 'bread_2') {
                $order->add_order_note($this->method_title . " Transaction ID " . $tx['id'] . " Cancelled.");
            } else {
                $order->add_order_note($this->method_title . " Transaction ID " . $tx['breadTransactionId'] . " Cancelled.");
            }
        }

        /**
         * @param $order \WC_Order
         * @param $error \WP_Error
         *
         * @return \WP_Error
         */
        private function add_note_error($order, $error) {
            $order->add_order_note($error->get_error_message());
            return $error;
        }

        /**
         * Update the order w/ the current Bread transaction status.
         *
         * @param $order \WC_Order
         * @param $tx array Bread API transaction object
         */
        private function updateOrderTxStatus($order, $tx) {
            $order->update_meta_data('bread_tx_status', strtolower($tx['status']));
        }

        /**
         * Parse the Bread API response.
         *
         * Pass every response through this function to automatically check for errors and return either
         * the original response or an error response.
         *
         * @param $response array|\WP_Error
         *
         * @return array
         */
        private function parse_api_response($response) {

            if ($response == null) {
                return $response;
            }

            // curl or other error (WP_Error)
            if (is_wp_error($response)) {
                return array('error' => $response->get_error_message());
            }

            // api error
            if (array_key_exists('error', $response)) {
                $description = isset($response['description']) ? $response['description'] : '';
                return array(
                    'error' => $response['error'],
                    'description' => $description,
                );
            }

            return $response;
        }

        /**
         * @param $response array
         *
         * @return bool
         */
        private function has_error($response) {
            if(is_array($response)) {
                return ( array_key_exists('error', $response) );
            }
            return false;
        }

        /**
         * @param string|array $error The error message of a transaction error response object.
         *
         * @return array
         */
        private function error_result($error) {
            return array(
                'result' => 'failure',
                'message' => is_array($error) ? $error['error'] : $error
            );
        }

        //Add the API key to the script
        public function add_api_key_to_script($tag, $handle, $src) {
            if ('bread-api' === $handle) {
                $tag = '<script type="text/javascript" src="' . esc_url($src) . '" data-api-key="' . $this->get_api_key() . '"></script>';
            }
            return $tag;
        }

        /**
         * @param $tag
         * @return string
         */
        function add_defer_tags_to_scripts($tag) {
            $scripts_to_defer = array("{$this->bread_config->get('tenant_prefix')}-sdk");

            foreach ($scripts_to_defer as $current_script) {
                if (true == strpos($tag, $current_script))
                    return str_replace(' src', ' src', $tag);
            }

            return $tag;
        }

        /**
         * Add Cors headers 
         */
        public function add_cors_headers($headers) {
            header("Access-Control-Allow-Origin: " . $this->bread_config->get('checkout_host'));
        }

        /**
         * Load main script
         */
        public function should_load_main_script($page_type) {
            switch (strtolower($page_type)) {
                case 'category':
                case 'product':
                    return strlen($this->get_configuration_setting('button_location_' . strtolower($page_type)));
                case 'cart_summary':
                    return strlen($this->get_configuration_setting('button_location_cart'));
                case 'checkout':
                    return strlen($this->get_configuration_setting('button_location_checkout'));
                default:
                    return true;
            }
        }

        /**
         * Check API and secret Key api for validation
         */
        public function validate_api_keys() {
            if (!$this->bread_finance_api) {
                $this->bread_finance_api = $this->load_bread_api_version();
            }

            $bread_version = $this->get_option('env_bread_api') ? $this->get_option('env_bread_api') : 'classic';
            $response = null;
            $is_valid_response = false;
            if ($bread_version === 'bread_2') {
                //Get the API key and secret
                $response = $this->bread_finance_api->get_token();
                $is_valid_response = !is_wp_error($response) && isset($response["token"]);
                if ($is_valid_response) {
                    $bread_auth_token = $this->get_option('bread_auth_token');
                    if ($bread_auth_token) {
                        update_option('bread_auth_token', $response['token']);
                    } else {
                        add_option('bread_auth_token', $response['token']);
                    }
                }
            } else {
                $data = array(
                    "customTotal" => 100,
                );
                $response = $this->bread_finance_api->getAsLowAs($data);
                $is_valid_response = !is_wp_error($response) && isset($response["asLowAs"]);
            }

            if ($response == null || !$is_valid_response) {
                echo '<script type="text/javascript">';
                echo "console.log('" . json_encode($response) . "');";
                echo 'alert("Your API and/or Secret key appear to be incorrect. Please ensure the inputted keys match the keys in your merchant portal.")';
                echo '</script>';
            }
        }

        /**
         * Create Bread cart opts
         */
        public function create_cart_opts($order) {
            $orderRef = strval($order->get_id());
            $enableHealthcareMode = $this->is_healthcare_mode();

            $opts = array(
                "options" => array(
                    "orderRef" => $orderRef,
                    "errorUrl" => home_url() . '?orderRef=' . $orderRef,
                    "completeUrl" => home_url(),
                    "customTotal" => intval($order->get_total() * 100),
                    "disableEditShipping" => true,
                ),
                "cartOrigin" => "woocommerce_carts",
            );

            $opts["options"]["shippingContact"] = array(
                "firstName" => $order->get_shipping_first_name(),
                "lastName" => $order->get_shipping_last_name(),
                "address" => $order->get_shipping_address_1(),
                "address2" => $order->get_shipping_address_2(),
                "city" => $order->get_shipping_city(),
                "state" => $order->get_shipping_state(),
                "zip" => $order->get_shipping_postcode(),
                "phone" => $order->get_billing_phone(),
            );
            $opts["options"]["billingContact"] = array(
                "firstName" => $order->get_billing_first_name(),
                "lastName" => $order->get_billing_last_name(),
                "email" => $order->get_billing_email(),
                "address" => $order->get_billing_address_1(),
                "address2" => $order->get_billing_address_2(),
                "city" => $order->get_billing_city(),
                "state" => $order->get_billing_state(),
                "zip" => $order->get_billing_postcode(),
                "phone" => $order->get_billing_phone(),
            );

            if (!$enableHealthcareMode) {
                if ($this->bread_finance_utilities->isAvataxEnabled()) {
                    wc_avatax()->get_order_handler()->calculate_order_tax($order);
                }
                $opts["options"]["tax"] = $this->bread_finance_utilities->priceToCents($order->get_cart_tax() + $order->get_shipping_tax());

                /* Add discounts */
                $discount_amount = $this->bread_finance_utilities->priceToCents($order->get_discount_total());
                if ($discount_amount > 0) {
                    $opts["options"]["discounts"][0] = array(
                        "description" => "Discounts: " . implode(", ", $order->get_coupon_codes()),
                        "amount" => $discount_amount,
                    );
                }

                /* Add selected shipping option */
                $opts["options"]["shippingOptions"][0] = array(
                    "type" => "Shipping",
                    "typeId" => "ShippingId",
                    "cost" => intval($order->get_shipping_total() * 100),
                );

                /* Add line items */
                $items = array();
                foreach ($order->get_items() as $item_id => $item_data) {
                    $product = wc_get_product($item_data['product_id']);
                    if (!$product)
                        break;

                    $imageId = $product->get_image_id();
                    $imageUrl = $imageId ? wp_get_attachment_image_src($imageId)[0] : "";
                    $detailUrl = get_permalink($product->get_id()) ?: "";

                    $item = array(
                        "quantity" => $item_data->get_quantity(),
                        "price" => intval($item_data->get_total() * 100),
                        "imageUrl" => $imageUrl,
                        "detailUrl" => $detailUrl,
                        "name" => $product->get_name(),
                        "sku" => $product->get_sku(),
                    );
                    array_push($items, $item);
                }
                $opts["options"]["items"] = $items;
            }
            return $opts;
        }
        
        public function validate_cart_opts($opts) {

            if ($opts["options"]["customTotal"] == 0)
                return "total";
                
            $items = array(
                "firstName", "lastName", "address", "city", "state", "zip", "phone"
            );

            /* Check if billing contact is complete */
            foreach ($items as $item) {
                if (strlen($opts["options"]["billingContact"][$item]) === 0) {
                    return "billing " . $item;
                }
            }

            /* If shipping option provided, check if shipping contact is complete */
            if (count($opts["options"]["shippingOptions"]) > 0) {
                foreach ($items as $item) {
                    if (strlen($opts["options"]["shippingContact"][$item]) === 0) {
                        return "shipping " . $item;
                    }
                }
            }

            return "";
        }
        
        /**
         * 
         * @param type $order
         * @param type $bread_cart
         */
        public function update_cart_custom_fields($order, $bread_cart) {
            $bread_api = $this->load_bread_api_version();
            $bread_cart_link = isset($bread_cart["url"]) ? $bread_cart["url"] : null;
            $bread_cart_id = isset($bread_cart["id"]) ? $bread_cart["id"] : null;

            if ($bread_cart_link === null) {
                $order->add_order_note("Error: An error occurred. Please check the request body and try again.");
                $this->log_Bread_issue("error", "[WCGateway] $this->method_title cart link is null", $bread_cart);
            } else {
                $order->add_order_note("$this->method_title cart link successfully created under the Custom Fields section. " . $bread_cart_link);
                if ($order->meta_exists("bread_cart_id")) {
                    $bread_api->expireBreadCart($order->get_meta("bread_cart_id"));
                    $order->update_meta_data("bread_cart_id", $bread_cart_id);
                } else {
                    $order->add_meta_data("bread_cart_id", $bread_cart_id);
                }
            }

            if ($order->meta_exists("bread_cart_link")) {
                $order->update_meta_data("bread_cart_link", $bread_cart_link);
            } else {
                $order->add_meta_data("bread_cart_link", $bread_cart_link);
            }
            $order->save();
        }
        
        /**
         * Send a cart
         *
         * @param \WC_Order $order
         * @param string		$method
         *
         * @return array|\WP_Error
         */
        public function send_bread_cart_link($order, $method) {
            $env = $this->load_bread_env();
            $bread_cart_id = $order->get_meta("bread_cart_id");
            $bread_api = $this->load_bread_api_version();
            $endpoint = '/carts/' . $bread_cart_id . '/' . $method;
            $payload = ( $method === 'text' ) ? array("phone" => $order->get_billing_phone()) : array(
                "name" => $order->get_formatted_billing_full_name(),
                "email" => $order->get_billing_email()
            );
            $response = null;
            if ($env === 'bread_2') {
                $response = $bread_api->sendBreadCartLink($bread_cart_id, array());
            } else {
                $response = $bread_api->sendBreadCartLink($endpoint, $payload);
            }
            if (is_wp_error($response)) {
                $order->add_order_note("Error: " . json_encode($response));
                $reqInfo = $payload;
                $reqInfo['endpoint'] = $endpoint;
                $this->log_Bread_issue("error", "[WCGateway] " . $response, $reqInfo);
            } else {
                $order->add_order_note(ucfirst($method) . " successfully sent to " . $order->get_formatted_billing_full_name());
            }
        }

        /**
         * 
         * @param type $data
         * @param type $errors
         * @return type
         */
        public function prevent_order_creation_during_validation($data, $errors) {
            if (!array_key_exists('bread_validate', $_REQUEST)) {
                return;
            }

            if (empty($errors->get_error_messages())) {
                wp_send_json(array('result' => 'success'));
                wp_die(0);
            }
        }

        /**
         * 
         * @return string
         */
        public function is_production() {
            return ( $this->get_option('environment') === 'production' );
        }

        /**
         * 
         * @return string
         */
        public function get_environment() {
            return $this->get_option('environment');
        }

        /**
         * 
         * @return string
         */
        public function get_api_key() {
            return $this->get_option($this->get_environment() . '_api_key');
        }

        /**
         * 
         * @return string
         */
        public function get_api_secret_key() {
            return $this->get_option($this->get_environment() . '_api_secret_key');
        }
               
        /**
         * 
         * @return string
         */
        public function get_classic_api_key() {
            return $this->get_option($this->get_environment() . '_classic_api_key');
        }

        /**
         * 
         * @return string
         */
        public function get_classic_api_secret_key() {
            return $this->get_option($this->get_environment() . '_classic_api_secret_key');
        }
        
        
        /**
         * 
         * @return string
         */
        public function get_api_base_url() {
            return $this->get_option($this->get_environment() . '_api_base_url');
        }

        /**
         * 
         * @return string
         */
        public function get_integration_key() {
            return $this->get_option($this->get_environment() . '_integration_key');
        }

        /**
         * 
         * @return string
         */
        public function get_api_url() {
            return $this->is_production() ? $this->bread_config->get('bread_host') : $this->bread_config->get('bread_host_sandbox');
        }

        /**
         * 
         * @return string
         */
        public function get_checkout_url() {
            return $this->is_production() ? $this->bread_config->get('checkout_host') : $this->bread_config->get('checkout_host_sandbox');
        }

        /**
         * Get a configuration item
         */
        public function get_configuration_setting($setting_slug) {
            if ($this->get_option($setting_slug)) {
                return $this->get_option($setting_slug);
            }
            return null;
        }

        /**
         * 
         * @return string
         */
        public function display_advanced_settings() {
            $settings = $this->get_option('advanced_settings');
            return isset($settings[0]['display_advanced_settings']) && $settings[0]['display_advanced_settings'] === 'on';
        }

        public function is_auto_settle() {
            $settings = $this->get_option('advanced_settings');
            return isset($settings[0]['auto_settle_enabled']) && $settings[0]['auto_settle_enabled'] === 'on';
        }

        public function is_healthcare_mode() {
            $settings = $this->get_option('advanced_settings');
            $bread_version = $this->get_option('env_bread_api') ? $this->get_option('env_bread_api') : 'classic';
            return $bread_version === 'classic' && isset($settings[0]['healthcare_mode_enabled']) && $settings[0]['healthcare_mode_enabled'] === 'on';
        }

        public function default_show_in_window() {
            $settings = $this->get_option('advanced_settings');
            return isset($settings[0]['show_in_new_window_enabled']) && $settings[0]['show_in_new_window_enabled'] === 'on';
        }

        public function is_price_threshold_enabled() {
            $settings = $this->get_option('advanced_settings');
            return isset($settings[0]['price_threshold_enabled']) && $settings[0]['price_threshold_enabled'] === 'on';
        }

        public function show_button_for_composite() {
            if ($this->is_price_threshold_enabled()) {
                $settings = $this->get_option('advanced_settings');
                return isset($settings[0]['price_threshold_composite']) && $settings[0]['price_threshold_composite'] === 'on';
            } else {
                return false;
            }
        }

        public function get_price_threshold() {
            $settings = $this->get_option('advanced_settings');
            $threshold_exists = $this->is_price_threshold_enabled() && isset($settings[0]['price_threshold_amount']);
            return $threshold_exists ? $settings[0]['price_threshold_amount'] : 0;
        }

        public function is_targeted_financing_enabled() {
            $settings = $this->get_option('advanced_settings');
            return isset($settings[0]['targeted_financing_enabled']) && $settings[0]['targeted_financing_enabled'] === 'on';
        }

        public function get_financing_program_id() {
            $settings = $this->get_option('advanced_settings');
            $financing_id_exists = $this->is_targeted_financing_enabled() && isset($settings[0]['financing_program_id']);
            return $financing_id_exists ? $settings[0]['financing_program_id'] : "";
        }

        public function get_tf_price_threshold() {
            $settings = $this->get_option('advanced_settings');
            $tf_threshold_exists = $this->is_targeted_financing_enabled() && isset($settings[0]['tf_price_threshold_amount']);
            return $tf_threshold_exists ? $settings[0]['tf_price_threshold_amount'] : 0;
        }

        public function get_products_to_exclude() {
            $settings = $this->get_option('advanced_settings');
            return isset($settings[0]['products_to_exclude']) ? $settings[0]['products_to_exclude'] : "";
        }

        public function get_sp_decline_message() {
            return $this->sp_decline_message;
        }

        /**
         * Save advanced settings
         */
        public function save_advanced_settings() {

            $advanced_settings = array();
            $refs = array(
                'display-advanced-settings' => 'display_advanced_settings',
                'auto-settle' => 'auto_settle_enabled',
                'healthcare-mode' => 'healthcare_mode_enabled',
                'default-show-in-window' => 'show_in_new_window_enabled',
                'price-threshold-enabled' => 'price_threshold_enabled',
                'price-threshold-amount' => 'price_threshold_amount',
                'price-threshold-composite' => 'price_threshold_composite',
                'targeted-financing-enabled' => 'targeted_financing_enabled',
                'financing-program-id' => 'financing_program_id',
                'tf-price-threshold-amount' => 'tf_price_threshold_amount',
                'products-to-exclude' => 'products_to_exclude',
            );

            foreach ($refs as $name => $setting) {
                if (isset($_POST[$name])) {
                    $advanced_settings[$setting] = wc_clean(wp_unslash($_POST[$name]));
                }
            }

            $this->update_option('advanced_settings', array($advanced_settings));
        }

        public function generate_advanced_settings_html() {
            ob_start();

            $display_advanced_settings = $this->display_advanced_settings() ? 'checked' : '';
            $auto_settle_enabled = $this->is_auto_settle() ? 'checked' : '';
            $healthcare_mode_enabled = $this->is_healthcare_mode() ? 'checked' : '';
            $show_in_new_window_enabled = $this->default_show_in_window() ? 'checked' : '';
            $price_threshold_enabled = $this->is_price_threshold_enabled() ? 'checked' : '';
            $price_threshold_amount = $this->get_price_threshold();
            $price_threshold_composite = $this->show_button_for_composite() ? 'checked' : '';
            $targeted_financing_enabled = $this->is_targeted_financing_enabled() ? 'checked' : '';
            $financing_program_id = $this->get_financing_program_id();
            $tf_price_threshold = $this->get_tf_price_threshold();
            $products_to_exclude = $this->get_products_to_exclude();
            ?>
            <tr>
                <th><?php echo esc_html__('Advanced Settings', $this->bread_config->get('text_domain')); ?></th>
                <td>
            <?php
            echo '<input type="checkbox" name="display-advanced-settings" id="display-advanced-settings" ' . esc_attr($display_advanced_settings) . '/> 
							Display advanced settings.'
            ?>
                </td>
            </tr>
            <tr class="bread-advanced-settings">
                <th><?php echo esc_html__('Auto-Settle', $this->bread_config->get('text_domain')); ?></th>
                <td>
                    <?php
                    echo '<input type="checkbox" name="auto-settle" ' . esc_attr($auto_settle_enabled) . '/> 
							Auto-settle transactions from Bread.';
                    ?>
                </td>
            </tr>
            <tr class="bread-advanced-settings">
                <th><?php echo esc_html__('Enable Healthcare Mode (Classic)', $this->bread_config->get('text_domain')); ?></th>
                <td>
                    <?php
                    echo '<input type="checkbox" name="healthcare-mode" ' . esc_attr($healthcare_mode_enabled) . '/> 
							Enable healthcare mode.'
                    ?>
                </td>
            </tr>
            <tr class="bread-advanced-settings">
                <th><?php echo esc_html__('Show in New Window', $this->bread_config->get('text_domain')); ?></th>
                <td>
                    <?php
                    echo '<input type="checkbox" name="default-show-in-window" ' . esc_attr($show_in_new_window_enabled) . '/> 
							Launch ' . $this->bread_config->get('tenant_name'). ' checkout in a new window regardless of device or browser.'
                    ?>
                </td>
            </tr>
            <tr class="bread-advanced-settings">
                <th><?php echo esc_html__('Enable Price Threshold', $this->bread_config->get('text_domain')); ?></th>
                <td>
                    <?php
                    // Only display this option if the Composite Product plugin is installed
                    $composite_products_option_html = function_exists('WC_CP') ?
                            '<input name="price-threshold-composite" type="checkbox"' . esc_attr($price_threshold_composite) . '/>
						Display ' . $this->bread_config->get('tenant_name') . ' on all composite product pages. <em>Recommended if you have composite products with base prices of $0 or null.</em>
						<br/><br/>' : '';

                    echo '<input type="checkbox" name="price-threshold-enabled" id="price-threshold-enabled" ' . esc_attr($price_threshold_enabled) . '/> 
									Hide ' . $this->bread_config->get('tenant_name') . ' buttons for products/cart sizes under a specified price threshold. 
								<div class="threshold" style="padding-left: 3em"><br/>'
                    . $composite_products_option_html .
                    '<div>Enter desired price threshold in integers only (e.g., 60 for $60):</div> <br/>
									<input name="price-threshold-amount" type="number" min="0" step="1" value="' . esc_attr($price_threshold_amount) . '"/>
								</div>'
                    ?>
                </td>
            </tr>
            <tr class="bread-advanced-settings">
                <th><?php echo esc_html__("Disable " . $this->bread_config->get('tenant_name') . " for Specific Product IDs", $this->bread_config->get('text_domain')); ?></th>
                <td>
                    <?php
                    echo '<div><br/>
									<textarea rows="3" cols="20" class="input-text wide-input" name="products-to-exclude" type="textarea">' . $products_to_exclude . '</textarea>
									<p class="description">Enter a comma-separated list of product IDs where ' . $this->bread_config->get('tenant_name') . ' should be disabled (ex: ID1, ID2, ID3).</p>
								</div>'
                    ?>
                </td>
            </tr>
            <tr class="bread-advanced-settings">
                <th><?php echo esc_html__('Cart-Size Targeted Financing', $this->bread_config->get('text_domain')); ?></th>
                <td>
                    <?php
                    echo '<input type="checkbox" name="targeted-financing-enabled" id="targeted-financing-enabled" ' . esc_attr($targeted_financing_enabled) . '/> 
								Enable Cart-Size Targeted Financing
							<div class="tf-threshold" style="padding-left: 3em"><br/>
								<div>Enter targeted financing id:</div><br/>
								<input name="financing-program-id" type="text" value="' . esc_attr($financing_program_id) . '"/>
								<br/><br/>
								<div>Enter desired price threshold in integers only (e.g., 60 for $60):</div><br/>
								<input name="tf-price-threshold-amount" type="number" min="0" step="1" value="' . esc_attr($tf_price_threshold) . '"/>
							</div>'
                    ?>
                </td>
            </tr>

            <script type="text/javascript">
                jQuery(function () {
                    var $display_advanced_settings = jQuery('#display-advanced-settings');
                    var $bread_advanced_settings = jQuery('.bread-advanced-settings');

                    if ($display_advanced_settings.attr('checked'))
                        $bread_advanced_settings.show()
                    else
                        $bread_advanced_settings.hide();

                    $display_advanced_settings.on('change', function () {
                        $bread_advanced_settings.toggle(this.checked);
                    });

                    var $price_threshold_enabled = jQuery('#price-threshold-enabled');
                    var $threshold = jQuery('.threshold');

                    if ($price_threshold_enabled.attr('checked'))
                        $threshold.show()
                    else
                        $threshold.hide();

                    $price_threshold_enabled.on('change', function () {
                        $threshold.toggle(this.checked);
                    });

                    var $targeted_financing_enabled = jQuery('#targeted-financing-enabled');
                    var $tf_threshold = jQuery('.tf-threshold');

                    if ($targeted_financing_enabled.attr('checked'))
                        $tf_threshold.show();
                    else
                        $tf_threshold.hide();

                    $targeted_financing_enabled.on('change', function () {
                        $tf_threshold.toggle(this.checked);
                    });

                    return false;
                });
            </script>
            <?php
            return ob_get_clean();
        }

        /**
         * Validate the supplied product Ids
         */
        public function validate_product_id_list() {
            $product_array = explode(",", $this->get_products_to_exclude());
            if (!empty($product_array)) {
                $unknownProducts = array();
                foreach ($product_array as $product_id) {
                    $product_id = trim($product_id);
                    if (!empty($product_id)) {
                        $product = wc_get_product($product_id);
                        if (is_wp_error($product) || !$product)
                            array_push($unknownProducts, $product_id);
                    }
                }
            }

            if (count($unknownProducts) > 0) {
                echo '<script type="text/javascript">';
                echo 'alert("The following product IDs were not found: ' . implode(", ", $unknownProducts) . '." )';
                echo '</script>';
            }
        }

        /**
         * 
         * Get the app icon if enabled
         * 
         * @return type
         */
        public function get_icon() {
            if ('yes' === $this->get_option('display_icon')) {
                $icon_src = plugins_url('/assets/image/' . $this->bread_config->get('gateway_id') . '_logo.png', $this->main_file_path);
                $icon_html = '<img src="' . $icon_src . '" alt="' . $this->bread_config->get('tenant_name') . '" style="height: 30px; border-radius:0px;"/>';
                return apply_filters('wc_bread_finance_checkout_icon_html', $icon_html);
            }
        }

        /**
         * Prevent WooCommerce from loading/saving to the main cart session when performing certain AJAX requests.
         *
         * To properly calculate tax and shipping we need to create a `WC_Cart` session with the selected products
         * and user data. This is complicated by the fact that WooCommerce will attempt to load the user's cart
         * when creating an instance of `WC_Cart`, first by using the cart cookie, then from the logged-in user
         * if the cookie fails.
         *
         * By using a custom null session handler we are able to create in-memory carts, disconnected from the
         * user's main cart session, for the purposes of accurately calculating tax & shipping.
         *
         */
        public function anonymize_tax_and_shipping_ajax() {

            if (!( defined('DOING_AJAX') && DOING_AJAX )) {
                return;
            }

            // @formatter:off
            if (!( array_key_exists('action', $_REQUEST) && strpos($_REQUEST['action'], 'bread') === 0 )) {
                return;
            }
            // @formatter:on
            // We want to use the main cart session when the user is on the "view cart" page.
            if (isset($_REQUEST['page_type']) && $_REQUEST['page_type'] === 'cart_summary') {
                return;
            }

            if (in_array($_REQUEST['action'], ['bread_calculate_tax', 'bread_calculate_shipping'])) {

                require_once $this->plugin_path . '/classes/class-bread-finance-session-handler.php';

                add_filter('woocommerce_session_handler', function ($handler) {
                    return "\Bread_Finance\Classes\Bread_Finance_Session_Handler";
                }, 99, 1);
            }
        }

        /**
         * 
         * @return type
         */
        public function init_bread_cart() {
            if (!array_key_exists('add-to-cart', $_POST)) {
                return;
            }

            // @formatter:off
            if (!( array_key_exists('action', $_REQUEST) && in_array($_REQUEST['action'], ['bread_get_options', 'bread_calculate_tax', 'bread_calculate_shipping']) )) {
                return;
            }
            // @formatter:on

            require_once $this->plugin_path . '/classes/class-bread-finance-session-handler.php';

            add_filter('woocommerce_session_handler', function ($handler) {
                return \Bread_Finance\Classes\Bread_Finance_Session_Handler::class;
            }, 99, 1);
        }

        public function prevent_bread_cart_persistence($check, $object_id, $meta_key, $meta_value, $prev_value) {
            if (!array_key_exists('add-to-cart', $_POST)) {
                return $check;
            }
            
            // @formatter:off
            if (!( array_key_exists('action', $_REQUEST) && in_array($_REQUEST['action'], ['bread_get_options', 'bread_calculate_tax', 'bread_calculate_shipping']) )) {
                return $check;
            }
            // @formatter:on

            return strpos($meta_key, '_woocommerce_persistent_cart') === 0;
        }

        public function empty_bread_cart() {
            if (!array_key_exists('add-to-cart', $_POST)) {
                return;
            }

            if (!( array_key_exists('action', $_REQUEST) && strpos($_REQUEST['action'], 'bread') === 0 )) {
                return;
            }

            WC()->cart->empty_cart();
        }

        public function external_plugin_compatibility() {
            $captcha_plugin = \Bread_Finance\Classes\Compat\Bread_Finance_Captcha::instance();
            $captcha_plugin->run_compat();
        }

        public function add_rewrite_tags() {
            add_rewrite_tag('%orderRef%', '([^&]+)');
            add_rewrite_tag('%transactionId%', '([^&]+)');
        }

        public function process_bread_cart_order() {
            ob_start();
            $this->bread_finance_plugin = Bread_Finance_Plugin::instance();
            if (!$this->enabled) {
                return;
            }
            
            $env = $this->load_bread_env();
            $order_id = get_query_var('orderRef');
            $order = wc_get_order($order_id);
            if($env === 'bread_2') {
                $action = get_query_var('action');
                //Do we have a checkout error?
                if($action == 'checkout-error') {
                    /* Error URL Route */
                    wc_get_logger()->debug('Checkout error');
                    $errorMessage = 'Note: Customer was not approved for financing or attempted to use an expired Bread cart link';
                    $order = wc_get_order($order_id);
                    $order->add_order_note($errorMessage);
                    $order->save();

                    $this->log_Bread_issue("warning", "[Plugin] " . $errorMessage, array('orderId' => $order_id));

                    wp_redirect(home_url());
                    ob_flush();
                    exit;
                }
                
                //Do we have a checkout complete
                if($action == 'checkout-complete') {
                    wc_get_logger()->debug('Checkout complete');
                    $message = 'Note: Customer checkout action complete';
                    $order = wc_get_order($order_id);
                    $order->add_order_note($message);
                    $order->save();
                    wp_redirect($order->get_checkout_order_received_url());
                    ob_flush();
                    exit;
                }
                
                //Do we have a success checkout callback
                if($action == 'callback') {
                    wc_get_logger()->debug('Success callback');
                    $tx_id = null;
                    $data = json_decode(file_get_contents('php://input'), true);
                    if(isset($data['transactionId'])) {
                        $tx_id = trim($data['transactionId']);
                    }
                    $tx = $this->get_transaction($tx_id);
                    $this->bread_finance_utilities->validateCalculatedTotals($order, $tx);
                    $response = $this->process_bread_platform_cart_payment($order_id, $tx_id);
                    $bread_api = $this->load_bread_api_version();
                    $bread_api->expireBreadCart($order->get_meta("bread_cart_id"));
                    wp_redirect($order->get_checkout_order_received_url());
                    ob_flush();
                    exit;
                }
            } else {
                $tx_id = get_query_var('transactionId');
                if (strlen($tx_id) > 0 && strlen($order_id) > 0) {
                    /* Complete URL Route */

                    $tx = $this->get_transaction($tx_id);
                    
                    $this->bread_finance_utilities->validateCalculatedTotals($order, $tx);

                    $response = $this->process_bread_cart_payment($order_id, $tx_id);
                    $bread_api = $this->load_bread_api_version();
                    $bread_api->expireBreadCart($order->get_meta("bread_cart_id"));
                    $order->update_meta_data('bread_cart_link', 'Bread cart link has expired');

                    if ($response['result'] === 'error') {
                        $order->update_status('failed');
                        $order->add_order_note($response['message']);
                        $order->save();

                        $errorInfo = array(
                            'response' => $response,
                            'txId' => $tx_id,
                            'orderId' => $order_id
                        );

                        $this->log_Bread_issue("error", "[Plugin] " . $response['message'], $errorInfo);
                    }

                    wp_redirect($order->get_checkout_order_received_url());
                    ob_flush();
                    exit;
                } else if (strlen($order_id) > 0) {
                    /* Error URL Route */

                    $errorMessage = 'Note: Customer was not approved for financing or attempted to use an expired Bread cart link';
                    $order = wc_get_order($order_id);
                    $order->add_order_note($errorMessage);
                    $order->save();

                    $this->log_Bread_issue("warning", "[Plugin] " . $errorMessage, array('orderId' => $order_id));

                    wp_redirect(home_url());
                    ob_flush();
                    exit;
                }
            } 
        }

        /**
         * @param $order_id
         * @param $tx_id
         * @return array[]
         */
        public function process_bread_cart_payment($order_id, $tx_id) {
            $order = wc_get_order($order_id);
            $tx = $this->get_transaction($tx_id);
            if ($this->has_error($tx)) {
                return array(
                    'result' => 'error',
                    'message' => 'Error retrieving transaction',
                    'tx' => $tx,
                );
            }
            $order->add_meta_data('bread_tx_id', $tx['breadTransactionId']);
            $order->save();

            $authorized_tx = $this->authorize_transaction($tx_id, $order_id);
            if ($this->has_error($authorized_tx)) {
                if ($this->is_split_pay_decline($authorized_tx['error'])) {
                    $this->handle_split_pay_decline($order);
                }
                return array(
                    'result' => 'error',
                    'message' => 'Transaction was NOT AUTHORIZED. Please create a new cart and try again.',
                    'tx' => $authorized_tx
                );
            }

            $order->update_status('on-hold');
            $this->updateOrderTxStatus($order, $authorized_tx);
            $order->update_meta_data( '_payment_method', $this->bread_config->get('gateway_id')); // Ensure Bread is selected payment method
            $order->save();

            if ($this->is_auto_settle()) {
                $order->update_status('processing');
            }

            return array(
                'result' => 'success',
            );
        }
        
        /**
         * Process bread platform cart payment
         */
        public function process_bread_platform_cart_payment($order_id, $tx_id) {
            $order = wc_get_order($order_id);
            $env = $this->load_bread_env();
            $transaction = $this->get_transaction($tx_id);
            if ($this->has_error($transaction)) {
                return array(
                    'result' => 'error',
                    'message' => 'Error retrieving transaction',
                    'tx' => $transaction,
                );
            }
            $order->add_meta_data('bread_tx_id', $transaction['id']);
            $order->save();
            
            $this->bread_finance_api = Bread_Finance_V2_Api::instance();
            
            $authorized_transaction = $this->parse_api_response($this->bread_finance_api->authorizeTransaction($tx_id, $transaction['totalAmount']['value'], $transaction['totalAmount']['currency'], $order_id));
            $this->log(
                    __FUNCTION__,
                    'Authorization request details. #' . json_encode($authorized_transaction)
            );
            if ($this->has_error($authorized_transaction)) {
                return $this->error_result($authorized_transaction);
            }

            // Validate Transaction Status / set order status
            if (strtoupper($authorized_transaction['status']) !== 'AUTHORIZED') {
                $message = esc_html__('Transaction status is not currently AUTHORIZED. Order Status: ' . $authorized_transaction['status'], $this->bread_config->get('text_domain'));
                $order->update_status('failed', $message);
                $order->save();
                return $this->error_result($message);
            }
            $this->add_order_note($order, $authorized_transaction);
            $order->update_status('on-hold');

            // Update billing contact from bread transaction
            $contact = array_merge(
                    array(
                        'lastName' => $authorized_transaction['billingContact']['name']['familyName'],
                        'firstName' => $authorized_transaction['billingContact']['name']['givenName'],
                        'address2' => '',
                        'country' => $order->get_billing_country()
                    ),
                    $authorized_transaction['billingContact']
            );

            $order->set_address(array(
                'first_name' => $contact['firstName'],
                'last_name' => $contact['lastName'],
                'address_1' => $contact['address']['address1'],
                'address_2' => $contact['address']['address2'],
                'city' => $contact['address']['locality'],
                'state' => $contact['address']['region'],
                'postcode' => $contact['address']['postalCode'],
                'country' => $contact['address']['country'],
                'email' => $contact['email'],
                'phone' => $contact['phone']
                    ), 'billing');

            $this->updateOrderTxStatus($order, $authorized_transaction);
            
            //Attach orderId to the breadTranasction
            $merchantOrderId = $order->get_id();
            $updateOrderDetails = $this->bread_finance_api->updateTransactionMerchantOrderId($tx_id, $merchantOrderId);
            
            //Set Payment method as Bread
            $payment_gateways = WC()->payment_gateways->payment_gateways();
            $order->set_payment_method($this->bread_config->get('gateway_id'));

            
            $order->save();
        }

        /**
         * 
         * @param type $cart_item_key
         * @param type $product_id
         * @param type $quantity
         * @param type $variation_id
         * @param type $variation
         * @param type $cart_item_data
         * @return type
         */
        public function handle_bread_cart_action($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data) {

            if (!array_key_exists('add-to-cart', $_POST)) {
                return;
            }

            if (!( array_key_exists('action', $_REQUEST) && strpos($_REQUEST['action'], 'bread') === 0 )) {
                return;
            }

            try {
                $shippingContact = ( array_key_exists('shipping_contact', $_REQUEST) ) ? $_REQUEST['shipping_contact'] : null;
                $billingContact = ( array_key_exists('billing_contact', $_REQUEST) ) ? $_REQUEST['billing_contact'] : null;

                $buttonHelper = Bread_Finance_Button_Helper::instance();
                $error_message = "Error getting $this->method_title options.";

                switch ($_POST['action']) {
                    case 'bread_get_options':
                        wp_send_json_success($buttonHelper->get_bread_options());
                        break;

                    case 'bread_calculate_tax':
                        $error_message = "Error calculating sales tax.";
                        $buttonHelper->update_cart_contact($shippingContact, $billingContact);
                        wp_send_json($buttonHelper->getTax());
                        break;

                    case 'bread_calculate_shipping':
                        $error_message = "Error calculating shipping.";
                        $buttonHelper->update_cart_contact($shippingContact, $billingContact);
                        wp_send_json($buttonHelper->getShipping());
                        break;
                }
            } catch (\Exception $e) {
                Bread_Finance_Logger::log( 'Error: ' . $e->getMessage() );
                wp_send_json_error(__($error_message, $this->bread_config->get('text_domain')));
            }
        }

        /**
         * Integration with Advanced Shipment Tracking [https://wordpress.org/plugins/woo-advanced-shipment-tracking/]
         *
         * Send shipment tracking information to Bread
         * @param type $meta_id
         * @param type $object_id
         * @param type $meta_key
         * @param type $meta_value
         */
        public function sendAdvancedShipmentTrackingInfo($meta_id, $object_id, $meta_key, $meta_value) {
            if ($meta_key === '_wc_shipment_tracking_items') {
                if ($order = wc_get_order($object_id)) {
                    if ($order->get_payment_method() == $this->bread_config->get('gateway_id')) {
                        if ($transactionId = $order->get_meta('bread_tx_id')) {
                            if (!empty($meta_value)) {
                                // $meta_value is an array of shipments
                                $shipment = end($meta_value);
                                $api = $this->load_bread_api_version();
                                $response = $api->updateShipment($transactionId, array(
                                    'trackingNumber' => $shipment['tracking_number'],
                                    'carrierName' => $shipment['tracking_provider'],
                                ));
                            }
                        }
                    }
                }
            }
        }

        public function log_Bread_issue($level, $event, $info) {
            $util = Bread_Finance_Utilities::instance();
            $isSentryEnabled = false;//$util->toBool($this->get_option('sentry_enabled'));
            
            if ($isSentryEnabled) {
                global $errorLevel, $errorInfo;
                $errorLevel = $level ? $level : "debug";
                $errorInfo = $info ? $info : array();
                Sentry\configureScope(function (Sentry\State\Scope $scope): void {
                    $scope->setExtra('issue_type', 'BreadIssue');

                    $levelString = 'Sentry\Severity::' . $GLOBALS["errorLevel"];
                    $scope->setLevel($levelString());

                    foreach ($GLOBALS["errorInfo"] as $key => $value) {
                        if ($key === 'txId') {
                            $scope->setTag($key, $value);
                        } else {
                            $scope->setExtra($key, json_encode($value));
                        }
                    }

                    $sentryInfo = $this->get_sentry_info();
                    $scope->setTag('plugin_version', $sentryInfo['plugin_version']);
                    $scope->setTag('merchant_api_key', $this->get_api_key());
                });

                if (is_string($event) || is_array($event)) {
                    Sentry\captureMessage(json_encode($event));
                } else {
                    Sentry\captureException($event);
                }
            }
        }

        public function get_transaction($tx_id) {
            $api = $this->load_bread_api_version();
            return $this->parse_api_response($api->getTransaction($tx_id));
        }

        public function authorize_transaction($tx_id, $order_id) {
            $api = $this->load_bread_api_version();
            return $this->parse_api_response($api->authorizeTransaction($tx_id, $order_id));
        }

        public function load_bread_api_version($api_version = null) {
            
            if (!is_null($api_version)) {
                $bread_version = in_array($api_version, ['classic', 'bread_2']) ? $api_version : $this->bread_config->get('default_sdk_version');
            } else {
                $bread_version = $this->get_option('env_bread_api') ? $this->get_option('env_bread_api') : $this->bread_config->get('default_sdk_version');
            }
            return Bread_Finance_Api_Factory::create($bread_version, $this->bread_config->get('sdk_versions'));
        }

        public function load_bread_env() {
            return $this->get_option('env_bread_api') ? $this->get_option('env_bread_api') : $this->bread_config->get('default_sdk_version');
        }

        public function load_sdk() {
            return $this->get_environment() === 'production' ? $this->bread_config->get('sdk_core') : $this->bread_config->get('sdk_core_sandbox');
        }

        public function load_api_base_url() {
            return $this->get_environment() === 'production' ? $this->bread_config->get('platform_domain_api') : $this->bread_config->get('platform_domain_api_sandbox');
        }
        
        /**
         * Setup Sentry
         * 
         * @return null
         */
        protected function configureSentry() {
            $response = $this->get_sentry_info();
            if (isset($response["dsn"]) && strlen($response["dsn"]) > 0) {
                $params = array(
                    'dsn' => $response["dsn"],
                    'bread_api_key' => $this->get_api_key(),
                    'plugin_version' => $response['plugin_version'],
                );
                wp_localize_script($this->sentryScript, 'mw_localized_data', $params);
                wp_cache_set("sentry-dsn", $response["dsn"], "", 60 * 60);
            } else {
                $error = array(
                    'message' => "EXCEPTION WHEN GETTING SENTRY DSN",
                    'response' => $response["error"],
                );
                error_log(print_r($error, true));
            }
        }
        
        /**
         * 
         * Fetch Sentry DSN info from API/cache
         * 
         * @return array
         */
        protected function get_sentry_info() {
            if (wp_cache_get("sentry_dsn")) {
                $response = array('dsn' => wp_cache_get("sentry_dsn"));
            } else {
                $api = Bread_Finance_Classic_Api::instance();
                $response = $this->parse_api_response($api->getSentryDSN());
            }

            $plugin_data = get_plugin_data(realpath($this->main_file_path));
            $response['plugin_version'] = $plugin_data['Version'];

            return $response;
        }
        
        /**
         * Add a custom action to order actions select box on edit order page
         * Only added for orders that aren't authorized or settled
         *
         * @param array $actions order actions array to display
         * @return array - updated actions
         */
        public function add_create_cart_options($actions) {
            $env = $this->load_bread_env();
            if ($env === 'bread_2') {
                global $theorder;

                if ($theorder->is_paid()) {
                    return $actions;
                }
                $actions['create_bread_cart_link'] = __('Create Bread cart link', $this->bread_config->get('text_domain'));
                $actions['email_bread_cart_link'] = __('Email Bread cart link', $this->bread_config->get('text_domain'));
                return $actions;
            } else {
                global $theorder;

                if ($theorder->is_paid()) {
                    return $actions;
                }
                $actions['create_bread_cart_link'] = __("Create $this->method_title cart link", $this->bread_config->get('text_domain'));
                $actions['email_bread_cart_link'] = __("Email $this->method_title cart link", $this->bread_config->get('text_domain'));
                $actions['text_bread_cart_link'] = __("Text $this->method_title cart link", $this->bread_config->get('text_domain'));
                return $actions;
            }
        }
        
        /**
         * 
         * @param array $qvars
         * @return string
         */
        public function custom_bread_vars($qvars) {
            $qvars[] = 'action';
            return $qvars;
        }
        
        /**
         * 
         * @param \WC_Order $order
	 * @return void
         */
        public function create_bread_cart_link($order) {
            $env = $this->load_bread_env();
            $bread_api = $this->load_bread_api_version();
            
            if ($this->bread_finance_utilities->isAvataxEnabled()) {
                wc_avatax()->get_order_handler()->calculate_order_tax($order);
            }
            // Recalculate totals, incl tax 
            $order->calculate_totals(true);
            if($env === 'bread_2') {
                //Check if bread_cart_meta exists
                //@todo. Fix this on 3.4. This hook gets called twice hence making the bread create cart action run twice
                if(!$order->get_meta('bread_cart_link')) {
                    $admin_carts_helper = Bread_Finance_Admin_Carts_Helper::instance();
                    $opts = $admin_carts_helper->create_cart_opts_platform($order, $this->getPlatformMerchantId(), $this->getPlatformProgramId());
                    $validate_cart_opts = $admin_carts_helper->validate_cart_opts_platform($opts);
                    if (strlen($validate_cart_opts) > 0) {
                        $errorMessage = "Error: Missing " . $validate_cart_opts . ". Please check order information and try again.";
                        $order->add_order_note($errorMessage);
                        $order->save();

                        $errorInfo = array(
                            'orderId' => strval($order->get_id()),
                            'opts' => $opts,
                            'missingInfo' => $validate_cart_opts,
                        );
                        $this->log_Bread_issue("debug", "[Plugin] Cannot create Bread cart. Missing information", $errorInfo);
                        return;
                    }
                    $bread_cart = $this->parse_api_response($bread_api->createBreadCart($opts));
                    $admin_carts_helper->update_platform_cart_custom_fields($order, $bread_cart, $bread_api);
                }
                return;
            } else {
                if ($this->bread_finance_utilities->isAvataxEnabled()) {
                    wc_avatax()->get_order_handler()->calculate_order_tax($order);
                }
                // Recalculate totals, incl tax 
                $order->calculate_totals(true);
                $opts = $this->create_cart_opts($order);
                $validate = $this->validate_cart_opts($opts);
                if (strlen($validate) > 0) {
                    $errorMessage = "Error: Missing " . $validate . ". Please check order information and try again.";
                    $order->add_order_note($errorMessage);
                    $order->save();

                    $errorInfo = array(
                        'orderId' => strval($order->get_id()),
                        'opts' => $opts,
                        'missingInfo' => $validate,
                    );
                    $this->log_Bread_issue("debug", "[Plugin] Cannot create $this->method_title cart. Missing information", $errorInfo);
                    return;
                }
                $bread_api = $this->load_bread_api_version();
                $bread_cart = $this->parse_api_response($bread_api->createBreadCart($opts));
                $this->update_cart_custom_fields($order, $bread_cart);
            }
        }

        /**
         * 
         * @param \WC_Order $order
	 * @return void
         */
        public function email_bread_cart_link($order) {
            $this->create_bread_cart_link($order);
            if ($order->meta_exists("bread_cart_link")) {
                $this->send_bread_cart_link($order, 'email');
            }
        }

        /**
         * 
         * @param \WC_Order $order
	 * @return void
         */
        public function text_bread_cart_link($order) {
            $env = $this->load_bread_env();
            if($env === 'classic') {
                $this->create_bread_cart_link($order);
                if ($order->meta_exists("bread_cart_link")) {
                    $this->send_bread_cart_link($order, 'text');
                }
            }     
            
        }
        
        /**
         * Get platform merchantId
         * 
         * @param null
         * @return string
         * @since 3.3.0
         */
        public function getPlatformMerchantId() {
            return $this->get_option($this->get_environment() . '_platform_merchantId');
        }

        /**
         * Get platform programId
         * 
         * @param null
         * @return string
         * @since 3.3.0
         */
        public function getPlatformProgramId() {
            return $this->get_option($this->get_environment() . '_platform_programId');
        }
        
        /**
         * Logs action
         *
         * @param string $context context.
         * @param string $message message.
         *
         * @return void
         */
        public function log($context, $message) {
            if ($this->get_option('debug')) {
                if (empty($this->log)) {
                    $this->log = new \WC_Logger();
                }

                $this->log->add(
                        'woocommerce-gateway: ' . $this->bread_config->get('gateway_id'),
                        $context . ' - ' . $message
                );

                if (defined('WP_DEBUG') && WP_DEBUG) {
                    // phpcs:disable WordPress.PHP.DevelopmentFunctions
                    error_log($context . ' - ' . $message);
                }
            }
        }

    }
    
}