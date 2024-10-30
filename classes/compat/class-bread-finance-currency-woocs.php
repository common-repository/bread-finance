<?php

namespace Bread_Finance\Classes\Compat;

class Bread_Finance_Currency_WOOCS extends Bread_Finance_Currency_Abstract {
    // Implement methods to interact with "Fox/Woocommerce Currency Switcher" plugin
    // https://wordpress.org/plugins/woocommerce-currency-switcher/
    protected $woocs;

    public function __construct() {
      global $WOOCS;
      $this->woocs = $WOOCS;
      parent::__construct($this->woocs);
  }


    public function get_active_currency_from_plugin() {
    return $this->woocs->current_currency;
  }
}