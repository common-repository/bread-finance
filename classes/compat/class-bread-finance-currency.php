<?php

namespace Bread_Finance\Classes\Compat;

class Bread_Finance_Currency {
    private $compatible_plugin_instance;

    /**
     * Reference singleton instance of this class
     * 
     * @var $instance
     */
    private static $instance;

    private $compatible_plugins = array(
        'WC_Product_Price_Based_Country' => \Bread_Finance\Classes\Compat\Bread_Finance_Currency_PBOC::class,
        'WOOCS' => \Bread_Finance\Classes\Compat\Bread_Finance_Currency_WOOCS::class
    );
    

    public function __construct() {
        // Search for compatible third-party currency plugin classes
        $this->find_compatible_instance();
    }

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

    private function find_compatible_instance() {
        // Loop through the list of compatible plugins
        foreach ($this->compatible_plugins as $plugin_class => $bread_plugin_class) {
            if (class_exists($plugin_class)) {
                $currencyPluginInstance = new $bread_plugin_class();
                if ($currencyPluginInstance instanceof \Bread_Finance\Classes\Compat\Bread_Finance_Currency_PBOC) {
                    $this->compatible_plugin_instance = $currencyPluginInstance;
                    break; // Break out of the loop since we found a compatible instance
                }
                if ($currencyPluginInstance instanceof \Bread_Finance\Classes\Compat\Bread_Finance_Currency_WOOCS) {
                    $this->compatible_plugin_instance = $currencyPluginInstance;
                    break;
                }
            }
        }
    }

    public function get_active_currency($bread_config_currency) {
        // Check if a compatible instance is available
        if ($this->compatible_plugin_instance) {
            // Use the compatible instance to get the active currency
            return $this->compatible_plugin_instance->get_active_currency_from_plugin();
        } else {
            // Handle the case where no compatible instance was found
            // We just return bread currency set in config yaml
            return $bread_config_currency;
        }
    }
}
