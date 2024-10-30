<?php

namespace Bread_Finance\Classes\Config;

use Exception;


class Bread_Config
{
    const PROTECTED = ['WP_ENV']; // List of protected env variables

    /** The path to the configuration directory */
    private $path;

    private $file = 'config.yml';

    private $env = 'env.php';

    private $default = 'default.php';

    /** Placeholder for environment variables */
    private $envs = array();

    public $configData = array();

    private static $instance;

    private $allowed_filters = [
        '__return_false',
        '__return_true',
        '__return_empty_array',
        '__return_zero',
        '__return_null',
        '__return_empty_string'
    ];

    public static $gateway_id;


    public function __construct()
    {

        $this->path = plugin_dir_path(__FILE__);

        /**
         * Auto load default environment
         */
        if (file_exists($this->path . $this->default)) {
            require_once $this->path . $this->default;
        }

        /**
         * Auto load environment file if it exits
         * Define WP_ENV in the root wp-config.php before the wp-settings.php include
         */
        if (defined('WP_ENV') && file_exists($this->path . WP_ENV . '.php')) {
            require_once $this->path . WP_ENV . '.php';
        }

        if (file_exists($this->path . $this->file)) {
            $file = \Bread_Finance\Classes\Spyc::YAMLLoad($this->path . $this->file);

            /**
             * Set configuration to environment variables
             */

            // Extract env specific variables
            if (defined('WP_ENV') && isset($file[WP_ENV])) {
                $this->envs = $file[WP_ENV];
            }

            // Merge env variables over root variables
            $this->set(array_merge($file, $this->envs));
        } elseif (defined('WP_DEBUG') && WP_DEBUG) {
            throw new Exception(
                "The configuration file could not be found at $this->path"
            );
        } else {
            error_log(
                "The configuration file could not be found at $this->path"
            );
        }

        /**
         * A hook for the initiation of the plugin
         */
        do_action('bread_wp_config_loaded', $this);
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

    /**
     * Set configuration to environment variables
     *
     */
    private function set($file)
    {
        foreach ($file as $name => $value) {
            // Do not update protected env variables
            if (!in_array($name, Bread_Config::PROTECTED)) {
                $this->configData[$name] = $value;
                //Update WordPress Admin option if it is an option
                if (substr($name, 0, 10) === 'WP_OPTION_') {
                    update_option(
                        strtolower(str_replace('WP_OPTION_', '', $name)),
                        $value
                    );
                }

                //Create a filter if the option is a filter
                if (substr($name, 0, 10) === 'WP_FILTER_' && in_array($value, $this->allowed_filters)) {
                    add_filter(strtolower(str_replace('WP_FILTER_', '', $name)), $value);
                }
            }
            
        }
    }

    public function get($key, $default = null) {
        return isset($this->configData[$key]) ? $this->configData[$key] : $default;
    }
    public function get_constant($key, $default = null) {
        return $this->get($key, $default) ? strtoupper($this->get($key, $default)) : $default;
    }
}
