<?php

/**
 * Bread Finance logger
 * 
 * @package Bread_finance/Classes
 */

 namespace Bread_Finance\Classes;


if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

class Bread_Finance_Logger 
{
    /**
     * The logger instance.
     *
     * @var WC_Logger|null
     */
    private static $logger;

    /**
     * The filename for the logger.
     */
    const WC_LOG_FILENAME = 'bread-finance-log';

    /**
     * Log a message
     *
     * @param string $message The message to log
     * @param array $context Any additional context to include in the log message
     * 
     * @since 3.3.2
     * @version 3.3.2
     */
    public static function log( $message, $context = array() ) {
        if ( ! class_exists( 'WC_Logger' ) ) {
            return;
        }
        $bread_config = \Bread_Finance\Classes\Config\Bread_Config::instance();
        $tenant = strtoupper($bread_config->get('gateway_id'));

        if (apply_filters('wc_bread_finance_logging', true, $message)) {
            if (self::$logger === null) {
                self::$logger = wc_get_logger();
            }

            $logEntry = "\n" . '==== ' . $bread_config->get('tenant_name') . ' Version: ' . constant('WC_'.$tenant.'_VERSION') . ' ====' . "\n";
            $logEntry .= '==== Start Log ====' . "\n" . $message . "\n" . '==== End Log ====' . "\n\n";

            self::$logger->debug( $logEntry, [ 'page_type' => self::WC_LOG_FILENAME ], $context );
        }
    }
}
