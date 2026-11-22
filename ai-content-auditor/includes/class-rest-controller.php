<?php
/**
 * REST API used by the admin dashboard (namespace aica/v1).
 *
 * Every route requires the audit capability; cookie auth requires the wp_rest nonce.
 *
 * @package AICA
 */

namespace AICA;

use AICA\Agent\Audit_Agent;
use AICA\Agent\Claude_Client;
use AICA\Agent\Quick_Scanner;
use AICA\Safety\Fix_Applier;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller.
 */
class Rest_Controller {

	const NS = 'aica/v1';

	/**
	 * Register routes.
	 */
	public static function register_routes() {
		$perm = array( __CLASS__, 'can_audit' );
		$id   = array(
			'id' => array(
				'type'     => 'integer',
				'required' => true,
				'minimum'  => 1,
			),
		);

		register_rest_route(
			self::NS,
			'/dashboard',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'dashboard' ),
				'permission_callback' => $perm,
			)
		);
		register_rest_route(
			self::NS,
			'/scans',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'create_scan' ),
				'permission_callback' => $perm,
				'args'                => array(
					'mode'      => array(
						'type'     => 'string',
						'enum'     => array( 'ai', 'quick' ),
						'required' => true,
					),
					'max_posts' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 500,
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/scans/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_scan' ),
				'permission_callback' => $perm,
				'args'                => $id + array(
					'after' => array(
						'type'    => 'integer',
						'default' => 0,
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/scans/(?P<id>\d+)/step',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'step_scan' ),
				'permission_callback' => $perm,
				'args'                => $id + array(
					'after' => array(
						'type'    => 'integer',
						'default' => 0,
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/scans/(?P<id>\d+)/cancel',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'cancel_scan' ),
				'permission_callback' => $perm,
				'args'                => $id,
			)
		);
		register_rest_route(
			self::NS,
			'/findings',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'list_findings' ),
				'permission_callback' => $perm,
				'args'                => array(
					'scan_id'  => array( 'type' => 'integer' ),
					'category' => array(
						'type' => 'string',
						'enum' => array_merge( array( '' ), Analyzers\Issue::CATEGORIES ),
					),
					'severity' => array(
						'type' => 'string',
						'enum' => array_merge( array( '' ), Analyzers\Issue::SEVERITIES ),
					),
					'status'   => array(
						'type' => 'string',
						'enum' => array( '', 'open', 'fixed', 'dismissed' ),
					),
					'search'   => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'page'     => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
					'per_page' => array(
						'type'    => 'integer',
						'default' => 50,
						'minimum' => 1,
						'maximum' => 200,
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/findings/(?P<id>\d+)/(?P<action>apply|undo|dismiss|reopen)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'finding_action' ),
				'permission_callback' => $perm,
				'args'                => $id + array(
					'value' => array(
						'type'        => 'string',
						'description' => 'Edited fix value (apply only). Sanitized like any suggested fix.',
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/test-connection',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'test_connection' ),
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
	}

	/**
	 * Permission callback.
	 *
	 * @return bool
	 */
	public static function can_audit() {
		return current_user_can( Plugin::capability() );
	}

	/**
	 * GET /dashboard.
	 *
	 * @return array
	 */
	public static function dashboard() {
		$latest  = Repository::latest_scan();
		$history = array();
		foreach ( Repository::recent_scans( 8 ) as $s ) {
			if ( 'mcp' === $s['mode'] ) {
				continue;
			}
			$sum       = Scoring::summarize( Repository::finding_stats( $s['id'] ), $s['posts_scanned'] );
			$history[] = self::prepare_scan( $s ) + array(
				'score'    => $sum['score'],
				'findings' => $sum['totals']['total'],
			);
		}
		$active = Repository::active_scan_id();
		return array(
			'latest'      => $latest ? self::prepare_scan( $latest ) + array( 'stats' => Scoring::summarize( Repository::finding_stats( $latest['id'] ), $latest['posts_scanned'] ) ) : null,
			'active_scan' => $active ? self::prepare_scan( Repository::get_scan( $active ) ) : null,
			'history'     => $history,
			'top'         => $latest ? array_map(
				array( __CLASS__, 'prepare_finding' ),
				Repository::query_findings(
					array(
						'scan_id' => $latest['id'],
						'status'  => 'open',
						'limit'   => 5,
					)
				)['items']
			) : array(),
			'config'      => array(
				'has_key'     => Secrets::has_key(),
				'model'       => Settings::get( 'model' ),
				'model_label' => self::model_label( Settings::get( 'model' ) ),
				'max_posts'   => (int) Settings::get( 'max_posts' ),
			),
		);
	}

	/**
	 * POST /scans.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return array|WP_Error
	 */
	public static function create_scan( WP_REST_Request $req ) {
		$overrides = array_filter( array( 'max_posts' => $req->get_param( 'max_posts' ) ) );
		if ( 'ai' === $req->get_param( 'mode' ) ) {
			if ( ! Secrets::has_key() ) {
				return new WP_Error( 'aica_no_api_key', __( 'Add your Claude API key in Settings to run an AI audit — or run a Quick scan, which needs no key.', 'ai-content-auditor' ), array( 'status' => 400 ) );
			}
			$scan = Audit_Agent::start( $overrides );
		} else {
			$scan = Quick_Scanner::start( $overrides );
		}
		return is_wp_error( $scan ) ? $scan : self::prepare_scan( $scan );
	}

	/**
	 * GET /scans/{id}.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return array|WP_Error
	 */
	public static function get_scan( WP_REST_Request $req ) {
		$scan = Repository::get_scan( (int) $req['id'] );
		if ( ! $scan ) {
			return new WP_Error( 'aica_not_found', __( 'Scan not found.', 'ai-content-auditor' ), array( 'status' => 404 ) );
		}
		return self::scan_payload( $scan, (int) $req['after'] );
	}

	/**
	 * POST /scans/{id}/step — advance the scan by one turn/batch.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return array|WP_Error
	 */
	public static function step_scan( WP_REST_Request $req ) {
		$id   = (int) $req['id'];
		$scan = Repository::get_scan( $id );
		if ( ! $scan ) {
			return new WP_Error( 'aica_not_found', __( 'Scan not found.', 'ai-content-auditor' ), array( 'status' => 404 ) );
		}
		// One agent turn waits on a single Claude API call (up to 180 s); give this request room to finish.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged
		}
		$result = 'quick' === $scan['mode'] ? Quick_Scanner::step( $id ) : ( new Audit_Agent() )->step( $id );
		$fresh  = Repository::get_scan( $id );
		$out    = self::scan_payload( $fresh, (int) $req['after'] );
		if ( is_wp_error( $result ) ) {
			$data              = $result->get_error_data();
			$out['step_error'] = array(
				'message'   => $result->get_error_message(),
				'retryable' => is_array( $data ) && ! empty( $data['retryable'] ),
			);
		}
		return $out;
	}

	/**
	 * POST /scans/{id}/cancel.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return array
	 */
	public static function cancel_scan( WP_REST_Request $req ) {
		Audit_Agent::cancel( (int) $req['id'] );
		return self::scan_payload( Repository::get_scan( (int) $req['id'] ), 0 );
	}

	/**
	 * GET /findings.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return array
	 */
	public static function list_findings( WP_REST_Request $req ) {
		$scan_id = (int) $req->get_param( 'scan_id' );
		if ( ! $scan_id ) {
			$latest  = Repository::latest_scan();
			$scan_id = $latest ? $latest['id'] : 0;
		}
		$per  = (int) $req['per_page'];
		$res  = Repository::query_findings(
			array(
				'scan_id'  => $scan_id,
				'category' => $req->get_param( 'category' ),
				'severity' => $req->get_param( 'severity' ),
				'status'   => $req->get_param( 'status' ),
				'search'   => $req->get_param( 'search' ),
				'limit'    => $per,
				'offset'   => ( (int) $req['page'] - 1 ) * $per,
			)
		);
		$scan = $scan_id ? Repository::get_scan( $scan_id ) : null;
		return array(
			'scan'  => $scan ? self::prepare_scan( $scan ) + array( 'stats' => Scoring::summarize( Repository::finding_stats( $scan_id ), $scan['posts_scanned'] ) ) : null,
			'total' => $res['total'],
			'items' => array_map( array( __CLASS__, 'prepare_finding' ), $res['items'] ),
		);
	}

	/**
	 * POST /findings/{id}/{action}.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return array|WP_Error
	 */
	public static function finding_action( WP_REST_Request $req ) {
		$finding = Repository::get_finding( (int) $req['id'] );
		if ( ! $finding ) {
			return new WP_Error( 'aica_not_found', __( 'Finding not found.', 'ai-content-auditor' ), array( 'status' => 404 ) );
		}
		switch ( $req['action'] ) {
			case 'apply':
				$edited = $req->get_param( 'value' );
				if ( $finding['has_fix'] && is_string( $edited ) && '' !== trim( $edited ) ) {
					// The reviewer may refine the suggestion before approving it.
					$clean = Fix_Applier::sanitize_value( $finding['fix_field'], $edited );
					if ( is_wp_error( $clean ) ) {
						return $clean;
					}
					if ( $clean !== $finding['fix_value'] ) {
						Repository::update_finding( $finding['id'], array( 'fix_value' => $clean ) );
						$finding['fix_value'] = $clean;
					}
				}
				$r = Fix_Applier::apply( $finding );
				break;
			case 'undo':
				$r = Fix_Applier::undo( $finding );
				break;
			case 'dismiss':
				Repository::update_finding( $finding['id'], array( 'status' => 'dismissed' ) );
				$r = true;
				break;
			default:
				if ( 'fixed' === $finding['status'] ) {
					return new WP_Error( 'aica_reopen_fixed', __( 'Undo the fix instead of reopening.', 'ai-content-auditor' ), array( 'status' => 409 ) );
				}
				Repository::update_finding( $finding['id'], array( 'status' => 'open' ) );
				$r = true;
		}
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$scan = Repository::get_scan( $finding['scan_id'] );
		return array(
			'finding' => self::prepare_finding( Repository::get_finding( $finding['id'] ) ),
			'stats'   => Scoring::summarize( Repository::finding_stats( $finding['scan_id'] ), $scan ? $scan['posts_scanned'] : 1 ),
		);
	}

	/**
	 * POST /test-connection.
	 *
	 * @return array|WP_Error
	 */
	public static function test_connection() {
		$r = ( new Claude_Client() )->ping();
		if ( is_wp_error( $r ) ) {
			return new WP_Error( $r->get_error_code(), $r->get_error_message(), array( 'status' => 400 ) );
		}
		return $r;
	}

	/* ------------------------------------------------------------------ */

	/**
	 * Scan + events + stats for polling.
	 *
	 * @param array $scan  Scan.
	 * @param int   $after Event cursor.
	 * @return array
	 */
	private static function scan_payload( array $scan, $after ) {
		return array(
			'scan'   => self::prepare_scan( $scan ),
			'events' => Repository::events( $scan['id'], $after ),
			'stats'  => Scoring::summarize( Repository::finding_stats( $scan['id'] ), $scan['posts_scanned'] ),
		);
	}

	/**
	 * Public shape of a scan.
	 *
	 * @param array|null $s Scan.
	 * @return array|null
	 */
	public static function prepare_scan( $s ) {
		if ( ! $s ) {
			return null;
		}
		$scope = $s['scope'];
		return array(
			'id'             => $s['id'],
			'mode'           => $s['mode'],
			'status'         => $s['status'],
			'is_terminal'    => $s['is_terminal'],
			'model'          => $s['model'],
			'model_label'    => self::model_label( $s['model'] ),
			'summary'        => (string) $s['summary'],
			'error'          => (string) $s['error'],
			'iterations'     => $s['iterations'],
			'max_iterations' => isset( $scope['max_iterations'] ) ? (int) $scope['max_iterations'] : 0,
			'posts_scanned'  => $s['posts_scanned'],
			'max_posts'      => isset( $scope['max_posts'] ) ? (int) $scope['max_posts'] : 0,
			'tokens'         => $s['input_tokens'] + $s['output_tokens'] + $s['cache_read_tokens'] + $s['cache_write_tokens'],
			'token_budget'   => isset( $scope['token_budget'] ) ? (int) $scope['token_budget'] : 0,
			'cache_read'     => $s['cache_read_tokens'],
			'cost_usd'       => $s['cost_usd'],
			'created_at'     => self::iso( $s['created_at'] ),
			'finished_at'    => $s['finished_at'] ? self::iso( $s['finished_at'] ) : null,
		);
	}

	/**
	 * Public shape of a finding.
	 *
	 * @param array $f Finding.
	 * @return array
	 */
	public static function prepare_finding( $f ) {
		$post = get_post( $f['post_id'] );
		return array(
			'id'             => $f['id'],
			'scan_id'        => $f['scan_id'],
			'post_id'        => $f['post_id'],
			'post_title'     => $post ? html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ) : __( '(deleted)', 'ai-content-auditor' ),
			'post_type'      => $post && get_post_type_object( $post->post_type ) ? get_post_type_object( $post->post_type )->labels->singular_name : '',
			'edit_url'       => $post && current_user_can( 'edit_post', $post->ID ) ? get_edit_post_link( $post->ID, 'raw' ) : '',
			'view_url'       => $post ? get_permalink( $post ) : '',
			'category'       => $f['category'],
			'severity'       => $f['severity'],
			'check_code'     => $f['check_code'],
			'title'          => $f['title'],
			'detail'         => $f['detail'],
			'recommendation' => $f['recommendation'],
			'source'         => $f['source'],
			'status'         => $f['status'],
			'fix'            => $f['has_fix'] ? array(
				'field'   => $f['fix_field'],
				'target'  => $f['fix_target'],
				'value'   => $f['fix_value'],
				'current' => self::current_value( $f ),
			) : null,
			'can_undo'       => $f['can_undo'],
		);
	}

	/**
	 * Current value of the field a fix targets (for the before/after preview).
	 *
	 * @param array $f Finding.
	 * @return string
	 */
	private static function current_value( array $f ) {
		$post = get_post( $f['post_id'] );
		if ( ! $post ) {
			return '';
		}
		switch ( $f['fix_field'] ) {
			case 'meta_description':
				return Analyzers\Content_Extractor::meta_description( $post->ID );
			case 'excerpt':
				return (string) $post->post_excerpt;
			case 'image_alt':
				if ( class_exists( 'WP_HTML_Tag_Processor' ) ) {
					$p = new \WP_HTML_Tag_Processor( $post->post_content );
					while ( $p->next_tag( 'img' ) ) {
						if ( $p->get_attribute( 'src' ) === $f['fix_target'] ) {
							$alt = $p->get_attribute( 'alt' );
							return null === $alt ? '' : (string) $alt;
						}
					}
				}
				return '';
		}
		return '';
	}

	/**
	 * UTC MySQL datetime → ISO 8601 with timezone.
	 *
	 * @param string $mysql Datetime.
	 * @return string
	 */
	private static function iso( $mysql ) {
		return gmdate( 'c', (int) strtotime( $mysql . ' UTC' ) );
	}

	/**
	 * Model label.
	 *
	 * @param string $model Model ID.
	 * @return string
	 */
	private static function model_label( $model ) {
		$m = Settings::models();
		return isset( $m[ $model ] ) ? preg_replace( '/\s*\(.*\)$/', '', $m[ $model ]['label'] ) : (string) $model;
	}
}
