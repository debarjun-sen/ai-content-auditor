<?php
/**
 * Turns post HTML into a structured document the analyzers (and Claude) can reason about.
 *
 * @package AICA
 */

namespace AICA\Analyzers;

defined( 'ABSPATH' ) || exit;

// DOMDocument exposes camelCase properties (parentNode, nodeName, textContent) that we cannot rename.
// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

/**
 * Content extractor. `from_html()` is pure; `from_post()` adds WordPress context.
 */
class Content_Extractor {

	/**
	 * Parse HTML.
	 *
	 * @param string $html      Post HTML (already rendered or raw post_content).
	 * @param string $site_host Host used to classify internal links.
	 * @return array{text:string,word_count:int,headings:array,images:array,links:array,tables:array,iframes:array,videos:array}
	 */
	public static function from_html( $html, $site_host = '' ) {
		$doc  = array(
			'text'       => '',
			'word_count' => 0,
			'headings'   => array(),
			'images'     => array(),
			'links'      => array(),
			'tables'     => array(),
			'iframes'    => array(),
			'videos'     => array(),
		);
		$html = (string) $html;
		if ( '' === trim( $html ) ) {
			return $doc;
		}
		// Put block boundaries on their own lines so text extraction keeps word breaks.
		$html = preg_replace( '#</(p|h[1-6]|li|div|td|th|tr|figcaption|blockquote)>#i', "</$1>\n", $html );
		$html = preg_replace( '#<br\s*/?>#i', "\n", $html );

		if ( ! class_exists( 'DOMDocument' ) ) {
			// php-xml missing: degrade to text-only analysis rather than fatal.
			$doc['text']       = self::clean( wp_strip_all_tags( $html ) );
			$doc['word_count'] = self::word_count( $doc['text'] );
			return $doc;
		}

		$dom      = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8" ?><div id="aica-root">' . $html . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$xpath = new \DOMXPath( $dom );
		$root  = $xpath->query( '//*[@id="aica-root"]' )->item( 0 );
		if ( ! $root ) {
			return $doc;
		}

		// Drop non-content nodes before reading text.
		foreach ( iterator_to_array( $xpath->query( './/script|.//style|.//noscript', $root ) ) as $n ) {
			$n->parentNode->removeChild( $n );
		}

		foreach ( $xpath->query( './/h1|.//h2|.//h3|.//h4|.//h5|.//h6', $root ) as $h ) {
			$doc['headings'][] = array(
				'level' => (int) substr( $h->nodeName, 1 ),
				'text'  => self::clean( $h->textContent ),
			);
		}

		foreach ( $xpath->query( './/img', $root ) as $img ) {
			$class = (string) $img->getAttribute( 'class' );
			$id    = 0;
			if ( preg_match( '/wp-image-(\d+)/', $class, $m ) ) {
				$id = (int) $m[1];
			}
			$in_link = false;
			for ( $p = $img->parentNode; $p && $p !== $root; $p = $p->parentNode ) {
				if ( 'a' === $p->nodeName ) {
					$in_link = true;
					break;
				}
			}
			$doc['images'][] = array(
				'src'           => (string) $img->getAttribute( 'src' ),
				'alt'           => $img->hasAttribute( 'alt' ) ? (string) $img->getAttribute( 'alt' ) : null,
				'attachment_id' => $id,
				'in_link'       => $in_link,
				'decorative'    => 'presentation' === $img->getAttribute( 'role' ) || 'true' === $img->getAttribute( 'aria-hidden' ),
			);
		}

		foreach ( $xpath->query( './/a[@href]', $root ) as $a ) {
			$href = trim( (string) $a->getAttribute( 'href' ) );
			$text = self::clean( $a->textContent );
			$alts = array();
			foreach ( $a->getElementsByTagName( 'img' ) as $img ) {
				$alts[] = trim( (string) $img->getAttribute( 'alt' ) );
			}
			$host           = (string) parse_url( $href, PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
			$scheme         = strtolower( (string) parse_url( $href, PHP_URL_SCHEME ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
			$internal       = ( '' === $host && '' === $scheme && '#' !== substr( $href, 0, 1 ) ) || ( '' !== $site_host && strtolower( $host ) === strtolower( $site_host ) );
			$doc['links'][] = array(
				'href'       => $href,
				'text'       => $text,
				'aria_label' => trim( (string) $a->getAttribute( 'aria-label' ) . ' ' . (string) $a->getAttribute( 'title' ) ),
				'image_alt'  => trim( implode( ' ', $alts ) ),
				'internal'   => $internal,
				'kind'       => in_array( $scheme, array( 'mailto', 'tel' ), true ) ? $scheme : ( '#' === substr( $href, 0, 1 ) ? 'anchor' : 'web' ),
			);
		}

		foreach ( $xpath->query( './/table', $root ) as $t ) {
			$doc['tables'][] = array(
				'has_th'      => $xpath->query( './/th', $t )->length > 0,
				'has_caption' => $xpath->query( './/caption', $t )->length > 0,
				'rows'        => $xpath->query( './/tr', $t )->length,
			);
		}

		foreach ( $xpath->query( './/iframe', $root ) as $f ) {
			$doc['iframes'][] = array(
				'src'   => (string) $f->getAttribute( 'src' ),
				'title' => trim( (string) $f->getAttribute( 'title' ) ),
			);
		}

		foreach ( $xpath->query( './/video', $root ) as $v ) {
			$doc['videos'][] = array(
				'autoplay' => $v->hasAttribute( 'autoplay' ),
				'controls' => $v->hasAttribute( 'controls' ),
				'tracks'   => $v->getElementsByTagName( 'track' )->length,
			);
		}

		$text              = self::clean( $root->textContent );
		$doc['text']       = $text;
		$doc['word_count'] = self::word_count( $text );
		return $doc;
	}

	/**
	 * Build a full document for a post.
	 *
	 * @param \WP_Post $post Post.
	 * @return array
	 */
	public static function from_post( \WP_Post $post ) {
		$raw  = (string) $post->post_content;
		$html = function_exists( 'do_blocks' ) ? do_blocks( $raw ) : $raw;
		$html = function_exists( 'do_shortcode' ) ? do_shortcode( $html ) : $html;

		$doc = self::from_html( $html, (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

		$thumb_id = (int) get_post_thumbnail_id( $post );
		return array_merge(
			array(
				'id'                 => (int) $post->ID,
				'type'               => $post->post_type,
				'status'             => $post->post_status,
				'title'              => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
				'slug'               => $post->post_name,
				'url'                => get_permalink( $post ),
				'excerpt'            => (string) $post->post_excerpt,
				'meta_description'   => self::meta_description( $post->ID ),
				'published'          => $post->post_date_gmt,
				'modified'           => $post->post_modified_gmt,
				'has_featured_image' => $thumb_id > 0,
				'featured_image_alt' => $thumb_id ? (string) get_post_meta( $thumb_id, '_wp_attachment_image_alt', true ) : null,
				'raw_content'        => $raw,
			),
			$doc
		);
	}

	/**
	 * Read a meta description from this plugin or popular SEO plugins.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function meta_description( $post_id ) {
		foreach ( array( '_aica_meta_description', '_yoast_wpseo_metadesc', 'rank_math_description', '_aioseo_description', '_seopress_titles_desc' ) as $key ) {
			$v = trim( (string) get_post_meta( $post_id, $key, true ) );
			if ( '' !== $v ) {
				return $v;
			}
		}
		return '';
	}

	/**
	 * Normalize whitespace.
	 *
	 * @param string $s Text.
	 * @return string
	 */
	public static function clean( $s ) {
		$s = html_entity_decode( (string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$s = preg_replace( '/[\s\x{00A0}]+/u', ' ', $s );
		return trim( (string) $s );
	}

	/**
	 * Unicode-aware word count.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	public static function word_count( $text ) {
		if ( '' === trim( $text ) ) {
			return 0;
		}
		return (int) preg_match_all( '/[\p{L}\p{N}][\p{L}\p{N}\'’-]*/u', $text );
	}

	/**
	 * Multibyte-safe length.
	 *
	 * @param string $s String.
	 * @return int
	 */
	public static function len( $s ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $s, 'UTF-8' ) : strlen( (string) $s );
	}
}
