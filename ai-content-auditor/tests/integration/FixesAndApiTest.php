<?php
/**
 * Human-approved fixes, REST permissions and the MCP endpoint.
 *
 * @package AICA\Tests
 */

use AICA\Mcp\Mcp_Server;
use AICA\Repository;
use AICA\Safety\Fix_Applier;

/**
 * @covers \AICA\Safety\Fix_Applier
 * @covers \AICA\Rest_Controller
 * @covers \AICA\Mcp\Mcp_Server
 */
class FixesAndApiTest extends AICA_TestCase {

	/**
	 * Create a finding with a fix.
	 *
	 * @param int    $post   Post.
	 * @param string $field  Field.
	 * @param string $value  Value.
	 * @param string $target Target.
	 * @return array
	 */
	private function finding( $post, $field, $value, $target = '' ) {
		$scan = Repository::create_scan( 'quick', array() );
		$r    = Repository::add_finding(
			$scan,
			array(
				'post_id'        => $post,
				'category'       => 'seo',
				'severity'       => 'medium',
				'title'          => 'Fix me',
				'recommendation' => 'Do it',
				'check_code'     => 'test.' . $field,
				'fix_field'      => $field,
				'fix_value'      => $value,
				'fix_target'     => $target,
			)
		);
		return Repository::get_finding( $r['id'] );
	}

	public function test_meta_description_apply_and_undo() {
		$post = $this->post();
		$f    = $this->finding( $post, 'meta_description', 'A helpful summary of this post for search results.' );
		$r    = Fix_Applier::apply( $f );
		$this->assertIsArray( $r );
		$this->assertSame( 'A helpful summary of this post for search results.', get_post_meta( $post, '_aica_meta_description', true ) );
		$f = Repository::get_finding( $f['id'] );
		$this->assertSame( 'fixed', $f['status'] );
		$this->assertTrue( $f['can_undo'] );

		$this->assertWPError( Fix_Applier::apply( $f ), 'Cannot apply twice.' );
		$this->assertTrue( Fix_Applier::undo( $f ) );
		$this->assertSame( '', get_post_meta( $post, '_aica_meta_description', true ) );
		$this->assertSame( 'open', Repository::get_finding( $f['id'] )['status'] );
	}

	public function test_image_alt_apply_updates_markup_and_attachment_then_undo() {
		$att  = self::factory()->attachment->create( array( 'post_mime_type' => 'image/jpeg' ) );
		$src  = 'https://example.org/wp-content/uploads/team.jpg';
		$html = '<!-- wp:image {"id":' . $att . '} --><figure class="wp-block-image"><img src="' . $src . '" class="wp-image-' . $att . '"/></figure><!-- /wp:image -->';
		$post = $this->post( array( 'post_content' => $html ) );
		$orig = get_post( $post )->post_content;
		$f    = $this->finding( $post, 'image_alt', 'Sam, our founder, at the office', $src );

		$this->assertIsArray( Fix_Applier::apply( $f ) );
		$content = get_post( $post )->post_content;
		$this->assertStringContainsString( 'alt="Sam, our founder, at the office"', $content );
		$this->assertStringContainsString( '<!-- wp:image', $content, 'Block markup is preserved.' );
		$this->assertSame( 'Sam, our founder, at the office', get_post_meta( $att, '_wp_attachment_image_alt', true ) );
		$this->assertNotEmpty( wp_get_post_revisions( $post ), 'A revision is saved before content changes.' );

		$this->assertTrue( Fix_Applier::undo( Repository::get_finding( $f['id'] ) ) );
		$this->assertSame( $orig, get_post( $post )->post_content );
		$this->assertSame( '', get_post_meta( $att, '_wp_attachment_image_alt', true ) );
	}

	public function test_undo_refuses_to_clobber_later_edits() {
		$post = $this->post( array( 'post_content' => '<img src="https://example.org/x.jpg">' ) );
		$f    = $this->finding( $post, 'image_alt', 'A red bicycle', 'https://example.org/x.jpg' );
		Fix_Applier::apply( $f );
		wp_update_post(
			array(
				'ID'           => $post,
				'post_content' => get_post( $post )->post_content . '<p>Edited later</p>',
			)
		);
		$r = Fix_Applier::undo( Repository::get_finding( $f['id'] ) );
		$this->assertWPError( $r );
		$this->assertSame( 'aica_undo_conflict', $r->get_error_code() );
	}

	public function test_capabilities_and_allowlist() {
		$post = $this->post();
		$f    = $this->finding( $post, 'meta_description', 'Valid description text for the page.' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$r = Fix_Applier::apply( $f );
		$this->assertSame( 'aica_forbidden', $r->get_error_code() );

		wp_set_current_user( $this->admin );
		$this->assertWPError( Fix_Applier::sanitize_value( 'post_content', 'x' ) );
		$this->assertWPError( Fix_Applier::sanitize_value( 'excerpt', '   ' ) );
		$this->assertSame( 'Bold', Fix_Applier::sanitize_value( 'image_alt', '<b>Bold</b>' ) );
	}

	public function test_rest_requires_capability() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$res = rest_do_request( new WP_REST_Request( 'GET', '/aica/v1/dashboard' ) );
		$this->assertSame( 403, $res->get_status() );

		wp_set_current_user( $this->admin );
		$res = rest_do_request( new WP_REST_Request( 'GET', '/aica/v1/dashboard' ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertArrayHasKey( 'config', $res->get_data() );
		$this->assertArrayNotHasKey( 'api_key', $res->get_data()['config'], 'The API key never reaches the browser.' );
	}

	public function test_rest_quick_scan_and_edited_fix() {
		$mine = $this->post( array( 'post_content' => '<p>' . str_repeat( 'Bread rises when yeast ferments the sugars in dough. ', 6 ) . '</p>' ) );
		$req = new WP_REST_Request( 'POST', '/aica/v1/scans' );
		$req->set_param( 'mode', 'quick' );
		$scan = rest_do_request( $req )->get_data();
		$this->assertSame( 'running', $scan['status'] );

		for ( $i = 0; $i < 10; $i++ ) {
			$step = rest_do_request( new WP_REST_Request( 'POST', '/aica/v1/scans/' . $scan['id'] . '/step' ) )->get_data();
			if ( $step['scan']['is_terminal'] ) {
				break;
			}
		}
		$this->assertSame( 'completed', $step['scan']['status'] );
		$this->assertNotEmpty( $step['events'] );

		$list = rest_do_request( new WP_REST_Request( 'GET', '/aica/v1/findings' ) )->get_data();
		$meta = null;
		foreach ( $list['items'] as $item ) {
			if ( 'seo.meta_description_missing' === $item['check_code'] && $mine === $item['post_id'] ) {
				$meta = $item;
			}
		}
		$this->assertNotNull( $meta );

		$apply = new WP_REST_Request( 'POST', '/aica/v1/findings/' . $meta['id'] . '/apply' );
		$apply->set_param( 'value', 'Reviewer-edited <em>summary</em> of how bread rises.' );
		$res = rest_do_request( $apply );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 'Reviewer-edited summary of how bread rises.', get_post_meta( $meta['post_id'], '_aica_meta_description', true ) );
		$this->assertSame( 'fixed', $res->get_data()['finding']['status'] );
	}

	public function test_ai_scan_requires_key() {
		if ( getenv( 'ANTHROPIC_API_KEY' ) || defined( 'AICA_ANTHROPIC_API_KEY' ) ) {
			$this->markTestSkipped( 'A key is configured in the environment.' );
		}
		$req = new WP_REST_Request( 'POST', '/aica/v1/scans' );
		$req->set_param( 'mode', 'ai' );
		$res = rest_do_request( $req );
		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( 'aica_no_api_key', $res->get_data()['code'] );
	}

	/**
	 * JSON-RPC call through the REST endpoint.
	 *
	 * @param string $method Method.
	 * @param array  $params Params.
	 * @return WP_REST_Response
	 */
	private function mcp( $method, array $params = array() ) {
		$req = new WP_REST_Request( 'POST', '/aica/v1/mcp' );
		$req->set_header( 'content-type', 'application/json' );
		$req->set_body(
			wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => $method,
					'params'  => (object) $params,
				)
			)
		);
		return rest_do_request( $req );
	}

	public function test_mcp_disabled_by_default() {
		$this->assertSame( 403, $this->mcp( 'initialize' )->get_status() );
	}

	public function test_mcp_protocol_flow() {
		$this->settings( array( 'mcp_enabled' => 1 ) );

		$init = $this->mcp( 'initialize', array( 'protocolVersion' => '2025-06-18' ) )->get_data();
		$this->assertSame( '2025-06-18', $init['result']['protocolVersion'] );
		$this->assertSame( 'ai-content-auditor', $init['result']['serverInfo']['name'] );

		$tools = array_column( $this->mcp( 'tools/list' )->get_data()['result']['tools'], null, 'name' );
		$this->assertArrayHasKey( 'check_seo', $tools );
		$this->assertTrue( $tools['check_seo']['annotations']['readOnlyHint'] );
		$this->assertArrayNotHasKey( 'apply_fix', $tools, 'apply_fix is hidden until the owner allows it.' );

		$post = $this->post();
		$call = $this->mcp(
			'tools/call',
			array(
				'name'      => 'check_accessibility',
				'arguments' => array( 'post_id' => $post ),
			)
		)->get_data();
		$this->assertFalse( $call['result']['isError'] );
		$this->assertGreaterThan( 0, $call['result']['structuredContent']->issue_count );

		$bad = $this->mcp(
			'tools/call',
			array(
				'name'      => 'check_seo',
				'arguments' => array( 'post_id' => 'x' ),
			)
		)->get_data();
		$this->assertTrue( $bad['result']['isError'] );

		$this->assertSame( -32601, $this->mcp( 'nope/method' )->get_data()['error']['code'] );

		$this->settings( array( 'mcp_allow_fixes' => 1 ) );
		$tools = array_column( $this->mcp( 'tools/list' )->get_data()['result']['tools'], 'name' );
		$this->assertContains( 'apply_fix', $tools );
	}

	public function test_mcp_requires_admin() {
		$this->settings( array( 'mcp_enabled' => 1 ) );
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->mcp( 'tools/list' )->get_status() );
	}

	public function test_mcp_dispatch_ping_and_notifications() {
		$this->assertEquals( (object) array(), Mcp_Server::dispatch( 'ping', array() ) );
		$this->settings( array( 'mcp_enabled' => 1 ) );
		$req = new WP_REST_Request( 'POST', '/aica/v1/mcp' );
		$req->set_body( wp_json_encode( array( 'jsonrpc' => '2.0', 'method' => 'notifications/initialized' ) ) );
		$this->assertSame( 202, rest_do_request( $req )->get_status() );
	}
}
