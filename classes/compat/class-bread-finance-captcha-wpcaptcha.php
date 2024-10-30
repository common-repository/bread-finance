<?php

namespace Bread_Finance\Classes\Compat;

class Bread_Finance_Captcha_WPCaptcha extends Bread_Finance_Captcha {
    protected $wpcaptcha;

    public $post_key = 'g-recaptcha-response';

    public function __construct() {
        $this->wpcaptcha = "WPCaptcha";
    }

    public function run_compat() {
        if (array_key_exists("bread-{$this->post_key}", $_POST) && !empty($_POST["bread-{$this->post_key}"])) {
            remove_action( 'woocommerce_review_order_before_submit', array('WPCaptcha_Functions', 'captcha_fields'));
            remove_action( 'woocommerce_review_order_before_submit', array('WPCaptcha_Functions', 'login_print_scripts'));
            remove_action( 'woocommerce_checkout_process', array('WPCaptcha_Functions', 'check_woo_checkout_form'));
        }
    }

    public function get_post_key() {
        return $this->post_key;
    }
}