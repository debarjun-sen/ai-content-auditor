<?php
/**
 * Analyzer unit tests.
 *
 * @package AICA\Tests
 */

use AICA\Analyzers\Accessibility_Analyzer;
use AICA\Analyzers\Content_Extractor;
use AICA\Analyzers\Freshness_Analyzer;
use AICA\Analyzers\Seo_Analyzer;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AICA\Analyzers\Content_Extractor
 * @covers \AICA\Analyzers\Seo_Analyzer
 * @covers \AICA\Analyzers\Accessibility_Analyzer
 * @covers \AICA\Analyzers\Freshness_Analyzer
 */
class AnalyzersTest extends TestCase {

	/**
	 * Codes of issues.
	 *
	 * @param array $issues Issues.
	 * @return string[]
	 */
	private function codes( array $issues ) {
		return array_column( $issues, 'check_code' );
	}

	/**
	 * Doc helper.
	 *
	 * @param string $html  HTML.
	 * @param array  $extra Extra fields.
	 * @return array
	 */
	private function doc( $html, array $extra = array() ) {
		return array_merge(
			array(
				'title'              => 'A perfectly reasonable post title here',
				'type'               => 'post',
				'slug'               => 'reasonable-title',
				'meta_description'   => str_repeat( 'Useful summary text. ', 6 ),
				'has_featured_image' => true,
				'featured_image_alt' => 'A chart',
				'modified'           => gmdate( 'Y-m-d H:i:s' ),
				'excerpt'            => '',
			),
			Content_Extractor::from_html( $html, 'example.com' ),
			$extra
		);
	}

	public function test_extractor_reads_structure() {
		$d = Content_Extractor::from_html(
			'<h2>Intro</h2><p>Hello world.</p><p>Second para</p>' .
			'<img src="/a.jpg"><img src="/b.jpg" alt=""><img src="/c.jpg" alt="Cat" class="wp-image-42">' .
			'<a href="/about/">About us</a><a href="https://example.com/x">x</a><a href="https://other.org">o</a><a href="mailto:a@b.c">mail</a>' .
			'<table><tr><th>H</th></tr><tr><td>v</td></tr></table><iframe src="https://maps.example/" title="Map"></iframe>',
			'example.com'
		);
		$this->assertSame( array( 'level' => 2, 'text' => 'Intro' ), $d['headings'][0] );
		$this->assertNull( $d['images'][0]['alt'] );
		$this->assertSame( '', $d['images'][1]['alt'] );
		$this->assertSame( 42, $d['images'][2]['attachment_id'] );
		$this->assertTrue( $d['links'][0]['internal'] );
		$this->assertTrue( $d['links'][1]['internal'] );
		$this->assertFalse( $d['links'][2]['internal'] );
		$this->assertSame( 'mailto', $d['links'][3]['kind'] );
		$this->assertTrue( $d['tables'][0]['has_th'] );
		$this->assertSame( 'Map', $d['iframes'][0]['title'] );
		$this->assertStringContainsString( 'Hello world. Second para', $d['text'] );
		$this->assertStringNotContainsString( 'world.Second', $d['text'] );
	}

	public function test_word_count_is_unicode_aware() {
		$this->assertSame( 4, Content_Extractor::word_count( 'Café déjà vu, naïve' ) );
		$this->assertSame( 0, Content_Extractor::word_count( '   ' ) );
	}

	public function test_seo_flags_title_and_meta_problems() {
		$issues = Seo_Analyzer::analyze( $this->doc( '<p>Short.</p>', array( 'title' => 'Hi', 'meta_description' => '' ) ) );
		$codes  = $this->codes( $issues );
		$this->assertContains( 'seo.title_short', $codes );
		$this->assertContains( 'seo.meta_description_missing', $codes );
		$this->assertContains( 'seo.thin_content', $codes );
		$meta = $issues[ array_search( 'seo.meta_description_missing', $codes, true ) ];
		$this->assertSame( 'meta_description', $meta['fixable']['field'] );
	}

	public function test_seo_long_title_body_h1_and_internal_links() {
		$body   = '<h1>Dup</h1><p>' . str_repeat( 'word ', 320 ) . '</p>';
		$issues = Seo_Analyzer::analyze( $this->doc( $body, array( 'title' => str_repeat( 'Long title ', 8 ) ) ) );
		$codes  = $this->codes( $issues );
		$this->assertContains( 'seo.title_long', $codes );
		$this->assertContains( 'seo.multiple_h1', $codes );
		$this->assertContains( 'seo.no_internal_links', $codes );
		$this->assertNotContains( 'seo.thin_content', $codes );
	}

	public function test_seo_clean_post_has_no_issues() {
		$body = '<h2>Section</h2><p>' . str_repeat( 'word ', 350 ) . '<a href="/related/">Related guide</a></p>';
		$this->assertSame( array(), Seo_Analyzer::analyze( $this->doc( $body ) ) );
	}

	public function test_a11y_images() {
		$issues = Accessibility_Analyzer::analyze( $this->doc( '<img src="/u/team.jpg"><img src="/u/x.jpg" alt="IMG_2041.jpg"><img src="/u/d.jpg" alt=""><img src="/u/p.jpg" role="presentation"><img src="/u/ok.jpg" alt="Our office at sunset">' ) );
		$codes  = $this->codes( $issues );
		$this->assertSame( 1, count( array_keys( $codes, 'a11y.img_missing_alt', true ) ) );
		$this->assertSame( 1, count( array_keys( $codes, 'a11y.img_generic_alt', true ) ) );
		$this->assertSame( '/u/team.jpg', $issues[0]['fixable']['target'] );
		$this->assertSame( '1.1.1', $issues[0]['wcag'] );
	}

	public function test_a11y_generic_alt_detection() {
		$this->assertTrue( Accessibility_Analyzer::is_generic_alt( 'DSC_0042' ) );
		$this->assertTrue( Accessibility_Analyzer::is_generic_alt( 'photo.png' ) );
		$this->assertTrue( Accessibility_Analyzer::is_generic_alt( 'Image' ) );
		$this->assertFalse( Accessibility_Analyzer::is_generic_alt( 'Two hikers on a ridge at dawn' ) );
	}

	public function test_a11y_headings_links_tables_embeds() {
		$html   = '<h2>One</h2><h4>Skip</h4><h3></h3>' .
			'<a href="/x"><img src="/i.png" alt=""></a><a href="/y">Click here</a><a href="/z">click here!</a><a href="/w" aria-label="Pricing details">More</a>' .
			'<table><tr><td>a</td></tr><tr><td>b</td></tr></table><iframe src="https://youtube.com/embed/1"></iframe><video autoplay></video>';
		$codes  = $this->codes( Accessibility_Analyzer::analyze( $this->doc( $html ) ) );
		$this->assertContains( 'a11y.heading_skip', $codes );
		$this->assertContains( 'a11y.empty_heading', $codes );
		$this->assertContains( 'a11y.empty_link', $codes );
		$this->assertSame( 1, count( array_keys( $codes, 'a11y.vague_link_text', true ) ), 'Vague link text is reported once per phrase, and aria-label rescues "More".' );
		$this->assertContains( 'a11y.table_no_headers', $codes );
		$this->assertContains( 'a11y.iframe_no_title', $codes );
		$this->assertContains( 'a11y.video_autoplay', $codes );
		$this->assertContains( 'a11y.video_no_captions', $codes );
	}

	public function test_freshness_age_and_years() {
		$now  = strtotime( '2026-11-22 00:00:00 UTC' );
		$doc  = $this->doc( '<p>Our picks for 2019 are currently the best this year.</p>', array( 'title' => 'Best tools for 2019', 'modified' => '2024-01-01 00:00:00' ) );
		$iss  = Freshness_Analyzer::analyze( $doc, $now, 365 );
		$by   = array_column( $iss, null, 'check_code' );
		$this->assertSame( 'high', $by['fresh.not_updated']['severity'], 'Over 2× the threshold is high severity.' );
		$this->assertSame( 'high', $by['fresh.dated_year']['severity'], 'Past year in the title is high severity.' );
		$this->assertArrayHasKey( 'fresh.time_sensitive', $by );
	}

	public function test_freshness_recent_content_is_clean_and_last_year_ok() {
		$now = strtotime( '2026-11-22 00:00:00 UTC' );
		$doc = $this->doc( '<p>Updated for 2025 and 2026.</p>', array( 'modified' => '2026-10-01 00:00:00' ) );
		$this->assertSame( array(), Freshness_Analyzer::analyze( $doc, $now, 365 ) );
	}

	public function test_freshness_broken_internal_links_use_resolver() {
		$doc    = $this->doc( '<p><a href="/exists/">ok</a> <a href="/gone/">gone</a> <a href="https://elsewhere.org/">ext</a></p>' );
		$iss    = Freshness_Analyzer::analyze(
			$doc,
			time(),
			365,
			static function ( $href ) {
				return '/exists/' === $href;
			}
		);
		$this->assertSame( array( 'fresh.broken_internal_links' ), $this->codes( $iss ) );
		$this->assertStringContainsString( '/gone/', $iss[0]['detail'] );
		$this->assertStringNotContainsString( 'elsewhere', $iss[0]['detail'] );
	}

	public function test_human_age() {
		$this->assertSame( '2 years', Freshness_Analyzer::human_age( 730 ) );
		$this->assertSame( '4 months', Freshness_Analyzer::human_age( 120 ) );
		$this->assertSame( '10 days', Freshness_Analyzer::human_age( 10 ) );
	}
}
