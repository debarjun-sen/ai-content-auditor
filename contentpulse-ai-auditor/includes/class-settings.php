<?php
/**
 * Settings (Settings API) and typed accessors.
 *
 * @package AICA
 */

namespace AICA;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin settings.
 */
class Settings {

	const OPTION = 'aica_settings';
	const GROUP  = 'aica_settings_group';
	const PAGE   = 'aica-settings';

	/**
	 * Models offered in the UI. Pricing is USD per million tokens (input, output).
	 *
	 * @return array<string,array{label:string,input:float,output:float,fallback:bool}>
	 */
	public static function models() {
		return array(
			'claude-opus-5-5'   => array(
				'label'    => 'Claude Opus 5.5 (best quality)',
				'input'    => 4.0,
				'output'   => 20.0,
				'fallback' => true,
			),
			'claude-sonnet-5-5' => array(
				'label'    => 'Claude Sonnet 5.5 (balanced)',
				'input'    => 2.0,
				'output'   => 10.0,
				'fallback' => true,
			),
			'claude-haiku-5-5'  => array(
				'label'    => 'Claude Haiku 5.5 (fastest, lowest cost)',
				'input'    => 0.10,
				'output'   => 0.50,
				'fallback' => false,
			),
		);
	}

	/**
	 * Default values.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'model'                   => 'claude-opus-5-5',
			'effort'                  => 'medium',
			'refusal_fallback'        => 1,
			'post_types'              => array( 'post', 'page' ),
			'include_drafts'          => 0,
			'max_posts'               => 25,
			'max_iterations'          => 30,
			'max_tokens_budget'       => 600000,
			'content_chars'           => 6000,
			'stale_days'              => 365,
			'mcp_enabled'             => 0,
			'mcp_allow_fixes'         => 0,
			'output_meta_description' => 1,
			'delete_on_uninstall'     => 0,
		);
	}

	/**
	 * All settings merged with defaults.
	 *
	 * @return array
	 */
	public static function all() {
		$stored = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Single setting.
	 *
	 * @param string $key      Key.
	 * @param mixed  $fallback Fallback.
	 * @return mixed
	 */
	public static function get( $key, $fallback = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	/**
	 * Register setting, sections and fields.
	 */
	public static function register() {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
				'show_in_rest'      => false,
			)
		);

		$sections = array(
			'aica_claude' => array( __( 'Claude API', 'contentpulse-ai-auditor' ), __( 'The agent calls the Claude Messages API with tool use. Your key is stored encrypted and never sent to the browser.', 'contentpulse-ai-auditor' ) ),
			'aica_scope'  => array( __( 'What to audit', 'contentpulse-ai-auditor' ), '' ),
			'aica_safety' => array( __( 'Safety & budgets', 'contentpulse-ai-auditor' ), __( 'Hard limits enforced on every scan, whichever is hit first.', 'contentpulse-ai-auditor' ) ),
			'aica_mcp'    => array( __( 'MCP server', 'contentpulse-ai-auditor' ), __( 'Let external agents (Claude Desktop, Claude Code) use the audit tools over MCP.', 'contentpulse-ai-auditor' ) ),
		);
		foreach ( $sections as $id => $s ) {
			$desc = $s[1];
			add_settings_section(
				$id,
				$s[0],
				static function () use ( $desc ) {
					if ( $desc ) {
						echo '<p class="aica-section-desc">' . esc_html( $desc ) . '</p>';
					}
				},
				self::PAGE
			);
		}

		$model_opts = array();
		foreach ( self::models() as $id => $m ) {
			$model_opts[ $id ] = $m['label'];
		}

		$fields = array(
			array( 'api_key', __( 'API key', 'contentpulse-ai-auditor' ), 'aica_claude', 'api_key' ),
			array( 'model', __( 'Model', 'contentpulse-ai-auditor' ), 'aica_claude', 'select', $model_opts ),
			array(
				'effort',
				__( 'Effort', 'contentpulse-ai-auditor' ),
				'aica_claude',
				'select',
				array(
					'low'    => __( 'Low — fastest', 'contentpulse-ai-auditor' ),
					'medium' => __( 'Medium — recommended', 'contentpulse-ai-auditor' ),
					'high'   => __( 'High — most thorough', 'contentpulse-ai-auditor' ),
				),
			),
			array( 'refusal_fallback', __( 'Refusal fallback', 'contentpulse-ai-auditor' ), 'aica_claude', 'checkbox', __( 'If a request is declined by safety classifiers, let the API retry it on a fallback model.', 'contentpulse-ai-auditor' ) ),
			array( 'post_types', __( 'Content types', 'contentpulse-ai-auditor' ), 'aica_scope', 'post_types' ),
			array( 'include_drafts', __( 'Include drafts', 'contentpulse-ai-auditor' ), 'aica_scope', 'checkbox', __( 'Also audit drafts and pending posts (never private posts).', 'contentpulse-ai-auditor' ) ),
			array( 'stale_days', __( 'Stale after (days)', 'contentpulse-ai-auditor' ), 'aica_scope', 'number', array( 30, 3650 ) ),
			array( 'max_posts', __( 'Max posts per scan', 'contentpulse-ai-auditor' ), 'aica_safety', 'number', array( 1, 200 ) ),
			array( 'max_iterations', __( 'Max agent turns', 'contentpulse-ai-auditor' ), 'aica_safety', 'number', array( 3, 100 ) ),
			array( 'max_tokens_budget', __( 'Token budget per scan', 'contentpulse-ai-auditor' ), 'aica_safety', 'number', array( 20000, 5000000 ) ),
			array( 'content_chars', __( 'Characters of content sent per post', 'contentpulse-ai-auditor' ), 'aica_safety', 'number', array( 500, 30000 ) ),
			array( 'output_meta_description', __( 'Output meta descriptions', 'contentpulse-ai-auditor' ), 'aica_safety', 'checkbox', __( 'Print approved meta descriptions in the page head when no SEO plugin is active.', 'contentpulse-ai-auditor' ) ),
			array( 'delete_on_uninstall', __( 'Delete data on uninstall', 'contentpulse-ai-auditor' ), 'aica_safety', 'checkbox', __( 'Remove scans, findings, settings and the stored key when the plugin is deleted.', 'contentpulse-ai-auditor' ) ),
			array( 'mcp_enabled', __( 'Enable MCP endpoint', 'contentpulse-ai-auditor' ), 'aica_mcp', 'checkbox', __( 'Requires an authenticated administrator (Application Password).', 'contentpulse-ai-auditor' ) ),
			array( 'mcp_allow_fixes', __( 'Allow MCP clients to apply fixes', 'contentpulse-ai-auditor' ), 'aica_mcp', 'checkbox', __( 'Off by default. When off, external agents can audit and record findings but cannot change content.', 'contentpulse-ai-auditor' ) ),
		);

		foreach ( $fields as $f ) {
			add_settings_field(
				'aica_' . $f[0],
				$f[1],
				array( __CLASS__, 'render_field' ),
				self::PAGE,
				$f[2],
				array(
					'key'       => $f[0],
					'type'      => $f[3],
					'extra'     => isset( $f[4] ) ? $f[4] : null,
					'label_for' => 'aica_' . $f[0],
				)
			);
		}
	}

	/**
	 * Render a field.
	 *
	 * @param array $args Field args.
	 */
	public static function render_field( $args ) {
		$key   = $args['key'];
		$id    = 'aica_' . $key;
		$name  = self::OPTION . '[' . $key . ']';
		$value = self::get( $key );

		switch ( $args['type'] ) {
			case 'api_key':
				$source = Secrets::source();
				if ( 'constant' === $source || 'env' === $source ) {
					printf(
						'<p class="aica-key-status aica-key-status--ok">%s <code>%s</code></p>',
						esc_html( 'constant' === $source ? __( 'Defined in wp-config.php:', 'contentpulse-ai-auditor' ) : __( 'Read from the ANTHROPIC_API_KEY environment variable:', 'contentpulse-ai-auditor' ) ),
						esc_html( Secrets::masked() )
					);
					return;
				}
				printf(
					'<input type="password" id="%1$s" name="%2$s" value="" class="regular-text" autocomplete="new-password" spellcheck="false" placeholder="%3$s" aria-describedby="%1$s_help" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( 'option' === $source ? Secrets::masked() : 'sk-ant-…' )
				);
				echo ' <button type="button" class="button" id="aica-test-connection">' . esc_html__( 'Test connection', 'contentpulse-ai-auditor' ) . '</button>';
				echo ' <span id="aica-test-result" role="status" aria-live="polite"></span>';
				echo '<p class="description" id="' . esc_attr( $id ) . '_help">';
				if ( 'option' === $source ) {
					echo esc_html__( 'A key is saved. Leave blank to keep it.', 'contentpulse-ai-auditor' ) . ' ';
					printf(
						'<label><input type="checkbox" name="%s" value="1" /> %s</label>',
						esc_attr( self::OPTION . '[api_key_clear]' ),
						esc_html__( 'Remove saved key', 'contentpulse-ai-auditor' )
					);
				} else {
					echo esc_html__( 'Tip: define AICA_ANTHROPIC_API_KEY in wp-config.php to keep the key out of the database entirely.', 'contentpulse-ai-auditor' );
				}
				echo '</p>';
				return;

			case 'select':
				printf( '<select id="%s" name="%s">', esc_attr( $id ), esc_attr( $name ) );
				foreach ( (array) $args['extra'] as $opt => $label ) {
					printf( '<option value="%s" %s>%s</option>', esc_attr( $opt ), selected( $value, $opt, false ), esc_html( $label ) );
				}
				echo '</select>';
				return;

			case 'checkbox':
				printf(
					'<label for="%1$s"><input type="checkbox" id="%1$s" name="%2$s" value="1" %3$s /> %4$s</label>',
					esc_attr( $id ),
					esc_attr( $name ),
					checked( 1, (int) $value, false ),
					esc_html( (string) $args['extra'] )
				);
				return;

			case 'number':
				printf(
					'<input type="number" id="%s" name="%s" value="%d" min="%d" max="%d" class="small-text" />',
					esc_attr( $id ),
					esc_attr( $name ),
					(int) $value,
					(int) $args['extra'][0],
					(int) $args['extra'][1]
				);
				return;

			case 'post_types':
				echo '<fieldset id="' . esc_attr( $id ) . '"><legend class="screen-reader-text">' . esc_html__( 'Content types', 'contentpulse-ai-auditor' ) . '</legend>';
				foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $pt ) {
					if ( 'attachment' === $pt->name ) {
						continue;
					}
					printf(
						'<label class="aica-inline-check"><input type="checkbox" name="%s[]" value="%s" %s /> %s</label>',
						esc_attr( $name ),
						esc_attr( $pt->name ),
						checked( in_array( $pt->name, (array) $value, true ), true, false ),
						esc_html( $pt->labels->name )
					);
				}
				echo '</fieldset>';
				return;
		}
	}

	/**
	 * Sanitize submitted settings.
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$d     = self::defaults();
		$out   = array();

		// API key is stored separately (encrypted) and never kept in this option.
		if ( ! empty( $input['api_key_clear'] ) ) {
			Secrets::set_key( '' );
		} elseif ( ! empty( $input['api_key'] ) ) {
			Secrets::set_key( sanitize_text_field( $input['api_key'] ) );
		}

		$out['model']  = isset( $input['model'] ) && array_key_exists( $input['model'], self::models() ) ? $input['model'] : $d['model'];
		$out['effort'] = isset( $input['effort'] ) && in_array( $input['effort'], array( 'low', 'medium', 'high' ), true ) ? $input['effort'] : $d['effort'];

		foreach ( array( 'refusal_fallback', 'include_drafts', 'mcp_enabled', 'mcp_allow_fixes', 'output_meta_description', 'delete_on_uninstall' ) as $bool ) {
			$out[ $bool ] = empty( $input[ $bool ] ) ? 0 : 1;
		}

		$ranges = array(
			'max_posts'         => array( 1, 200 ),
			'max_iterations'    => array( 3, 100 ),
			'max_tokens_budget' => array( 20000, 5000000 ),
			'content_chars'     => array( 500, 30000 ),
			'stale_days'        => array( 30, 3650 ),
		);
		foreach ( $ranges as $k => $r ) {
			$v         = isset( $input[ $k ] ) ? absint( $input[ $k ] ) : $d[ $k ];
			$out[ $k ] = max( $r[0], min( $r[1], $v ) );
		}

		$public            = get_post_types( array( 'public' => true ) );
		$types             = isset( $input['post_types'] ) ? array_map( 'sanitize_key', (array) $input['post_types'] ) : array();
		$types             = array_values( array_intersect( $types, $public ) );
		$out['post_types'] = $types ? $types : $d['post_types'];

		return $out;
	}
}
