<?php

namespace Bread_Finance\Classes\Compat;

abstract class Bread_Finance_Currency_Abstract {
    protected $currencyPlugin;

    public function __construct($currencyPlugin) {
        $this->currencyPlugin = $currencyPlugin;
    }

    public static function get_active_currency() {
        return get_woocommerce_currency();
    }
}
