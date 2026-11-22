<?php
/**
 * Unit-test bootstrap: no WordPress. Pure classes (analyzers, guard, validator,
 * scoring, request building) run against a handful of tiny stubs.
 *
 * @package AICA\Tests
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'AICA_VERSION', 'test' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MINUTE_IN_SECONDS', 60 );

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

// ---- Minimal WordPress stubs -------------------------------------------------
if ( ! function_exists( '__' ) ) {
	function __( $s ) { return $s; } // phpcs:ignore
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value ) { return $value; } // phpcs:ignore
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $flags = 0 ) { return json_encode( $data, $flags ); } // phpcs:ignore
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); } // phpcs:ignore
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error { // phpcs:ignore
		private $code; private $message; private $data; // phpcs:ignore
		public function __construct( $code = '', $message = '', $data = '' ) { $this->code = $code; $this->message = $message; $this->data = $data; } // phpcs:ignore
		public function get_error_code() { return $this->code; } // phpcs:ignore
		public function get_error_message() { return $this->message; } // phpcs:ignore
		public function get_error_data() { return $this->data; } // phpcs:ignore
	}
}

require_once dirname( __DIR__, 2 ) . '/includes/autoload.php';
