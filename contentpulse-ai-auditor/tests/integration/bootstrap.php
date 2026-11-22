<?php
/**
 * Integration bootstrap (WordPress test suite). Run inside wp-env:
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/contentpulse-ai-auditor composer test:integration
 *
 * @package AICA\Tests
 */

$aica_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $aica_tests_dir ) {
	$aica_tests_dir = getenv( 'WP_PHPUNIT__DIR' );
}
if ( ! $aica_tests_dir ) {
	$aica_tests_dir = '/tmp/wordpress-tests-lib';
}
if ( ! file_exists( $aica_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WordPress test library not found in {$aica_tests_dir}. Use wp-env or set WP_TESTS_DIR.\n" ); // phpcs:ignore
	exit( 1 );
}

require_once dirname( __DIR__, 2 ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';
require_once $aica_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__, 2 ) . '/contentpulse-ai-auditor.php';
	}
);

require $aica_tests_dir . '/includes/bootstrap.php';

// Real tables, created once before tests wrap each case in a transaction.
AICA\Installer::install_schema();
require_once __DIR__ . '/class-aica-testcase.php';
