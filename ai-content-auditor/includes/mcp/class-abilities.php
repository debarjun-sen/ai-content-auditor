<?php
/**
 * WordPress Abilities API registration (WP 6.9+). The official MCP Adapter
 * exposes abilities with meta.mcp.public = true as MCP tools.
 *
 * Abilities are thin adapters over Tool_Registry — same schemas, same code path.
 *
 * @package AICA
 */

namespace AICA\Mcp;

use AICA\Plugin;
use AICA\Tools\Tool_Context;
use AICA\Tools\Tool_Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Abilities registration.
 */
class Abilities {

	const CATEGORY = 'ai-content-auditor';

	/**
	 * Hook in when the Abilities API exists.
	 */
	public static function init() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ) );
	}

	/**
	 * Category.
	 */
	public static function register_category() {
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'AI Content Auditor', 'ai-content-auditor' ),
				'description' => __( 'Audit site content for SEO, accessibility and stale content.', 'ai-content-auditor' ),
			)
		);
	}

	/**
	 * Ability ID for a tool name.
	 *
	 * @param string $tool Tool name.
	 * @return string
	 */
	public static function ability_id( $tool ) {
		return self::CATEGORY . '/' . str_replace( '_', '-', $tool );
	}

	/**
	 * Register one ability per MCP-surface tool.
	 */
	public static function register_abilities() {
		foreach ( Tool_Registry::for_surface( 'mcp' ) as $name => $def ) {
			$schema = json_decode( wp_json_encode( $def['input_schema'] ), true );
			$args   = array(
				'label'               => $def['label'],
				'description'         => $def['description'],
				'category'            => self::CATEGORY,
				'execute_callback'    => static function ( $input = null ) use ( $name ) {
					$ctx = new Tool_Context( 0, 'ability', 100 );
					return Tool_Registry::execute( $name, is_array( $input ) ? $input : array(), $ctx );
				},
				'permission_callback' => static function () use ( $name ) {
					if ( 'apply_fix' === $name ) {
						return current_user_can( Plugin::capability() ) && current_user_can( 'edit_posts' );
					}
					return current_user_can( Plugin::capability() );
				},
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => 'apply_fix' !== $name,
						'type'   => 'tool',
					),
					'annotations'  => $def['annotations'],
				),
			);
			// Abilities with no input omit the schema so the API passes null.
			if ( ! empty( $schema['properties'] ) ) {
				$args['input_schema'] = $schema;
			}
			wp_register_ability( self::ability_id( $name ), $args );
		}
	}
}
