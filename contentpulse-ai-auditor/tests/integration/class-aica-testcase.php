<?php
/**
 * Shared helpers: settings reset, admin user, scripted Claude API responses.
 *
 * @package AICA\Tests
 */

use AICA\Settings;
use AICA\Tools\Tool_Registry;

/**
 * Base test case.
 */
abstract class AICA_TestCase extends WP_UnitTestCase {

	/**
	 * Queued fake API responses.
	 *
	 * @var array[]
	 */
	protected $responses = array();

	/**
	 * Captured outgoing API requests: [ 'headers' => [], 'body' => [] ].
	 *
	 * @var array[]
	 */
	protected $requests = array();

	/**
	 * Admin user.
	 *
	 * @var int
	 */
	protected $admin;

	public function set_up() {
		parent::set_up();
		update_option( Settings::OPTION, Settings::defaults() );
		delete_option( 'aica_active_scan' );
		Tool_Registry::reset();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $this->admin );
		}
		wp_set_current_user( $this->admin );
		add_filter( 'pre_http_request', array( $this, 'fake_http' ), 10, 3 );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'fake_http' ), 10 );
		$this->responses = array();
		$this->requests  = array();
		parent::tear_down();
	}

	/**
	 * Settings override.
	 *
	 * @param array $values Values.
	 */
	protected function settings( array $values ) {
		update_option( Settings::OPTION, array_merge( Settings::all(), $values ) );
	}

	/**
	 * Intercept calls to api.anthropic.com.
	 *
	 * @param mixed  $pre  Pre.
	 * @param array  $args Args.
	 * @param string $url  URL.
	 * @return mixed
	 */
	public function fake_http( $pre, $args, $url ) {
		if ( false === strpos( $url, 'api.anthropic.com' ) ) {
			return $pre;
		}
		$this->requests[] = array(
			'headers' => $args['headers'],
			'body'    => json_decode( $args['body'], true ),
			'raw'     => $args['body'],
		);
		$next = array_shift( $this->responses );
		if ( ! $next ) {
			return new WP_Error( 'http_request_failed', 'No fake response queued.' );
		}
		$code = isset( $next['__status'] ) ? $next['__status'] : 200;
		unset( $next['__status'] );
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( $next ),
			'response' => array(
				'code'    => $code,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * A tool_use turn.
	 *
	 * @param array $calls [ [name, input], ... ].
	 * @param string $text Optional preamble.
	 * @return array
	 */
	protected function tool_turn( array $calls, $text = '' ) {
		$content = array(
			array(
				'type'      => 'thinking',
				'thinking'  => '',
				'signature' => 'sig-' . wp_generate_password( 6, false ),
			),
		);
		if ( $text ) {
			$content[] = array(
				'type' => 'text',
				'text' => $text,
			);
		}
		foreach ( $calls as $i => $c ) {
			$content[] = array(
				'type'  => 'tool_use',
				'id'    => 'toolu_' . wp_generate_password( 8, false ) . $i,
				'name'  => $c[0],
				'input' => isset( $c[1] ) ? $c[1] : new stdClass(),
			);
		}
		return array(
			'id'          => 'msg_' . wp_generate_password( 8, false ),
			'type'        => 'message',
			'role'        => 'assistant',
			'model'       => 'claude-opus-5-5',
			'content'     => $content,
			'stop_reason' => 'tool_use',
			'usage'       => array(
				'input_tokens'                => 1200,
				'output_tokens'               => 300,
				'cache_read_input_tokens'     => 2000,
				'cache_creation_input_tokens' => 0,
			),
		);
	}

	/**
	 * A final text turn.
	 *
	 * @param string $text Summary.
	 * @param string $stop Stop reason.
	 * @return array
	 */
	protected function final_turn( $text, $stop = 'end_turn' ) {
		return array(
			'id'          => 'msg_final',
			'type'        => 'message',
			'role'        => 'assistant',
			'model'       => 'claude-opus-5-5',
			'content'     => array(
				array(
					'type' => 'text',
					'text' => $text,
				),
			),
			'stop_reason' => $stop,
			'usage'       => array(
				'input_tokens'  => 500,
				'output_tokens' => 120,
			),
		);
	}

	/**
	 * Create an auditable post.
	 *
	 * @param array $args Args.
	 * @return int
	 */
	protected function post( array $args = array() ) {
		return self::factory()->post->create(
			array_merge(
				array(
					'post_title'   => 'Hi',
					'post_content' => '<p>Short text with an <img src="https://example.org/a.jpg"> image.</p>',
					'post_status'  => 'publish',
				),
				$args
			)
		);
	}
}
