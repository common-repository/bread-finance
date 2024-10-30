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

class Bread_Finance_Api_Factory {

    public static function create($api_version, $sdk_versions) {

        // Check if the requested version is available
        if (!array_key_exists($api_version, $sdk_versions)) {
            throw new \InvalidArgumentException("Invalid API version: $api_version");
        }

        switch ($api_version) {
            case 'bread_2':
                return Bread_Finance_V2_Api::instance();
            case 'classic':
            default:
                return Bread_Finance_Classic_Api::instance();
        }
    }
}