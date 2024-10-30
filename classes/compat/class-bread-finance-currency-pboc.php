<?php

namespace Bread_Finance\Classes\Compat;

class Bread_Finance_Currency_PBOC extends Bread_Finance_Currency_Abstract {
    // Implement methods to interact with "WooCommerce Price Based on Country" plugin
    protected $pboc;

    public function __construct() {

        $this->pboc = \WC_Product_Price_Based_Country::instance();
        parent::__construct($this->pboc);
    }

    /**
     * Get PBOC selected currency.
     *
     * @return string
     */
    public static function get_active_currency_from_plugin() {
        if(!class_exists('WCPBC_Pricing_Zones') ) {
            return get_woocommerce_currency();
        }
        // This is for testing, changing countries manually.
        if ( isset( $_REQUEST['wcpbc-manual-country'] ) ) {
            $manual_country = wc_clean( wp_unslash( $_REQUEST['wcpbc-manual-country'] ) );
            $selected_zone  = \WCPBC_Pricing_Zones::get_zone_by_country( $manual_country );
        } else {
            $selected_zone = wcpbc_get_zone_by_country();
        }
        return ( $selected_zone ) ? $selected_zone->get_currency() : get_woocommerce_currency();
    }
}