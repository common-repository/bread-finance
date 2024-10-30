<?php

/**
 * Bread v2 API
 *
 */

namespace Bread_Finance\Classes;

class Bread_Finance_V2_Api {

    /**
     * Reference singleton instance of this class
     *
     * @var $instance
     */
    private static $instance;

    /**
     * Bread Base URL
     */
    private $bread_base_url;

    /**
     * Main gateway
     */
    public $bread_finance_gateway = false;

    /**
     * API auth credentials
     * 
     * @var $basic_auth_credentials
     */
    public $basic_auth_credentials;

    /**
     *
     * @var type
     */
    public $bread_finance_utilities = false;
    public $api_base_url;
    public $integration_key;


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
        $this->set_bread_finance_gateway();
        $this->set_bread_finance_utilities();
        $this->basic_auth_credentials = 'Basic ' . base64_encode($this->bread_finance_gateway->get_api_key() . ':' . $this->bread_finance_gateway->get_api_secret_key());
        $this->api_base_url = $this->bread_finance_gateway->load_api_base_url();
        $this->integration_key = $this->bread_finance_gateway->get_integration_key();
    }

    protected function set_bread_finance_gateway() {
        if(!$this->bread_finance_gateway) {
            $this->bread_finance_gateway = new Bread_Finance_Gateway();
        }
    }
    
    protected function set_bread_finance_utilities() {
        if(!$this->bread_finance_utilities) {
            $this->bread_finance_utilities = Bread_Finance_Utilities::instance();
        }
    }
    
    
    public function get_bread_gateway() {
        if($this->bread_finance_gateway) {
            return $this->bread_finance_gateway;
        }

        $this->bread_finance_gateway = new Bread_Finance_Gateway();
        return $this->bread_finance_gateway;
    }

    public function get_token() {
        $wp_remote = 'wp_remote_post';

        $api_url = join('/', [rtrim($this->api_base_url, '/'), 'auth/service/authorize']);

        $result = call_user_func($wp_remote, $api_url, array(
            'method' => 'POST',
            'headers' => array('Content-Type' => 'application/json', 'Authorization' => $this->basic_auth_credentials),
        ));

        if (!is_wp_error($result)) {
            return json_decode($result['body'], true);
        }

        return $result;
    }

    public function getTransaction($tx_id) {
        $token = get_option('bread_auth_token');
        $response = $this->makeRequest('GET', $token, $this->api_base_url, "transaction/$tx_id", []);
        return $response;
    }


    public function authorizeTransaction($tx_id, $amount, $currency, $order_id = null) {   
        $params = '{"amount": {"currency":"' . $currency . '","value":' . $amount . '}}';
        $token = get_option('bread_auth_token');
        $response  = $this->makeRequest('POST', $token, $this->api_base_url, "transaction/$tx_id/authorize", $params, false);
        return $response;
    }

    public function cancelTransaction($tx_id, $amount, $currency, $order_id = null) {
        $params = '{"amount": {"currency":"' . $currency . '","value":' . $amount . '}}';
        $token = get_option('bread_auth_token');
        return $this->makeRequest('POST', $token, $this->api_base_url, "transaction/$tx_id/cancel", $params, false);
    }
    
    public function updateTransaction($tx_id, $params = array()) {
        $token = get_option('bread_auth_token');
        $response = $this->makeRequest('PATCH', $token, $this->api_base_url, "transaction/$tx_id", $params);
        return $response;
    }
    
    public function updateTransactionMerchantOrderId($tx_id, $merchantOrderId) {
        $data = '{"externalID":"' . $merchantOrderId . '","metadata":{"externalMerchantData":"externalInfo"}}';
        $token = get_option('bread_auth_token');
        $response = $this->makeRequest('PATCH', $token, $this->api_base_url, "transaction/$tx_id", $data, false);
        return $response;
    }

    public function settleTransaction($tx_id, $amount, $currency, $order_id = null) {
        $params = '{"amount": {"currency":"' . $currency . '","value":' . $amount . '}}';
        $token = get_option('bread_auth_token');
        return $this->makeRequest('POST', $token, $this->api_base_url, "transaction/$tx_id/settle", $params, false);
    }

    public function refundTransaction($tx_id, $amount, $currency, $order_id = null) {
        $params = '{"amount": {"currency":"' . $currency . '","value":' . $amount . '}}';
        $token = get_option('bread_auth_token');
        return $this->makeRequest('POST', $token, $this->api_base_url, "transaction/$tx_id/refund", $params, false);
    }
    
    public function updateShipment($tx_id, $payload) {
        $token = get_option('bread_auth_token');
        $params = '{"tracking_number":"' . $payload['trackingNumber'] . '","carrier":"' . $payload['carrierName'] . '"}';
        return $this->makeRequest('POST', $token, $this->api_base_url, "transaction/$tx_id/fulfillment", $params, false);
    }

    public function makeRequest($method, $token, $base_url, $endpoint, $payload, $jsonEncode = true) {
        $wp_remote = $method == 'GET' ? 'wp_remote_get' : 'wp_remote_post';
        $api_url = join('/', [rtrim($base_url, '/'), $endpoint]);
        $wp_payload = $method == 'GET' ? $payload : json_encode($payload);
        if (!$jsonEncode) {
            $wp_payload = $payload;
        }

        $request = [
            'method' => $method,
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $token),
            'body' => $wp_payload,
        ];

        Bread_Finance_Logger::log( "{$api_url} request: " . print_r( $request, true ) );

        $result = call_user_func($wp_remote, $api_url, $request);

        $authorization_error_check = wp_remote_retrieve_response_code($result);
        if ($authorization_error_check == '403' || $authorization_error_check == '401') {
            $response = $this->get_token();
            $is_valid_response = !is_wp_error($response) && isset($response["token"]);
            if ($is_valid_response) {
                $bread_auth_token = get_option('bread_auth_token');
                if ($bread_auth_token) {
                    update_option('bread_auth_token', $response['token']);
                } else {
                    add_option('bread_auth_token', $response['token']);
                }
            }
            
            $result = call_user_func($wp_remote, $api_url, array(
                'method' => $method,
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $response['token']),
                'body' => $wp_payload,
            ));
            
            if (is_wp_error($response) || empty($result['body'])) {
                Bread_Finance_Logger::log(
                        'Error response: ' . print_r($result, true) . PHP_EOL . 'Failed request: ' . print_r(
                                [
                                    'api_url' => $api_url,
                                    'request' => $request
                                ],
                                true
                        )
                );
            }

            $authorization_error_check = wp_remote_retrieve_response_code($result);
            if ($authorization_error_check === '403' || $authorization_error_check === '401') {
                return array(
                    'error' => 'jwt_auth_error',
                    'description' => 'Token validation error'
                );
            }   
        }


        if (!is_wp_error($result)) {
            return json_decode($result['body'], true);
        }

        return $result;
    }
    
    /**
     * 
     * @param type $payload
     * @param type $order
     * @return type
     */
    public function createBreadCart($payload) {
        $token = get_option('bread_auth_token');
        
        //Load items into a JSON payload string
        $items = "";
        foreach($payload['items'] as $item) {
            $items .= '{';
            $items .= '"name":"' . $item['name'] . '",';
            $items .= '"quantity":' . $item['quantity'] . ',';
            $items .= '"sku":"' . $item['sku'] . '",';
            $items .= '"itemUrl":"' . $item['itemUrl'] . '",';
            $items .= '"imageUrl":"' . $item['imageUrl'] . '",';
            $items .= '"description":"' . $item['description'] . '",';
            $items .= '"unitPrice":{"currency":"' . $item['unitPrice']['currency'] . '","value":' . $item['unitPrice']['value'] . '},';
            $items .= '"shippingCost":{"currency":"' . $item['unitPrice']['currency'] . '","value":0},';
            $items .= '"unitTax":{"currency":"' . $item['unitTax']['currency'] . '","value":' . $item['unitTax']['value'] . '}';
            $items .= '},';
        }
        $items = rtrim($items,',');
        
        //Format timestamp
        $datetime = \DateTime::createFromFormat("Y-m-d H:i:s", date('Y-m-d H:i:s'));
        $timestamp = $datetime->format(\DateTime::RFC3339);
        
        $params = '{'
                . '"callbackUrl":"' . $payload['callbackURL'] . '",'
                . '"checkoutCompleteUrl":"' . $payload['checkoutCompleteUrl'] . '",'
                . '"checkoutErrorUrl":"' . $payload['checkoutErrorUrl'] . '",'
                . '"isHipaaRestricted":true,'
                . '"orderReference":"' . $payload['orderReference'] . '",'
                . '"merchantID":"' . $payload['merchantID'] . '",'
                . '"programID":"' . $payload['programID'] . '",'
                . '"disclosures":[{'
                    . '"name":"one-time",'
                    . '"acceptedAt":"' . $timestamp . '"'
                . '}],'
                . '"contact":{'
                    . '"name":{'
                        . '"givenName":"' . $payload['contact']['name']['givenName'] . '",'
                        . '"familyName":"' . $payload['contact']['name']['familyName'] . '"'
                    . '},'
                    . '"phone":"' . $payload['contact']['phone'] . '",'
                    . '"shippingAddress":{'
                        . '"address1":"' . $payload['contact']['shippingAddress']['address1'] . '",'
                        . '"address2":"' . $payload['contact']['shippingAddress']['address2'] . '",'
                        . '"locality":"' . $payload['contact']['shippingAddress']['locality'] . '",'
                        . '"postalCode":"' . $payload['contact']['shippingAddress']['postalCode'] . '",'
                        . '"region":"' . $payload['contact']['shippingAddress']['region'] . '",'
                        . '"country":"' . $payload['contact']['shippingAddress']['country'] . '"},'
                    . '"billingAddress":{'
                        . '"address1":"' . $payload['contact']['billingAddress']['address1'] . '",'
                        . '"address2":"' . $payload['contact']['billingAddress']['address2'] . '",'
                        . '"locality":"' . $payload['contact']['billingAddress']['locality'] . '",'
                        . '"postalCode":"' . $payload['contact']['billingAddress']['postalCode'] . '",'
                        . '"region":"' . $payload['contact']['billingAddress']['region'] . '",'
                        . '"country":"' . $payload['contact']['billingAddress']['country'] . '"},'
                    . '"email":"' . $payload['contact']['email'] . '"'
                . '},'
                . '"order":{'
                    . '"subTotal":{'
                        . '"currency":"' . $payload['order']['subTotal']['currency'] . '",'
                        . '"value":' . $payload['order']['subTotal']['value'] . ''
                    . '},'
                    . '"totalDiscounts":{'
                        . '"currency":"' . $payload['order']['totalDiscounts']['currency'] . '",'
                        . '"value":' . $payload['order']['totalDiscounts']['value'] . ''
                    . '},'
                    . '"totalPrice":{'
                        . '"currency":"' . $payload['order']['totalPrice']['currency'] . '",'
                        . '"value":' . $payload['order']['totalPrice']['value'] . ''
                    . '},'
                    . '"totalShipping":{'
                        . '"currency":"' . $payload['order']['totalShipping']['currency'] . '",'
                        . '"value":' . $payload['order']['totalShipping']['value'] . ''
                    . '},'
                    . '"totalTax":{'
                        . '"currency":"' . $payload['order']['totalTax']['currency'] . '",'
                        . '"value":' . $payload['order']['totalTax']['value'] . ''
                    . '},'
                    . '"discountCode":"' . $payload['order']['discountCode'] . '",'                
                    . '"items":[' . $items . ']'
                . '}'
                . '}';
        $response = $this->makeRequest('POST', $token, $this->api_base_url, "cart", $params, false);
        return $response;
    }
    
    public function expireBreadCart($cartId) {
        $token = get_option('bread_auth_token');
        $params = '{"cartdId":" ' . $cartId . '"}';
        $this->makeRequest('POST', $token, $this->api_base_url, "cart/$cartId/expire", $params, false);
    }

    public function sendBreadCartLink($cartId, $payload) {
        $token = get_option('bread_auth_token');
        return $this->makeRequest('POST', $token, $this->api_base_url, "cart/$cartId/notify", $payload, false);
    }
    
}