<?php

namespace Bread_Finance\Classes;


if (!defined('ABSPATH')) {
    exit;
}

class Bread_Finance_Ajax extends \WC_AJAX {

    /**
     * @var $bread_finance_plugin
     */
    public $bread_finance_plugin = false;

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

    //Initialize our class
    public function __construct() {
        if (!$this->bread_finance_plugin) {
            $this->bread_finance_plugin = Bread_Finance_Plugin::instance();
        }
        self::add_ajax_events();
    }

    /**
     * Hook in methods - uses WordPress ajax handlers (admin-ajax).
     */
    public static function add_ajax_events() {
        $ajax_events = array(
            'bread_get_order_pay_opts' => true,
            'bread_get_options' => true,
            'bread_calculate_shipping' => true,
            'bread_calculate_tax' => true,
            'bread_set_qualstate' => true,
            'bread_complete_checkout' => true
        );

        foreach ($ajax_events as $ajax_event => $nopriv) {
            add_action('wp_ajax_' . $ajax_event, array(__CLASS__, $ajax_event));
            if ($nopriv) {
                add_action('wp_ajax_nopriv_' . $ajax_event, array(__CLASS__, $ajax_event));
                // WC AJAX can be used for frontend ajax requests.
                add_action('wp_ajax_' . $ajax_event, array(__CLASS__, $ajax_event));
            }
        }
    }

    /**
     * Get Bread Checkout opts
     */
    public function bread_get_order_pay_opts() {
        $nonce = isset($_POST['nonce']) ? sanitize_key($_POST['nonce']) : '';
        if (!wp_verify_nonce($nonce, 'get_bread_checkout_opts')) {
            wp_send_json_error('bad_nonce');
            exit;
        }

        if (!$this->bread_finance_plugin->get_bread_gateway()->enabled) {
            return;
        }

        try {
            // url: merchant_url.com/wordpress/checkout/order-pay/{order_id}/?pay_for_order=true&key=wc_order_{hash}
            $url = $_SERVER["HTTP_REFERER"];        
            $start = strpos($url, 'order-pay/') + strlen('order-pay/');
            $end = strpos($url, '/?pay_for_order');
            $order_id = substr($url, $start, $end - $start);
            $order = wc_get_order($order_id);
            $opts = $this->bread_finance_plugin->get_bread_gateway()->create_cart_opts($order);
            wp_send_json_success($opts);
        } catch (\Exception $e) {
            Bread_Finance_Logger::log( 'Error: ' . $e->getMessage() );
            wp_send_json_error(__("Error getting Bread options.", $this->bread_finance_plugin->get_text_domain()));
        }
    }

    /**
     * 
     * @return type
     */
    public static function bread_get_options() {

        $bread_finance_plugin = Bread_Finance_Plugin::instance();

        if (!$bread_finance_plugin->get_bread_gateway()->enabled) {
            return;
        }

        try {
            $button_helper = Bread_Finance_Button_Helper::instance();
            $options = $button_helper->get_bread_options();
            wp_send_json_success($options);
        } catch (\Exception $e) {
            Bread_Finance_Logger::log( 'Error: ' . $e->getMessage() );
            wp_send_json_error(__("Error getting Bread options.", $bread_finance_plugin->get_text_domain()));
        }
    }

    /**
     * Calculate shipping costs
     */
    public static function bread_calculate_shipping() {
        $button_helper = Bread_Finance_Button_Helper::instance();
        $bread_finance_plugin = Bread_Finance_Plugin::instance();

        try {
            if ($_REQUEST['page_type'] === 'cart_summary') {
                $button_helper->update_cart_contact($_REQUEST['shipping_contact']);
            } else {
                $button_helper->create_bread_cart($_REQUEST['button_opts'], $_REQUEST['shipping_contact']);
            }

            $shippingOptions = $button_helper->getShipping();
            wp_send_json($shippingOptions);
        } catch (\Exception $e) {
            Bread_Finance_Logger::log( 'Error: ' . $e->getMessage() );
            wp_send_json_error(__("Error calculating shipping.", $bread_finance_plugin->get_text_domain()));
        }
    }

    public static function bread_calculate_tax() {
        $button_helper = Bread_Finance_Button_Helper::instance();
        $bread_finance_plugin = Bread_Finance_Plugin::instance();

        try {
            $shipping_contact = $_REQUEST['shipping_contact'];
            $billing_contact = ( array_key_exists('billing_contact', $_REQUEST) ) ? $_REQUEST['billing_contact'] : null;

            if ($_REQUEST['page_type'] === 'cart_summary') {
                $button_helper->update_cart_contact($shipping_contact, $billing_contact);
            } else {
                $button_helper->create_bread_cart($_REQUEST['button_opts'], $shipping_contact, $billing_contact);
            }

            $tax = $button_helper->getTax();
            wp_send_json($tax);
        } catch (\Exception $e) {
            Bread_Finance_Logger::log( 'Error: ' . $e->getMessage() );
            wp_send_json_error(__("Error calculating sales tax.", $bread_finance_plugin->get_text_domain()));
        }
    }

    /**
     * Set customer qualification state when window closed
     * 
     * @return type
     */
    public static function bread_set_qualstate() {
        $bread_finance_plugin = Bread_Finance_Plugin::instance();
        if (!$bread_finance_plugin->get_bread_gateway()->enabled) {
            return;
        }

        if ($bread_finance_plugin->get_bread_gateway()->get_configuration_setting('default_payment') === 'no') {
            return;
        }

        switch ($_REQUEST['customer_data']['state']) {
            case 'PREQUALIFIED':
            case 'PARTIALLY_PREQUALIFIED':
                WC()->session->set('chosen_payment_method', $bread_finance_plugin->get_bread_gateway()->get('gateway_id'));
                break;
            default:
                WC()->session->set('chosen_payment_method', '');
        }

        WC()->session->set('bread_qualstate', $_REQUEST['customer_data']['state']);

        wp_send_json_success();
    }
    
    public static function bread_complete_checkout() {
        $bread_finance_plugin = Bread_Finance_Plugin::instance();
        $bread_finance_gateway = $bread_finance_plugin->get_bread_gateway();
        $bread_version  = $bread_finance_gateway->load_bread_env();

        $bread_checkout_options = Bread_Finance_Options_Checkout::instance();

        if($bread_version === 'bread_2') {
            self::bread_complete_checkout_bread_2($bread_finance_plugin, $bread_finance_gateway, $bread_checkout_options);
        } else {
            self::bread_complete_checkout_classic($bread_finance_plugin, $bread_finance_gateway);
        }

    }

    public static function bread_complete_checkout_classic($bread_finance_plugin, $bread_finance_gateway) {
        $bread_finance_api = $bread_finance_gateway->bread_finance_api;
        $bread_finance_utilities = $bread_finance_gateway->bread_finance_utilities;
        if (!$bread_finance_plugin->get_bread_gateway()->enabled) {
            return;
        }

        $tx_id = $_REQUEST['tx_id'];
        $form = $_REQUEST['form'];
        if (!$tx_id) {
            wp_send_json(array(
                'success' => false,
                'message' => __("Invalid Transaction ID", $bread_finance_plugin->get_text_domain())
            ));
        }

        $transaction = $bread_finance_api->getTransaction($tx_id);

        if (is_wp_error($transaction)) {
            wp_send_json(array(
                'success' => false,
                'message' => $transaction->get_error_message(),
                'url' => $bread_finance_api->bread_api_url
            ));
        }

        if (!isset($transaction['error'])) {
            if ($transaction['merchantOrderId'] === "") {
                $user_email = $transaction['billingContact']['email'];
                $order_user = get_user_by('email', $user_email);

                if ($order_user === false) {
                    $user_password = wp_generate_password();
                    $user_id = wp_create_user($user_email, $user_password, $user_email);
                    if (is_wp_error($user_id)) {
                        wp_send_json(array('success' => false, 'message' => $user_id->get_error_message()));
                    }
                    $order_user = get_user_by('id', $user_id);
                }

                $billing_names = explode(' ', $transaction['billingContact']['fullName']);
                $billing_last_name = array_pop($billing_names);
                $billing_first_name = implode(' ', $billing_names);

                $shipping_names = explode(' ', $transaction['shippingContact']['fullName']);
                $shipping_last_name = array_pop($shipping_names);
                $shipping_first_name = implode(' ', $shipping_names);

                $order = wc_create_order(array('customer_id' => $order_user->ID));

                /* Set the payment method details */
                $order->set_payment_method($bread_finance_gateway->id);
                $order->set_payment_method_title($bread_finance_gateway->method_title);
                $order->set_transaction_id($tx_id);
                $order->add_meta_data('bread_tx_id', $tx_id);
                $order->add_meta_data('bread_api_version', $bread_finance_gateway->load_bread_env());

                /* Set billing address */
                $order->set_address(array(
                    'first_name' => $billing_first_name,
                    'last_name' => $billing_last_name,
                    'company' => '',
                    'email' => $transaction['billingContact']['email'],
                    'phone' => $transaction['billingContact']['phone'],
                    'address_1' => $transaction['billingContact']['address'],
                    'address_2' =>isset($transaction['billingContact']['address2']) ? $transaction['billingContact']['address2'] : '',
                    'city' => $transaction['billingContact']['city'],
                    'state' => $transaction['billingContact']['state'],
                    'postcode' => $transaction['billingContact']['zip'],
                    'country' => $transaction['billingContact']['country'],
                ), 'billing');

                /* Set shipping address */
                $order->set_address(array(
                    'first_name' => $shipping_first_name,
                    'last_name' => $shipping_last_name,
                    'company' => '',
                    'phone' => $transaction['shippingContact']['phone'],
                    'address_1' => $transaction['shippingContact']['address'],
                    'address_2' => isset($transaction['shippingContact']['address2']) ? $transaction['shippingContact']['address2'] : '',
                    'city' => $transaction['shippingContact']['city'],
                    'state' => $transaction['shippingContact']['state'],
                    'postcode' => $transaction['shippingContact']['zip'],
                    'country' => $transaction['shippingContact']['country'],
                ), 'shipping');

                /* Add products */
                foreach ($transaction['lineItems'] as $item) {
                    /**
                     * WooCommerce may be overriding line breaks ("\n") and causing loss of formatting.
                     * This code modifies the product name so that each line appears as its own div and
                     * creates the appearance of line breaks.
                     */
                    $name = $item['product']['name'];
                    $name = "<div>" . $name . "</div>";
                    $name = str_replace("\n", "</div><div>", $name);

                    $product = wc_get_product($item['sku']);
                    $args = array(
                        'name' => $name,
                        'subtotal' => $bread_finance_utilities->priceToDollars($item['price'], $item['quantity']),
                        'total' => $bread_finance_utilities->priceToDollars($item['price'], $item['quantity']),
                    );

                    /* Set Variation data for variable products */
                    if ($product && $product->get_type() === 'variation') {
                        $variation = array();
                        foreach ($form as $input) {
                            if (preg_match('/attribute_(.+)/', $input['name'], $matches)) {
                                $variation[$matches[1]] = $input['value'];
                            }
                        }

                        foreach ($product->get_attributes() as $key => $value) {
                            if ($value) {
                                $variation[$key] = $value;
                            }
                        }
                        $args['variation'] = $variation;
                    }

                    $order->add_product($product, $item['quantity'], $args);
                }

                /* Add shipping */
                $shippingItem = new \WC_Order_Item_Shipping();
                $shippingItem->set_method_title($transaction['shippingMethodName']);
                $shippingItem->set_method_id($transaction['shippingMethodCode']);
                $shippingItem->set_total($bread_finance_utilities->priceToDollars($transaction['shippingCost'], 1));
                $order->add_item($shippingItem);
                $order->save();

                /* Add discounts */
                foreach ($transaction['discounts'] as $discount) {
                    $coupon_response = $order->apply_coupon($discount['description']);
                    if (is_wp_error($coupon_response)) {
                        $message = esc_html__("Error: " . $coupon_response->get_error_message(), $bread_finance_plugin->get_text_domain());
                        $order->update_status("failed", $message);
                        wp_send_json_error(__($message, $bread_finance_plugin->get_text_domain()));
                    }
                }

                /* Add tax */
                /* For merchants using AvaTax, use Avalara method to calculate tax for order */
                /* Tax calculation MUST happen after discounts are added to grab the correct AvaTax amount */
                if ($bread_finance_utilities->isAvataxEnabled()) {
                    wc_avatax()->get_order_handler()->calculate_order_tax($order);
                }
                $order->calculate_totals();

                $bread_finance_gateway->add_order_note($order, $transaction);

                /* Validate calculated totals */
                $validateTotalsResponse = $bread_finance_utilities->validateCalculatedTotals($order, $transaction, $bread_finance_plugin);
                if (is_wp_error($validateTotalsResponse)) {
                    $message = esc_html__("ALERT: Transaction amount does not equal order total.", $bread_finance_plugin->get_text_domain());
                    $order->update_status("failed", $message);
                    wp_send_json_error(__("ALERT: Bread transaction total does not match order total.", $bread_finance_plugin->get_text_domain()));
                }
                /* Authorize Bread transaction */
                $transaction = $bread_finance_api->authorizeTransaction($tx_id);
                if (strtolower($transaction['status']) !== 'authorized') {
                    $errorDescription = $transaction["description"];
                    $isSpDecline = $bread_finance_gateway->is_split_pay_decline($errorDescription);
                    if ($isSpDecline) {
                        $errorDescription = $bread_finance_gateway->get_sp_decline_message();
                        $bread_finance_gateway->handle_split_pay_decline($order);
                    } else {
                        $order->add_order_note("Transaction FAILED to authorize.");
                        $errorInfo = $transaction;
                        $errorInfo['txId'] = $tx_id;
                        $bread_finance_gateway->log_Bread_issue("error", "[AjaxHandlers] Transaction failed to authorize.", $errorInfo);
                    }
                    wp_send_json_error(array(
                        'message' => __("Transaction FAILED to authorize.", $bread_finance_plugin->get_text_domain()),
                        'response' => $errorDescription,
                        'spDecline' => $isSpDecline,
                    ));
                }

                $order->update_status('on-hold');
                $order->update_meta_data('bread_tx_status', 'authorized');
                $order->add_meta_data('bread_api_version', 'classic');
                $order->save();

                /* Settle Bread transaction (if auto-settle enabled) */
                if ($bread_finance_gateway->is_auto_settle()) {
                    $order->update_status('processing');
                }

                /* Update Bread transaction with the order id */
                $bread_finance_api->updateTransaction($tx_id, array('merchantOrderId' => (string) $order->get_id()));

                /* Clear the cart if requested */
                if (isset($_REQUEST['clear_cart']) and $_REQUEST['clear_cart']) {
                    WC()->cart->empty_cart();
                }

                wp_send_json_success(array(
                    'transaction' => $order->get_meta_data('bread_tx_status'),
                    'order_id' => $order->get_id(),
                    'redirect' => $order->get_checkout_order_received_url()
                ));
            } else {
                wp_send_json_error(array(
                    'message' => __('Transaction has already been recorded to order #', $bread_finance_plugin->get_text_domain()) . $transaction['merchantOrderId']
                ));
            }
        } else {
            wp_send_json_error(array(
                'message' => $transaction['description']
            ));
        }
    }

    public static function bread_complete_checkout_bread_2($bread_finance_plugin, $bread_finance_gateway, $bread_finance_checkout_options) {
        $bread_finance_api = $bread_finance_gateway->bread_finance_api;
        $bread_finance_utilities = $bread_finance_gateway->bread_finance_utilities;
        if (!$bread_finance_plugin->get_bread_gateway()->enabled) {
            return;
        }

        $tx_id = $_REQUEST['tx_id'];
        if (!$tx_id) {
            wp_send_json(array(
                'success' => false,
                'message' => __("Invalid Transaction ID", $bread_finance_plugin->get_text_domain())
            ));
        }

        $transaction = $bread_finance_api->getTransaction($tx_id);
        if (is_wp_error($transaction)) {
            wp_send_json(array(
                'success' => false,
                'message' => $transaction->get_error_message(),
                'url' => $bread_finance_api->get_bread_gateway()->get_api_base_url()
            ));
        }

        if (!isset($transaction['error']) || !$transaction['error']) {
            if (isset($transaction['externalID']) && $transaction['externalID'] === "") {
                
                //Get the list of cart items
                $checkout_options = $bread_finance_checkout_options->get_options();
                
                $user_email = $transaction['billingContact']['email'];
                $order_user = get_user_by('email', $user_email); 

                if ($order_user === false) {
                    $user_password = wp_generate_password();
                    $user_id = wp_create_user($user_email, $user_password, $user_email);
                    if (is_wp_error($user_id)) {
                        wp_send_json(array('success' => false, 'message' => $user_id->get_error_message()));
                    }
                    $order_user = get_user_by('id', $user_id);
                }

                $billing_last_name = $transaction['billingContact']['name']['familyName'];
                $billing_first_name = $transaction['billingContact']['name']['givenName'];


                $shipping_last_name = $transaction['shippingContact']['name']['familyName'];
                $shipping_first_name = $transaction['shippingContact']['name']['givenName'];

                $order = wc_create_order(array('customer_id' => $order_user->ID));

                /* Set the payment method details */
                $order->set_payment_method($bread_finance_gateway->id);
                $order->set_payment_method_title($bread_finance_gateway->method_title);
                $order->set_transaction_id($tx_id);
                $order->add_meta_data('bread_tx_id', $tx_id); 
                $order->add_meta_data('bread_api_version', $bread_finance_gateway->load_bread_env());
                $order->add_meta_data('payment_method', $bread_finance_gateway->id);
                /* Set billing address */
                $order->set_address(array(
                    'first_name' => $billing_first_name,
                    'last_name' => $billing_last_name,
                    'company' => '',
                    'email' => $transaction['billingContact']['email'],
                    'phone' => $transaction['billingContact']['phone'],
                    'address_1' => $transaction['billingContact']['address']['address1'],
                    'address_2' => isset($transaction['billingContact']['address']['address2']) ? $transaction['billingContact']['address']['address2'] : '',
                    'city' => $transaction['billingContact']['address']['locality'],
                    'state' => $transaction['billingContact']['address']['region'],
                    'postcode' => $transaction['billingContact']['address']['postalCode'],
                    'country' => $transaction['billingContact']['address']['country'],
                ), 'billing');

                /* Set shipping address */
                $order->set_address(array(
                    'first_name' => $shipping_first_name,
                    'last_name' => $shipping_last_name,
                    'company' => '',
                    'email' => $transaction['shippingContact']['email'],
                    'phone' => $transaction['shippingContact']['phone'],
                    'address_1' => $transaction['shippingContact']['address']['address1'],
                    'address_2' => isset($transaction['shippingContact']['address']['address2']) ? $transaction['shippingContact']['address']['address2'] : '',
                    'city' => $transaction['shippingContact']['address']['locality'],
                    'state' => $transaction['shippingContact']['address']['region'],
                    'postcode' => $transaction['shippingContact']['address']['postalCode'],
                    'country' => $transaction['shippingContact']['address']['country'],
                ), 'shipping');
                //@todo items are not being returned by the API
                /* Add products */
                foreach ($checkout_options['items'] as $item) {
                    /**
                     * WooCommerce may be overriding line breaks ("\n") and causing loss of formatting.
                     * This code modifies the product name so that each line appears as its own div and
                     * creates the appearance of line breaks.
                     */
                    $name = $item['name'];
                    $name = "<div>" . $name . "</div>";
                    $name = str_replace("\n", "</div><div>", $name);

                    $product = wc_get_product($item['sku']);
                    $args = array(
                        'name' => $name,
                        'subtotal' => $bread_finance_utilities->priceToDollars($item['price'], $item['quantity']),
                        'total' => $bread_finance_utilities->priceToDollars($item['price'], $item['quantity']),
                    );

                    //Set Variation data for variable products *
                    if ($product && $product->get_type() === 'variation') {
                        $variation = array();
                        foreach ($form as $input) {
                            if (preg_match('/attribute_(.+)/', $input['name'], $matches)) {
                                $variation[$matches[1]] = $input['value'];
                            }
                        }

                        foreach ($product->get_attributes() as $key => $value) {
                            if ($value) {
                                $variation[$key] = $value;
                            }
                        }
                        $args['variation'] = $variation;
                    }

                    $order->add_product($product, $item['quantity'], $args);
                }
                

                ///Add shipping
                $shippingItem = new \WC_Order_Item_Shipping();
                $shippingItem->set_method_title($checkout_options['shippingOptions'][0]['type']);
                $shippingItem->set_method_id($checkout_options['shippingOptions'][0]['typeId']);
                $shippingItem->set_total($bread_finance_utilities->priceToDollars($transaction['shippingAmount']['value'], 1));
                $order->add_item($shippingItem);
                $order->save();

                if(isset($checkout_options['discounts']) && sizeof($checkout_options['discounts']) > 0) {
                    foreach ($checkout_options['discounts'] as $discount) {
                        $coupon_response = $order->apply_coupon((string) $discount['description']);
                        if (is_wp_error($coupon_response)) {
                            $message = esc_html__("Error: " . $coupon_response->get_error_message(), $bread_finance_plugin->get_text_domain());
                            $order->update_status("failed", $message);
                            $bread_finance_utilities->json_error(__($message, $bread_finance_plugin->get_text_domain()));
                        }
                    }
                }
                
                

                /* Add tax */
                /* For merchants using AvaTax, use Avalara method to calculate tax for order */
                /* Tax calculation MUST happen after discounts are added to grab the correct AvaTax amount */
                if ($bread_finance_utilities->isAvataxEnabled()) {
                    wc_avatax()->get_order_handler()->calculate_order_tax($order);
                }
                $order->calculate_totals();

                $bread_finance_gateway->add_order_note($order, $transaction);
                /* Validate calculated totals */
                $validateTotalsResponse = $bread_finance_utilities->validateCalculatedTotals($order, $transaction, $bread_finance_plugin);
                if (is_wp_error($validateTotalsResponse)) {
                    $message = esc_html__("ALERT: Transaction amount does not equal order total.", $bread_finance_plugin->get_text_domain());
                    $order->update_status("failed", $message);
                    $bread_finance_utilities->json_error(__("ALERT: Bread transaction total does not match order total.", $bread_finance_plugin->get_text_domain()));
                }
                /* Authorize Bread transaction */
                $active_currency = $bread_finance_utilities->get_active_currency();
                $transaction = $bread_finance_api->authorizeTransaction($tx_id, $transaction['totalAmount']['value'], $active_currency);
                if (strtoupper($transaction['status']) !== 'AUTHORIZED') {
                    $errorDescription = $transaction["description"];

                    $order->add_order_note("Transaction FAILED to authorize.");
                    $errorInfo = $transaction;
                    $errorInfo['txId'] = $tx_id;
                    $bread_finance_gateway->log_Bread_issue("error", "[AjaxHandlers] Transaction failed to authorize.", $errorInfo);

                    $bread_finance_utilities->json_error(array(
                        'message' => __("Transaction FAILED to authorize.", $bread_finance_plugin->get_text_domain()),
                        'response' => $errorDescription,
                        'spDecline' => '',
                    ));
                }

                $order->update_status('on-hold');
                $order->update_meta_data('bread_tx_status', 'authorized');
                $order->add_meta_data('bread_api_version', $bread_finance_gateway->load_bread_api_version());
                $order->save();

                /* Settle Bread transaction (if auto-settle enabled) */
                if ($bread_finance_gateway->is_auto_settle()) {
                    $order->update_status('processing');
                }

                /* Update Bread transaction with the order id */
                $bread_finance_api->updateTransaction($tx_id, array('externalID' => (string) $order->get_id()));

                /* Clear the cart if requested */
                if (isset($_REQUEST['clear_cart']) and $_REQUEST['clear_cart']) {
                    WC()->cart->empty_cart();
                }

                $bread_finance_utilities->json_success(array(
                    'transaction' => $order->get_meta_data('bread_tx_status'),
                    'order_id' => $order->get_id(),
                    'redirect' => $order->get_checkout_order_received_url()
                ));
            } else {
                $bread_finance_utilities->json_error(array(
                    'message' => __('Transaction has already been recorded to order #', $bread_finance_plugin->get_text_domain()) . $transaction['merchantOrderId']
                ));
            }
        } else {
            $bread_finance_utilities->json_error(array(
                'message' => $transaction['description']
            ));
        }
    }

}

Bread_Finance_Ajax::instance();
