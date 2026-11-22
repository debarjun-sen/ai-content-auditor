<?php
/**
 * Deterministic accessibility checks on stored content markup (WCAG 2.2 oriented).
 *
 * @package AICA
 */

namespace AICA\Analyzers;

defined( 'ABSPATH' ) || exit;

/**
 * Accessibility analyzer.
 */
class Accessibility_Analyzer {

	/**
	 * Link texts that say nothing out of context (WCAG 2.4.4).
	 *
	 * @var string[]
	 */
	const VAGUE_LINKS = array( 'click here', 'here', 'read more', 'more', 'link', 'this', 'this link', 'learn more', 'continue', 'click', 'go', 'details', 'more info' );

	/**
	 * Run checks.
	 *
	 * @param array $doc Document.
	 * @return array[] Issues.
	 */
	public static function analyze( array $doc ) {
		$issues = array();

		// Images.
		$missing = array();
		$generic = array();
		foreach ( isset( $doc['images'] ) ? $doc['images'] : array() as $img ) {
			if ( ! empty( $img['decorative'] ) ) {
				continue;
			}
			if ( null === $img['alt'] ) {
				$missing[] = $img;
			} elseif ( '' !== $img['alt'] && self::is_generic_alt( $img['alt'] ) ) {
				$generic[] = $img;
			}
		}
		foreach ( $missing as $img ) {
			$issues[] = Issue::make(
				'a11y.img_missing_alt',
				'accessibility',
				'high',
				'Image has no alt attribute',
				sprintf( 'Screen readers will announce the file name for %s.', self::basename( $img['src'] ) ),
				'Describe what the image shows and why it matters, or use alt="" if it is purely decorative.',
				array(
					'field'  => 'image_alt',
					'target' => $img['src'],
				),
				'1.1.1'
			);
		}
		foreach ( $generic as $img ) {
			$issues[] = Issue::make(
				'a11y.img_generic_alt',
				'accessibility',
				'medium',
				'Alt text is not descriptive',
				sprintf( 'Alt text “%s” on %s doesn’t describe the image.', Issue::excerpt( $img['alt'], 60 ), self::basename( $img['src'] ) ),
				'Replace it with a short description of the image content and purpose.',
				array(
					'field'  => 'image_alt',
					'target' => $img['src'],
				),
				'1.1.1'
			);
		}

		// Heading structure.
		$prev = 1;
		foreach ( isset( $doc['headings'] ) ? $doc['headings'] : array() as $h ) {
			if ( '' === $h['text'] ) {
				$issues[] = Issue::make( 'a11y.empty_heading', 'accessibility', 'medium', 'Empty heading', sprintf( 'An H%d heading has no text.', $h['level'] ), 'Remove the empty heading or give it meaningful text.', null, '2.4.6' );
				continue;
			}
			if ( $h['level'] > $prev + 1 ) {
				$issues[] = Issue::make(
					'a11y.heading_skip',
					'accessibility',
					'medium',
					'Heading levels skip',
					sprintf( '“%s” jumps from H%d to H%d.', Issue::excerpt( $h['text'], 60 ), $prev, $h['level'] ),
					sprintf( 'Use H%d here so assistive-tech users can follow the outline.', $prev + 1 ),
					null,
					'1.3.1'
				);
			}
			$prev = $h['level'];
		}

		// Links.
		$vague_seen = array();
		foreach ( isset( $doc['links'] ) ? $doc['links'] : array() as $l ) {
			$name = trim( $l['text'] . ' ' . $l['aria_label'] . ' ' . $l['image_alt'] );
			if ( '' === $name ) {
				$issues[] = Issue::make( 'a11y.empty_link', 'accessibility', 'high', 'Link has no accessible name', sprintf( 'The link to %s has no text, label or image alt.', Issue::excerpt( $l['href'], 60 ) ), 'Add visible link text, or alt text on the linked image describing the destination.', null, '2.4.4' );
				continue;
			}
			$normalized = strtolower( trim( preg_replace( '/[^\p{L}\p{N} ]+/u', '', $l['text'] ) ) );
			if ( '' === trim( $l['aria_label'] ) && in_array( $normalized, self::VAGUE_LINKS, true ) && ! isset( $vague_seen[ $normalized ] ) ) {
				$vague_seen[ $normalized ] = true;
				$issues[]                  = Issue::make( 'a11y.vague_link_text', 'accessibility', 'medium', 'Vague link text', sprintf( 'Link text “%s” doesn’t say where it goes (%s).', $l['text'], Issue::excerpt( $l['href'], 50 ) ), 'Rewrite the link to describe its destination, e.g. “Download the 2025 pricing guide”.', null, '2.4.4' );
			}
		}

		// Tables.
		foreach ( isset( $doc['tables'] ) ? $doc['tables'] : array() as $i => $t ) {
			if ( ! $t['has_th'] && $t['rows'] > 1 ) {
				$issues[] = Issue::make( 'a11y.table_no_headers', 'accessibility', 'medium', 'Data table has no header cells', sprintf( 'Table #%d has %d rows but no <th> cells.', $i + 1, $t['rows'] ), 'Turn on “Header section” in the Table block so cells are announced with their column.', null, '1.3.1' );
			}
		}

		// Embeds.
		foreach ( isset( $doc['iframes'] ) ? $doc['iframes'] : array() as $f ) {
			if ( '' === $f['title'] ) {
				$issues[] = Issue::make( 'a11y.iframe_no_title', 'accessibility', 'medium', 'Embedded frame has no title', sprintf( 'The iframe from %s has no title attribute.', Issue::excerpt( (string) parse_url( $f['src'], PHP_URL_HOST ), 50 ) ), 'Add a title describing the embed, e.g. title="Map of our office".', null, '4.1.2' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
			}
		}
		foreach ( isset( $doc['videos'] ) ? $doc['videos'] : array() as $v ) {
			if ( $v['autoplay'] ) {
				$issues[] = Issue::make( 'a11y.video_autoplay', 'accessibility', 'medium', 'Video autoplays', 'Autoplaying media can disorient users and interfere with screen readers.', 'Turn off autoplay or make sure the video is muted and has a visible pause control.', null, '2.2.2' );
			}
			if ( 0 === $v['tracks'] ) {
				$issues[] = Issue::make( 'a11y.video_no_captions', 'accessibility', 'medium', 'Video has no captions track', 'No <track> element was found for captions or subtitles.', 'Upload a WebVTT captions file in the Video block’s “Text tracks” setting.', null, '1.2.2' );
			}
		}

		// Featured image alt.
		if ( ! empty( $doc['has_featured_image'] ) && isset( $doc['featured_image_alt'] ) && '' === trim( (string) $doc['featured_image_alt'] ) ) {
			$issues[] = Issue::make( 'a11y.featured_no_alt', 'accessibility', 'low', 'Featured image has no alt text', 'Themes that show the featured image will output it without a description.', 'Add alt text to the featured image in the Media Library.', null, '1.1.1' );
		}

		return $issues;
	}

	/**
	 * Is this alt text a filename or placeholder?
	 *
	 * @param string $alt Alt text.
	 * @return bool
	 */
	public static function is_generic_alt( $alt ) {
		$a = strtolower( trim( $alt ) );
		if ( preg_match( '/\.(jpe?g|png|gif|webp|avif|svg)$/i', $a ) ) {
			return true;
		}
		if ( preg_match( '/^(img|image|dsc|pxl|screenshot|photo)[\s_-]*\d+/i', $a ) ) {
			return true;
		}
		return in_array( $a, array( 'image', 'img', 'photo', 'picture', 'graphic', 'logo', 'icon', 'banner', 'untitled', 'placeholder', 'alt' ), true );
	}

	/**
	 * File name for messages.
	 *
	 * @param string $src URL.
	 * @return string
	 */
	private static function basename( $src ) {
		$path = (string) parse_url( (string) $src, PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		$base = basename( $path );
		return '' !== $base ? $base : 'an image';
	}
}
