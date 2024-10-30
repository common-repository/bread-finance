<?php 

/**
 * Bread finance classic API interface
 * 
 */

namespace Bread_Finance\Classes;

class Bread_Finance_Classic_Api {
    
    /**
     * Instance of the Bread plugin
     * 
     * @var $plugin
     */
    protected $plugin;
    
    /**
     * API auth credentials
     * 
     * @var $basic_auth_credentials
     */
    public $basic_auth_credentials;
    
    /**
     * Reference singleton instance of this class
     * 
     * @var $instance
     */
    private static $instance;
    
    /**
     * Main gateway
     */
    public $bread_finance_gateway = false;
    
    /**
     * Sentry DSN
     */
    const URL_LAMBDA_SENTRY_DSN = 'https://oapavh9uvh.execute-api.us-east-1.amazonaws.com/prod/sentrydsn?platform=woocommerce';
    
    /**
     * Bread API URL
     */
    public $bread_api_url;
    
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
        $this->basic_auth_credentials = 'Basic ' . base64_encode($this->get_bread_gateway()->get_classic_api_key() . ':' . $this->get_bread_gateway()->get_classic_api_secret_key());
        $this->bread_api_url = $this->get_bread_gateway()->get_api_url();
        
    }
    
    /**
     * Instance of
     * 
     * @return object
     */
    public function get_bread_gateway() {
        if($this->bread_finance_gateway) {
            return $this->bread_finance_gateway;
        }
        
        $this->bread_finance_gateway = new Bread_Finance_Gateway();
        return $this->bread_finance_gateway;
    }
    
    
    /**
     * Get a bread transaction
     *
     * @param    string $tx_id The transaction id
     *
     * @return    array
     */
    public function getTransaction($tx_id) {
        return $this->makeRequest('GET', '/transactions/' . $tx_id);
    }
    
    /**
     * Authorize a bread transaction
     *
     * @param    string $tx_id The transaction id
     *
     * @return    array
     */
    public function authorizeTransaction($tx_id, $order_id = null) {
        $params = ( $order_id === null ) ? array('type' => 'authorize') : array('type' => 'authorize', 'merchantOrderId' => strval($order_id));

        return $this->makeRequest('POST', '/transactions/actions/' . $tx_id, $params);
    }
    
    /**
     * Cancel a bread transaction
     *
     * @param    string $tx_id The transaction id
     *
     * @return    array
     */
    public function cancelTransaction($tx_id) {
        return $this->makeRequest('POST', '/transactions/actions/' . $tx_id, array('type' => 'cancel'));
    }
    
    /**
     * Update a bread transaction
     *
     * @param    string $tx_id The transaction id
     * @param    array $payload The updated transaction data
     *
     * @return    array
     */
    public function updateTransaction($tx_id, $payload) {
        return $this->makeRequest('PUT', '/transactions/' . $tx_id, $payload);
    }

    /**
     * Track shipment data for a transaction
     *
     * @param    string 	$tx_id 		The transaction id
     * @param    array 		$payload 	The updated transaction data
     *
     * @return	array
     */
    public function updateShipment($tx_id, $payload) {
        return $this->makeRequest('POST', '/transactions/' . $tx_id . '/shipment', $payload);
    }

    /**
     * Settle a bread transaction
     *
     * @param    string $tx_id The transaction id
     *
     * @return    array
     */
    public function settleTransaction($tx_id) {
        return $this->makeRequest('POST', '/transactions/actions/' . $tx_id, array('type' => 'settle'));
    }

    /**
     * Refund a bread transaction
     *
     * @param    string $tx_id The transaction id
     *
     * @param int|null $amount Amount (in cents) to refund
     * @param array|null $line_items
     *
     * @return    array
     */
    public function refundTransaction($tx_id, $amount = null, $line_items = null) {
        $params = array('type' => 'refund');

        if ($amount) {
            $params['amount'] = $amount;
        }

        if ($line_items) {
            $params['lineItems'] = $line_items;
        }

        return $this->makeRequest('POST', '/transactions/actions/' . $tx_id, $params);
    }

    /**
     * Create Bread Cart
     *
     * @param    array
     *
     * @return    array
     */
    public function createBreadCart($payload) {
        return $this->makeRequest('POST', '/carts', $payload);
    }

    /**
     * Expire Bread Cart
     *
     * @param    string
     *
     * @return    void
     */
    public function expireBreadCart($cartId) {
        $this->makeRequest('POST', '/carts/' . $cartId . '/expire');
    }

    /**
     * Send Bread Cart
     *
     * @param    array
     * @param		 string 
     * @return   array
     */
    public function sendBreadCartLink($endpoint, $payload) {
        return $this->makeRequest('POST', $endpoint, $payload);
    }

    /**
     * 
     * @param type $data
     * @return type
     */
    public function getAsLowAs($data) {
        return $this->makeRequest('POST', '/aslowas', $data);
    }

    /**
     * 
     * @return type
     */
    public function getSentryDSN() {
        $result = call_user_func('wp_remote_get', self::URL_LAMBDA_SENTRY_DSN, array(
            'method' => 'GET',
            'headers' => array('Content-Type' => 'application/json', 'Authorization' => $this->basic_auth_credentials),
                ));

        if (!is_wp_error($result)) {
            return json_decode($result['body'], true);
        }

        return $result;
    }

    /**
     * Make a request to the bread api
     *
     * @param    string $method The request method
     * @param    string $endpoint The api endpoint to contact
     * @param    array $payload The data to send to the endpoint
     *
     * @return    array|WP_Error
     */
    protected function makeRequest($method, $endpoint, $payload = array()) {
        $wp_remote = $method == 'GET' ? 'wp_remote_get' : 'wp_remote_post';
        $api_url = $this->bread_api_url . $endpoint;
        $wp_payload = $method == 'GET' ? $payload : json_encode($payload);
        $result = call_user_func($wp_remote, $api_url, array(
            'method' => $method,
            'headers' => array('Content-Type' => 'application/json', 'Authorization' => $this->basic_auth_credentials),
            'body' => $wp_payload,
        ));

        if (!is_wp_error($result)) {
            return json_decode($result['body'], true);
        }

        return $result;
    }

}