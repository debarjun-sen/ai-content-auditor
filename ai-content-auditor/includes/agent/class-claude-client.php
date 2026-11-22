<?php
/**
 * Minimal Claude Messages API client over the WordPress HTTP API.
 *
 * Why not the Composer SDK? WordPress plugins ship without a runtime package
 * manager, and vendored HTTP stacks (Guzzle/PSR-18) frequently collide between
 * plugins. wp_remote_post() also honors the site's proxy and SSL settings.
 *
 * @package AICA
 */

namespace AICA\Agent;

use AICA\Secrets;
use AICA\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Claude client.
 */
class Claude_Client {

	const ENDPOINT        = 'https://api.anthropic.com/v1/messages';
	const API_VERSION     = '2026-10-21';
	const FALLBACK_BETA   = 'server-side-fallback-2026-07-01';
	const REQUEST_TIMEOUT = 180;

	/**
	 * API key.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Constructor.
	 *
	 * @param string|null $api_key Key (defaults to stored key).
	 */
	public function __construct( $api_key = null ) {
		$this->api_key = null === $api_key ? Secrets::get_key() : (string) $api_key;
	}

	/**
	 * Build the request body for an agent turn.
	 *
	 * @param string $model    Model ID.
	 * @param string $system   System prompt.
	 * @param array  $tools    Tool definitions.
	 * @param array  $messages Conversation.
	 * @param array  $opts     effort, max_tokens, fallback.
	 * @return array
	 */
	public static function build_body( $model, $system, array $tools, array $messages, array $opts = array() ) {
		// Conversations round-trip through JSON with assoc arrays, which turns `{}` into `[]`.
		// tool_use.input must be an object, so restore empty objects before sending.
		foreach ( $messages as &$msg ) {
			if ( 'assistant' === $msg['role'] && is_array( $msg['content'] ) ) {
				foreach ( $msg['content'] as &$block ) {
					if ( isset( $block['type'] ) && 'tool_use' === $block['type'] && ( empty( $block['input'] ) || ! is_array( $block['input'] ) ) ) {
						$block['input'] = (object) array();
					}
				}
				unset( $block );
			}
		}
		unset( $msg );

		$body = array(
			'model'         => $model,
			'max_tokens'    => isset( $opts['max_tokens'] ) ? (int) $opts['max_tokens'] : 16000,
			// The system block caches tools + system (render order: tools → system → messages).
			'system'        => array(
				array(
					'type'          => 'text',
					'text'          => $system,
					'cache_control' => array( 'type' => 'ephemeral' ),
				),
			),
			'tools'         => $tools,
			'messages'      => $messages,
			// Top-level automatic caching also caches the growing conversation each turn.
			'cache_control' => array( 'type' => 'ephemeral' ),
			'output_config' => array( 'effort' => isset( $opts['effort'] ) ? $opts['effort'] : 'medium' ),
		);
		// Thinking: adaptive is the default for current models, so the param is omitted.
		if ( ! empty( $opts['fallback'] ) ) {
			$body['fallbacks'] = 'default';
		}
		return $body;
	}

	/**
	 * Send a Messages request.
	 *
	 * @param array $body Request body.
	 * @return array|WP_Error Decoded response.
	 */
	public function create_message( array $body ) {
		if ( '' === $this->api_key ) {
			return new WP_Error( 'aica_no_api_key', __( 'No Claude API key is configured. Add one under Settings.', 'ai-content-auditor' ) );
		}

		$headers = array(
			'content-type'      => 'application/json',
			'x-api-key'         => $this->api_key,
			'anthropic-version' => self::API_VERSION,
		);
		if ( isset( $body['fallbacks'] ) ) {
			$headers['anthropic-beta'] = self::FALLBACK_BETA;
		}

		$args = apply_filters(
			'aica_claude_request_args',
			array(
				'timeout'     => self::REQUEST_TIMEOUT,
				'headers'     => $headers,
				'body'        => wp_json_encode( $body ),
				'data_format' => 'body',
			),
			$body
		);

		$attempts = 0;
		do {
			++$attempts;
			$response = wp_remote_post( self::ENDPOINT, $args );
			if ( is_wp_error( $response ) ) {
				$error = new WP_Error( 'aica_http', sprintf( /* translators: %s: error */ __( 'Could not reach the Claude API: %s', 'ai-content-auditor' ), $response->get_error_message() ) );
				$retry = $attempts < 2;
			} else {
				$code    = (int) wp_remote_retrieve_response_code( $response );
				$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
				if ( $code >= 200 && $code < 300 && is_array( $decoded ) ) {
					return $decoded;
				}
				$error = self::error_from_response( $code, $decoded );
				// Retry transient errors once (429, 5xx, 529 overloaded), honoring x-should-retry.
				$should = wp_remote_retrieve_header( $response, 'x-should-retry' );
				$retry  = $attempts < 2 && 'false' !== $should && ( 429 === $code || 408 === $code || 409 === $code || $code >= 500 );
				if ( $retry ) {
					$wait  = (int) wp_remote_retrieve_header( $response, 'retry-after' );
					$delay = (int) apply_filters( 'aica_retry_delay', max( 1, min( 5, $wait ? $wait : 2 ) ), $code );
					if ( $delay > 0 ) {
						sleep( $delay );
					}
				}
			}
		} while ( $retry );

		return $error;
	}

	/**
	 * Map an API error to WP_Error with a helpful message.
	 *
	 * @param int        $code    HTTP status.
	 * @param array|null $decoded Body.
	 * @return WP_Error
	 */
	public static function error_from_response( $code, $decoded ) {
		$type = isset( $decoded['error']['type'] ) ? (string) $decoded['error']['type'] : 'api_error';
		$msg  = isset( $decoded['error']['message'] ) ? (string) $decoded['error']['message'] : __( 'Unexpected response from the Claude API.', 'ai-content-auditor' );
		$hint = '';
		switch ( $code ) {
			case 401:
				$hint = __( 'Check that your API key is correct and active.', 'ai-content-auditor' );
				break;
			case 403:
				$hint = __( 'Your key lacks permission for this model or feature.', 'ai-content-auditor' );
				break;
			case 404:
				$hint = __( 'The model may not be available to your account; try another model in Settings.', 'ai-content-auditor' );
				break;
			case 429:
				$hint = __( 'Rate limited. Wait a minute and resume the scan.', 'ai-content-auditor' );
				break;
			case 529:
				$hint = __( 'The API is temporarily overloaded. Resume the scan shortly.', 'ai-content-auditor' );
				break;
		}
		return new WP_Error(
			'aica_api_' . $type,
			trim( sprintf( 'Claude API error (%d %s): %s %s', $code, $type, $msg, $hint ) ),
			array(
				'status'    => $code,
				'retryable' => in_array( $code, array( 408, 409, 429, 500, 502, 503, 504, 529 ), true ),
			)
		);
	}

	/**
	 * Cheap connectivity check used by the "Test connection" button.
	 *
	 * @return array|WP_Error
	 */
	public function ping() {
		$model = Settings::get( 'model' );
		$body  = array(
			'model'         => $model,
			'max_tokens'    => 64,
			'output_config' => array( 'effort' => 'low' ),
			'messages'      => array(
				array(
					'role'    => 'user',
					'content' => 'Reply with the single word: ready',
				),
			),
		);
		$res   = $this->create_message( $body );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return array(
			'model' => isset( $res['model'] ) ? $res['model'] : $model,
			'ok'    => true,
		);
	}

	/**
	 * Estimate cost from usage.
	 *
	 * @param string $model Model ID.
	 * @param array  $usage Usage block.
	 * @return float USD.
	 */
	public static function cost( $model, array $usage ) {
		$models = Settings::models();
		$p      = isset( $models[ $model ] ) ? $models[ $model ] : $models['claude-opus-5-5'];
		$in     = isset( $usage['input_tokens'] ) ? (int) $usage['input_tokens'] : 0;
		$out    = isset( $usage['output_tokens'] ) ? (int) $usage['output_tokens'] : 0;
		$cr     = isset( $usage['cache_read_input_tokens'] ) ? (int) $usage['cache_read_input_tokens'] : 0;
		$cw     = isset( $usage['cache_creation_input_tokens'] ) ? (int) $usage['cache_creation_input_tokens'] : 0;
		return ( $in * $p['input'] + $cw * $p['input'] * 1.25 + $cr * $p['input'] * 0.05 + $out * $p['output'] ) / 1000000;
	}
}
