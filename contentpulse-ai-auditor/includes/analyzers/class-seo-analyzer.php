<?php
/**
 * Deterministic on-page SEO checks.
 *
 * @package AICA
 */

namespace AICA\Analyzers;

defined( 'ABSPATH' ) || exit;

/**
 * SEO analyzer. Input is a document from Content_Extractor.
 */
class Seo_Analyzer {

	const TITLE_MIN = 30;
	const TITLE_MAX = 60;
	const DESC_MIN  = 70;
	const DESC_MAX  = 160;
	const THIN      = 300;

	/**
	 * Run checks.
	 *
	 * @param array $doc Document.
	 * @return array[] Issues.
	 */
	public static function analyze( array $doc ) {
		$issues = array();
		$title  = isset( $doc['title'] ) ? trim( (string) $doc['title'] ) : '';
		$tlen   = Content_Extractor::len( $title );
		$type   = isset( $doc['type'] ) ? $doc['type'] : 'post';
		$words  = isset( $doc['word_count'] ) ? (int) $doc['word_count'] : 0;

		if ( '' === $title ) {
			$issues[] = Issue::make( 'seo.title_missing', 'seo', 'high', 'Missing title', 'This content has no title, so search results will show a generic label.', 'Add a descriptive title of 30–60 characters that includes the main topic.' );
		} elseif ( $tlen < self::TITLE_MIN ) {
			$issues[] = Issue::make( 'seo.title_short', 'seo', 'medium', 'Title is too short', sprintf( 'The title “%s” is %d characters; search engines favor 30–60.', $title, $tlen ), 'Expand the title with the main keyword and a clear benefit.' );
		} elseif ( $tlen > self::TITLE_MAX ) {
			$issues[] = Issue::make( 'seo.title_long', 'seo', 'medium', 'Title will be truncated in search results', sprintf( 'The title is %d characters; anything past ~60 is usually cut off.', $tlen ), 'Shorten the title to 60 characters or fewer, keeping the key phrase near the start.' );
		}

		$desc = isset( $doc['meta_description'] ) ? trim( (string) $doc['meta_description'] ) : '';
		$dlen = Content_Extractor::len( $desc );
		if ( '' === $desc ) {
			$issues[] = Issue::make( 'seo.meta_description_missing', 'seo', 'medium', 'No meta description', 'Search engines will pick an arbitrary snippet from the page.', 'Write a 120–155 character summary that tells searchers what they will get.', array( 'field' => 'meta_description' ) );
		} elseif ( $dlen < self::DESC_MIN || $dlen > self::DESC_MAX ) {
			$issues[] = Issue::make( 'seo.meta_description_length', 'seo', 'low', 'Meta description length is off', sprintf( 'The meta description is %d characters (aim for 70–160).', $dlen ), 'Rewrite it to roughly 120–155 characters.', array( 'field' => 'meta_description' ) );
		}

		if ( 'post' === $type && $words > 0 && $words < self::THIN ) {
			$issues[] = Issue::make( 'seo.thin_content', 'seo', 'medium', 'Thin content', sprintf( 'Only %d words. Short posts rarely rank for competitive queries.', $words ), 'Expand with examples, steps or FAQs — or merge it into a related, stronger post.' );
		} elseif ( 0 === $words ) {
			$issues[] = Issue::make( 'seo.empty_content', 'seo', 'high', 'Page has no text content', 'No readable text was found in the body.', 'Add descriptive text so search engines and visitors understand the page.' );
		}

		$headings = isset( $doc['headings'] ) ? $doc['headings'] : array();
		$h1       = array_filter(
			$headings,
			static function ( $h ) {
				return 1 === $h['level'];
			}
		);
		if ( count( $h1 ) > 0 ) {
			$issues[] = Issue::make( 'seo.multiple_h1', 'seo', 'low', 'Extra H1 in the body', sprintf( 'Found %d H1 heading(s) in the content; the theme already outputs the title as H1.', count( $h1 ) ), 'Change body H1s to H2 so each page has a single main heading.' );
		}
		if ( $words >= self::THIN && count( $headings ) === 0 ) {
			$issues[] = Issue::make( 'seo.no_subheadings', 'seo', 'low', 'Long text without subheadings', sprintf( '%d words with no headings make the page hard to scan.', $words ), 'Break the content into sections with descriptive H2/H3 headings.' );
		}

		$links    = isset( $doc['links'] ) ? $doc['links'] : array();
		$internal = array_filter(
			$links,
			static function ( $l ) {
				return ! empty( $l['internal'] ) && 'web' === $l['kind'];
			}
		);
		if ( $words >= 150 && count( $internal ) === 0 ) {
			$issues[] = Issue::make( 'seo.no_internal_links', 'seo', 'low', 'No internal links', 'This page doesn’t link to any other page on the site.', 'Link to 2–3 related posts or pages to help visitors and crawlers discover content.' );
		}

		$slug = isset( $doc['slug'] ) ? (string) $doc['slug'] : '';
		if ( Content_Extractor::len( $slug ) > 75 ) {
			$issues[] = Issue::make( 'seo.slug_long', 'seo', 'low', 'Very long URL slug', sprintf( 'The slug is %d characters.', Content_Extractor::len( $slug ) ), 'Use a short, readable slug (3–5 words). Add a redirect if you change a published URL.' );
		}

		if ( isset( $doc['has_featured_image'] ) && ! $doc['has_featured_image'] && 'post' === $type ) {
			$issues[] = Issue::make( 'seo.no_featured_image', 'seo', 'low', 'No featured image', 'Social shares and some search features will have no preview image.', 'Set a relevant featured image (at least 1200 px wide).' );
		}

		return $issues;
	}
}
