<?php
/**
 * Plugin bootstrap: wires hooks.
 *
 * @package AICA
 */

namespace AICA;

use AICA\Agent\Audit_Agent;
use AICA\Mcp\Abilities;
use AICA\Mcp\Mcp_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin class.
 */
class Plugin {

	/**
	 * Singleton.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Capability required to run audits and see findings.
	 *
	 * @return string
	 */
	public static function capability() {
		/**
		 * Filter the capability that grants access to the auditor.
		 *
		 * @param string $cap Capability. Default manage_options.
		 */
		return (string) apply_filters( 'aica_capability', 'manage_options' );
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action( 'init', array( $this, 'init' ) );
		add_action( 'rest_api_init', array( Rest_Controller::class, 'register_routes' ) );
		add_action( 'rest_api_init', array( Mcp_Server::class, 'register_routes' ) );
		add_action( Audit_Agent::CRON_HOOK, array( Audit_Agent::class, 'cron_continue' ) );
		add_action( 'wp_head', array( $this, 'output_meta_description' ), 1 );
		Abilities::init();

		if ( is_admin() ) {
			add_action( 'admin_init', array( Settings::class, 'register' ) );
			add_action( 'admin_init', array( Installer::class, 'maybe_upgrade' ) );
			Admin::init();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'aica', Cli::class );
		}
	}

	/**
	 * Init hook.
	 */
	public function init() {
		register_post_meta(
			'',
			'_aica_meta_description',
			array(
				'type'          => 'string',
				'single'        => true,
				'show_in_rest'  => false,
				'auth_callback' => static function ( $allowed, $key, $post_id ) {
					return current_user_can( 'edit_post', $post_id );
				},
			)
		);
	}

	/**
	 * Print approved meta descriptions when no SEO plugin handles them.
	 */
	public function output_meta_description() {
		if ( ! Settings::get( 'output_meta_description' ) || ! is_singular() ) {
			return;
		}
		if ( defined( 'WPSEO_VERSION' ) || class_exists( 'RankMath' ) || defined( 'AIOSEO_VERSION' ) || defined( 'SEOPRESS_VERSION' ) ) {
			return;
		}
		$desc = (string) get_post_meta( get_queried_object_id(), '_aica_meta_description', true );
		if ( '' !== $desc ) {
			echo '<meta name="description" content="' . esc_attr( $desc ) . '" />' . "\n";
		}
	}
}
