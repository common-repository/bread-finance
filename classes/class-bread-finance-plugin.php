<?php 

/**
 * 
 * Plugin manager
 */

namespace Bread_Finance\Classes;

class Bread_Finance_Plugin {
    
    const TEXT_DOMAIN = 'woocommerce-gateway-bread';
    
    /**
     * @var $bread_finance_gateway
     */
    public $bread_finance_gateway = false;
    
    /**
     * Reference singleton instance of this class
     * 
     * @var $instance
     */
    private static $instance;
    
    /**
     * @var array   Supported product-types
     */
    public $supported_products = array('simple', 'grouped', 'variable', 'composite', 'bundle');

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
        $this->set_bread_gateway();
    }
    
    public function set_bread_gateway() {
        if(!$this->bread_finance_gateway) {
            $this->bread_finance_gateway = new Bread_Finance_Gateway;
        }
     
    }
    
    public function get_bread_gateway() {
        if(!$this->bread_finance_gateway) {
            $this->bread_finance_gateway = new Bread_Finance_Gateway;
        }
        return $this->bread_finance_gateway;
    }
    
    public function get_text_domain() {
        return self::TEXT_DOMAIN;
    }
    
    /**
     * @param $product \WC_Product
     *
     * @return bool
     */
    public function supports_product($product) {
        return in_array($product->get_type(), $this->supported_products);
    }

}
