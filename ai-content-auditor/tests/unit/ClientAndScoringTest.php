<?php
/**
 * Request building, error mapping, cost and scoring.
 *
 * @package AICA\Tests
 */

use AICA\Agent\Claude_Client;
use AICA\Agent\Quick_Scanner;
use AICA\Scoring;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AICA\Agent\Claude_Client
 * @covers \AICA\Scoring
 */
class ClientAndScoringTest extends TestCase {

	public function test_build_body_shapes_request_for_caching_and_fallback() {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Audit',
			),
			array(
				'role'    => 'assistant',
				'content' => array(
					array(
						'type'  => 'tool_use',
						'id'    => 'toolu_1',
						'name'  => 'get_site_overview',
						'input' => array(),
					),
				),
			),
		);
		$body     = Claude_Client::build_body(
			'claude-opus-5-5',
			'SYSTEM',
			array( array( 'name' => 't' ) ),
			$messages,
			array(
				'effort'   => 'high',
				'fallback' => true,
			)
		);

		$this->assertSame( 'claude-opus-5-5', $body['model'] );
		$this->assertSame( array( 'type' => 'ephemeral' ), $body['system'][0]['cache_control'] );
		$this->assertSame( array( 'type' => 'ephemeral' ), $body['cache_control'] );
		$this->assertSame( 'high', $body['output_config']['effort'] );
		$this->assertSame( 'default', $body['fallbacks'] );
		$this->assertArrayNotHasKey( 'thinking', $body, 'Adaptive thinking is the default; never send budget_tokens.' );
		$this->assertArrayNotHasKey( 'tool_choice', $body, 'Forced tool_choice is rejected on current models.' );
		$this->assertArrayNotHasKey( 'temperature', $body );

		$json = json_encode( $body );
		$this->assertStringContainsString( '"input":{}', $json, 'Empty tool_use input must serialize as an object.' );
	}

	public function test_build_body_without_fallback() {
		$body = Claude_Client::build_body( 'claude-haiku-5-5', 'S', array(), array(), array() );
		$this->assertArrayNotHasKey( 'fallbacks', $body );
		$this->assertSame( 'medium', $body['output_config']['effort'] );
	}

	public function test_error_mapping_marks_retryable() {
		$e = Claude_Client::error_from_response(
			429,
			array(
				'error' => array(
					'type'    => 'rate_limit_error',
					'message' => 'slow down',
				),
			)
		);
		$this->assertSame( 'aica_api_rate_limit_error', $e->get_error_code() );
		$this->assertTrue( $e->get_error_data()['retryable'] );
		$this->assertStringContainsString( 'slow down', $e->get_error_message() );

		$e = Claude_Client::error_from_response( 401, null );
		$this->assertFalse( $e->get_error_data()['retryable'] );
		$this->assertStringContainsString( 'API key', $e->get_error_message() );
	}

	public function test_cost_estimate() {
		$cost = Claude_Client::cost(
			'claude-opus-5-5',
			array(
				'input_tokens'            => 1000000,
				'output_tokens'           => 100000,
				'cache_read_input_tokens' => 1000000,
			)
		);
		// $4 input + $2 output + $0.20 cache reads.
		$this->assertEqualsWithDelta( 6.20, $cost, 0.0001 );
	}

	public function test_scoring() {
		$this->assertSame( 100, Scoring::score( 0, 10 ) );
		$this->assertGreaterThan( Scoring::score( 40, 10 ), Scoring::score( 10, 10 ) );
		$this->assertSame( 'A', Scoring::grade( 95 ) );
		$this->assertSame( 'F', Scoring::grade( 10 ) );

		$sum = Scoring::summarize(
			array(
				array(
					'category' => 'seo',
					'severity' => 'high',
					'status'   => 'open',
					'n'        => 2,
				),
				array(
					'category' => 'seo',
					'severity' => 'low',
					'status'   => 'fixed',
					'n'        => 3,
				),
				array(
					'category' => 'accessibility',
					'severity' => 'medium',
					'status'   => 'dismissed',
					'n'        => 1,
				),
			),
			4
		);
		$this->assertSame( 6, $sum['totals']['total'] );
		$this->assertSame( 2, $sum['totals']['open'] );
		$this->assertSame( 3, $sum['totals']['fixed'] );
		$this->assertSame( 1, $sum['totals']['dismissed'] );
		$this->assertSame( 2, $sum['categories']['seo']['high'] );
		$this->assertSame( 100, $sum['categories']['accessibility']['score'], 'Dismissed findings do not cost points.' );
		$this->assertLessThan( 100, $sum['score'] );
	}

	public function test_suggested_description_falls_back_to_body_when_excerpt_is_short() {
		$body = str_repeat( 'Bread rises when yeast ferments the sugars in dough. ', 6 );

		$short = Quick_Scanner::suggest_description(
			array(
				'excerpt' => 'Post excerpt 12',
				'text'    => $body,
			)
		);
		$this->assertStringStartsWith( 'Bread rises when yeast', $short );
		$this->assertLessThanOrEqual( 156, mb_strlen( $short ) );

		$long_excerpt = 'A hand-written excerpt that is comfortably longer than fifty characters.';
		$this->assertSame(
			$long_excerpt,
			Quick_Scanner::suggest_description(
				array(
					'excerpt' => $long_excerpt,
					'text'    => $body,
				)
			)
		);

		$this->assertSame(
			'',
			Quick_Scanner::suggest_description(
				array(
					'excerpt' => '',
					'text'    => 'Too short.',
				)
			)
		);
	}
}
