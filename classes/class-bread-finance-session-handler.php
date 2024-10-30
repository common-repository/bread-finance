<?php

/**
 * Non persisting session handler class
 */

namespace Bread_Finance\Classes;

use PasswordHash;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Bread_Finance_Session_Handler extends \WC_Session {

    public function set_customer_session_cookie($set) {
        return;
    }

    public function has_session() {
        return false;
    }

    public function set_session_expiration() {
        return;
    }

    public function generate_customer_id() {
        require_once ABSPATH . 'wp-includes/class-phpass.php';
        $hasher = new PasswordHash(8, false);

        return md5($hasher->get_random_bytes(32));
    }

    public function get_session_cookie() {
        return false;
    }

    public function get_session_data() {
        return array();
    }

    public function save_data() {
        return;
    }

    public function destroy_session() {
        return;
    }

    public function nonce_user_logged_out($uid) {
        return $uid;
    }

    public function cleanup_sessions() {
        return;
    }

    public function get_session($customer_id, $default = false) {
        return $default;
    }

    public function delete_session($customer_id) {
        return;
    }

    public function update_session_timestamp($customer_id, $timestamp) {
        return;
    }

}
