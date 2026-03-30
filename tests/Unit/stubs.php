<?php
/**
 * Global WordPress Stubs for Unit Testing.
 */

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		public function get_param( $key ) { return null; }
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		public $data;
		public function __construct( $data = null, $status = 200 ) { $this->data = $data; }
		public function get_data() { return $this->data; }
		public function header( $key, $value ) {}
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) { return $text; }
}

if ( ! function_exists( '_x' ) ) {
	function _x( $text, $context, $domain = 'default' ) { return $text; }
}

if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( $text, $domain = 'default' ) { echo $text; }
}

if ( ! function_exists( 'esc_attr_e' ) ) {
	function esc_attr_e( $text, $domain = 'default' ) { echo $text; }
}

if ( ! function_exists( 'wp_cache_get' ) ) {
	function wp_cache_get( $key, $group = '', $force = false, &$found = null ) { return false; }
}

if ( ! function_exists( 'wp_cache_set' ) ) {
	function wp_cache_set( $key, $data, $group = '', $expire = 0 ) { return true; }
}

if ( ! function_exists( 'wp_cache_delete' ) ) {
	function wp_cache_delete( $key, $group = '' ) { return true; }
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $transient ) { return false; }
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $transient, $value, $expiration = 0 ) { return true; }
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $transient ) { return true; }
}

if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( $hook, $args = array() ) { return false; }
}

if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	function wp_schedule_single_event( $timestamp, $hook, $args = array(), $wp_error = false ) { return true; }
}
