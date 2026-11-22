<?php
/**
 * The Claude tool-use loop against scripted API responses.
 *
 * @package AICA\Tests
 */

use AICA\Agent\Audit_Agent;
use AICA\Agent\Quick_Scanner;
use AICA\Repository;
use AICA\Secrets;

/**
 * @covers \AICA\Agent\Audit_Agent
 * @covers \AICA\Agent\Claude_Client
 * @covers \AICA\Agent\Quick_Scanner
 */
class AgentTest extends AICA_TestCase {

	public function set_up() {
		parent::set_up();
		Secrets::set_key( 'sk-ant-test-0123456789abcdef' );
	}

	public function tear_down() {
		Secrets::set_key( '' );
		parent::tear_down();
	}

	/**
	 * Drive a scan to completion.
	 *
	 * @param int $id Scan.
	 * @return array
	 */
	private function run_scan( $id ) {
		$agent = new Audit_Agent();
		for ( $i = 0; $i < 20; $i++ ) {
			$agent->step( $id );
			$scan = Repository::get_scan( $id, true );
			if ( $scan['is_terminal'] ) {
				return $scan;
			}
		}
		$this->fail( 'Scan did not finish.' );
	}

	public function test_full_agent_loop() {
		$post = $this->post();
		$this->responses = array(
			$this->tool_turn( array( array( 'get_site_overview' ) ), 'Let me look at the site.' ),
			$this->tool_turn(
				array(
					array( 'check_seo', array( 'post_id' => $post ) ),
					array( 'check_accessibility', array( 'post_id' => $post ) ),
				)
			),
			$this->tool_turn(
				array(
					array(
						'record_finding',
						array(
							'post_id'        => $post,
							'category'       => 'accessibility',
							'severity'       => 'high',
							'title'          => 'Image has no alt text',
							'recommendation' => 'Describe the image.',
							'check_code'     => 'a11y.img_missing_alt',
							'suggested_fix'  => array(
								'field'  => 'image_alt',
								'value'  => 'Product screenshot',
								'target' => 'https://example.org/a.jpg',
							),
						),
					),
				)
			),
			$this->final_turn( "**Overall:** needs work.\n\n- Fix alt text" ),
		);

		$scan = Audit_Agent::start();
		$this->assertSame( 'running', $scan['status'] );
		$done = $this->run_scan( $scan['id'] );

		$this->assertSame( 'completed', $done['status'] );
		$this->assertSame( 4, $done['iterations'] );
		$this->assertStringContainsString( 'needs work', $done['summary'] );
		$this->assertGreaterThan( 0, $done['cost_usd'] );
		$this->assertSame( 1, $done['posts_scanned'] );
		$this->assertSame( 0, Repository::active_scan_id(), 'Lock is released.' );

		// Request shape.
		$first = $this->requests[0];
		$this->assertSame( 'sk-ant-test-0123456789abcdef', $first['headers']['x-api-key'] );
		$this->assertSame( '2026-10-21', $first['headers']['anthropic-version'] );
		$this->assertSame( 'server-side-fallback-2026-07-01', $first['headers']['anthropic-beta'] );
		$this->assertSame( 'claude-opus-5-5', $first['body']['model'] );
		$this->assertSame( 'ephemeral', $first['body']['system'][0]['cache_control']['type'] );
		$this->assertNotContains( 'apply_fix', array_column( $first['body']['tools'], 'name' ) );

		// Second request replays assistant content verbatim and sends tool results.
		$second = $this->requests[1];
		$msgs   = $second['body']['messages'];
		$this->assertSame( 'assistant', $msgs[1]['role'] );
		$this->assertSame( 'thinking', $msgs[1]['content'][0]['type'], 'Thinking blocks are passed back unchanged.' );
		$this->assertStringContainsString( '"input":{}', $second['raw'] );
		$this->assertSame( 'tool_result', $msgs[2]['content'][0]['type'] );

		// Both parallel tool results in ONE user message.
		$third = $this->requests[2]['body']['messages'];
		$this->assertCount( 2, $third[4]['content'] );

		$f = Repository::query_findings( array( 'scan_id' => $scan['id'] ) );
		$this->assertSame( 1, $f['total'] );
		$this->assertSame( 'ai', $f['items'][0]['source'] );
		$this->assertTrue( $f['items'][0]['has_fix'] );

		// The agent never changed content.
		$this->assertStringNotContainsString( 'Product screenshot', get_post( $post )->post_content );
	}

	public function test_tool_errors_are_returned_as_is_error() {
		$this->responses = array(
			$this->tool_turn( array( array( 'get_content', array( 'post_id' => 999999 ) ) ) ),
			$this->final_turn( 'Done.' ),
		);
		$scan = Audit_Agent::start();
		$this->run_scan( $scan['id'] );
		$result = $this->requests[1]['body']['messages'][2]['content'][0];
		$this->assertTrue( $result['is_error'] );
	}

	public function test_budget_notice_and_hard_stop() {
		$this->settings( array( 'max_iterations' => 3 ) );
		$this->responses = array(
			$this->tool_turn( array( array( 'get_site_overview' ) ) ),
			$this->tool_turn( array( array( 'get_site_overview' ) ) ),
			$this->tool_turn( array( array( 'get_site_overview' ) ) ),
			$this->tool_turn( array( array( 'get_site_overview' ) ) ),
			$this->tool_turn( array( array( 'get_site_overview' ) ) ),
		);
		$scan = Audit_Agent::start();
		$done = $this->run_scan( $scan['id'] );
		$this->assertSame( 'budget_exhausted', $done['status'] );
		$this->assertLessThanOrEqual( 4, count( $this->requests ), 'Hard stop after max turns.' );

		$notice = false;
		foreach ( $this->requests as $r ) {
			foreach ( $r['body']['messages'] as $m ) {
				if ( 'user' === $m['role'] && is_array( $m['content'] ) ) {
					foreach ( $m['content'] as $b ) {
						$notice = $notice || ( 'text' === $b['type'] && false !== strpos( $b['text'], 'Budget notice' ) );
					}
				}
			}
		}
		$this->assertTrue( $notice, 'The agent is asked to wrap up before the hard stop.' );
	}

	public function test_refusal_fails_gracefully() {
		$r                 = $this->final_turn( '', 'refusal' );
		$r['stop_details'] = array(
			'type'     => 'refusal',
			'category' => 'cyber',
		);
		$this->responses   = array( $r );
		$scan              = Audit_Agent::start();
		$done              = $this->run_scan( $scan['id'] );
		$this->assertSame( 'failed', $done['status'] );
		$this->assertStringContainsString( 'cyber', $done['error'] );
	}

	public function test_retryable_api_error_keeps_scan_running() {
		$this->responses = array(
			array(
				'__status' => 529,
				'type'     => 'error',
				'error'    => array(
					'type'    => 'overloaded_error',
					'message' => 'Overloaded',
				),
			),
			array(
				'__status' => 529,
				'type'     => 'error',
				'error'    => array(
					'type'    => 'overloaded_error',
					'message' => 'Overloaded',
				),
			),
			$this->final_turn( 'Recovered.' ),
		);
		add_filter( 'aica_retry_delay', '__return_zero' );
		$scan = Audit_Agent::start();
		$r    = ( new Audit_Agent() )->step( $scan['id'] );
		$this->assertWPError( $r );
		$this->assertSame( 'running', Repository::get_scan( $scan['id'] )['status'] );
		$done = $this->run_scan( $scan['id'] );
		$this->assertSame( 'completed', $done['status'] );
	}

	public function test_single_scan_lock() {
		$this->responses = array( $this->final_turn( 'ok' ) );
		$a               = Audit_Agent::start();
		$b               = Quick_Scanner::start();
		$this->assertWPError( $b );
		$this->assertSame( 'aica_scan_running', $b->get_error_code() );
		Audit_Agent::cancel( $a['id'] );
		$this->assertIsArray( Quick_Scanner::start() );
	}

	public function test_quick_scan_finds_issues_and_suggests_meta_description() {
		$this->post(
			array(
				'post_title'   => 'A reasonably titled post about gardening',
				'post_content' => '<p>' . str_repeat( 'Tomatoes grow best in full sun with steady watering. ', 8 ) . '</p><img src="https://example.org/t.jpg">',
			)
		);
		$scan = Quick_Scanner::start();
		for ( $i = 0; $i < 10 && ! Repository::get_scan( $scan['id'] )['is_terminal']; $i++ ) {
			Quick_Scanner::step( $scan['id'] );
		}
		$done  = Repository::get_scan( $scan['id'] );
		$found = Repository::query_findings( array( 'scan_id' => $scan['id'] ) )['items'];
		$codes = array_column( $found, 'check_code' );
		$this->assertSame( 'completed', $done['status'] );
		$this->assertContains( 'a11y.img_missing_alt', $codes );
		$meta = $found[ array_search( 'seo.meta_description_missing', $codes, true ) ];
		$this->assertTrue( $meta['has_fix'] );
		$this->assertLessThanOrEqual( 156, mb_strlen( $meta['fix_value'] ) );
		$this->assertSame( 0, (int) $done['cost_usd'] );
		$this->assertEmpty( $this->requests, 'Quick scan never calls the API.' );
	}
}

