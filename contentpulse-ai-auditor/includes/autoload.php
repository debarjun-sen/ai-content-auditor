<?php
/**
 * PSR-4-ish autoloader: AICA\Foo_Bar => includes/class-foo-bar.php,
 * AICA\Sub\Foo_Bar => includes/sub/class-foo-bar.php.
 *
 * @package AICA
 */

defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	static function ( $class_name ) {
		if ( 0 !== strpos( $class_name, 'AICA\\' ) ) {
			return;
		}
		$parts = explode( '\\', substr( $class_name, 5 ) );
		$name  = array_pop( $parts );
		$dir   = '';
		foreach ( $parts as $part ) {
			$dir .= strtolower( str_replace( '_', '-', $part ) ) . '/';
		}
		$file = __DIR__ . '/' . $dir . 'class-' . strtolower( str_replace( '_', '-', $name ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);
