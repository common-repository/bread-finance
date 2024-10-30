<?php

namespace Bread_Finance\Classes\Compat;

class Bread_Finance_Captcha {
    private $compatible_plugin_instance;

    private $bread_config;

    /**
     * Reference singleton instance of this class
     * 
     * @var $instance
     */
    private static $instance;

    private $compatible_plugins = array(
        'WPCaptcha' => \Bread_Finance\Classes\Compat\Bread_Finance_Captcha_WPCaptcha::class
    );

    public function __construct() {
        if (!$this->bread_config) {
            $this->bread_config = \Bread_Finance\Classes\Config\Bread_Config::instance();
        }
        // Search for compatible third-party plugin classes
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
                if ($this->is_excluded_in_config($plugin_class)) {
                    break; // Break early if plugin is excluded in config.yml
                }
                $pluginInstance = new $bread_plugin_class();
                if ($pluginInstance instanceof \Bread_Finance\Classes\Compat\Bread_Finance_Captcha_WPCaptcha) {
                    $this->compatible_plugin_instance = $pluginInstance;
                    break; // Break out of the loop since we found a compatible instance
                }
            }
        }
    }

    public function is_excluded_in_config($name) {
        $excluded_plugins = $this->bread_config->get('excluded_plugins', []);
        $plugin_name = $name ?: get_class($this->compatible_plugin_instance);
        return in_array($plugin_name, $excluded_plugins);
    }
    

    public function get_post_key() {
        if ($this->compatible_plugin_instance) {
            return $this->compatible_plugin_instance->get_post_key();
        }
    }

    public function run_compat() {
        if ($this->compatible_plugin_instance) {
            return $this->compatible_plugin_instance->run_compat();
        }
    }
}
