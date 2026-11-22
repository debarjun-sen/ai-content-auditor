<?php
/**
 * Applies human-approved fixes. The only code path that writes to site content.
 *
 * Guarantees:
 *  - Only allowlisted fields (meta_description, excerpt, image_alt) can change.
 *  - The acting user must be able to edit the post (and the attachment).
 *  - Values are sanitized and length-capped; HTML is never written.
 *  - The previous value is stored for one-click undo; post edits also create a revision.
 *  - Undo refuses to clobber edits someone made after the fix.
 *
 * @package AICA
 */

namespace AICA\Safety;

use AICA\Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Fix applier.
 */
class Fix_Applier {

	/**
	 * Allowlisted fields and their max lengths.
	 *
	 * @return array<string,int>
	 */
	public static function fields() {
		return (array) apply_filters(
			'aica_fix_fields',
			array(
				'meta_description' => 320,
				'excerpt'          => 600,
				'image_alt'        => 250,
			)
		);
	}

	/**
	 * Sanitize a proposed value for a field.
	 *
	 * @param string $field Field.
	 * @param string $value Value.
	 * @return string|WP_Error
	 */
	public static function sanitize_value( $field, $value ) {
		$fields = self::fields();
		if ( ! isset( $fields[ $field ] ) ) {
			return new WP_Error( 'aica_fix_field_not_allowed', __( 'This field cannot be changed by the auditor.', 'ai-content-auditor' ), array( 'status' => 400 ) );
		}
		$value = trim( sanitize_text_field( wp_strip_all_tags( (string) $value ) ) );
		if ( '' === $value ) {
			return new WP_Error( 'aica_fix_empty', __( 'The suggested value is empty.', 'ai-content-auditor' ), array( 'status' => 400 ) );
		}
		if ( mb_strlen( $value ) > $fields[ $field ] ) {
			$value = rtrim( mb_substr( $value, 0, $fields[ $field ] - 1 ) ) . '…';
		}
		return $value;
	}

	/**
	 * Apply the fix attached to a finding.
	 *
	 * @param array $finding Finding (from Repository).
	 * @return array|WP_Error Result with before/after.
	 */
	public static function apply( array $finding ) {
		if ( 'open' !== $finding['status'] ) {
			return new WP_Error( 'aica_fix_state', __( 'Only open findings can be fixed.', 'ai-content-auditor' ), array( 'status' => 409 ) );
		}
		if ( empty( $finding['has_fix'] ) ) {
			return new WP_Error( 'aica_no_fix', __( 'This finding has no suggested fix.', 'ai-content-auditor' ), array( 'status' => 400 ) );
		}
		$post = get_post( (int) $finding['post_id'] );
		if ( ! $post ) {
			return new WP_Error( 'aica_post_missing', __( 'The post no longer exists.', 'ai-content-auditor' ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return new WP_Error( 'aica_forbidden', __( 'You are not allowed to edit this post.', 'ai-content-auditor' ), array( 'status' => 403 ) );
		}
		$value = self::sanitize_value( $finding['fix_field'], $finding['fix_value'] );
		if ( is_wp_error( $value ) ) {
			return $value;
		}

		switch ( $finding['fix_field'] ) {
			case 'meta_description':
				$result = self::apply_meta_description( $post, $value );
				break;
			case 'excerpt':
				$result = self::apply_excerpt( $post, $value );
				break;
			case 'image_alt':
				$result = self::apply_image_alt( $post, (string) $finding['fix_target'], $value );
				break;
			default:
				$result = apply_filters( 'aica_apply_custom_fix', new WP_Error( 'aica_fix_unsupported', __( 'Unsupported fix.', 'ai-content-auditor' ) ), $finding, $post, $value );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		Repository::update_finding(
			$finding['id'],
			array(
				'status'       => 'fixed',
				'fix_previous' => wp_json_encode( $result['previous'] ),
			)
		);
		Repository::log_event( $finding['scan_id'], 'fix', sprintf( 'Fix applied by %s: %s', wp_get_current_user()->user_login, $finding['title'] ), array( 'finding_id' => $finding['id'] ) );

		/**
		 * Fires after a fix is applied.
		 *
		 * @param array $finding Finding.
		 * @param array $result  Result.
		 */
		do_action( 'aica_fix_applied', $finding, $result );

		return array(
			'field' => $finding['fix_field'],
			'value' => $value,
		);
	}

	/**
	 * Undo a previously applied fix.
	 *
	 * @param array $finding Finding.
	 * @return true|WP_Error
	 */
	public static function undo( array $finding ) {
		if ( 'fixed' !== $finding['status'] ) {
			return new WP_Error( 'aica_undo_state', __( 'This finding has not been fixed.', 'ai-content-auditor' ), array( 'status' => 409 ) );
		}
		$prev = json_decode( (string) Repository::get_fix_previous( $finding['id'] ), true );
		$post = get_post( (int) $finding['post_id'] );
		if ( ! is_array( $prev ) || ! $post ) {
			return new WP_Error( 'aica_undo_missing', __( 'Nothing to undo.', 'ai-content-auditor' ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return new WP_Error( 'aica_forbidden', __( 'You are not allowed to edit this post.', 'ai-content-auditor' ), array( 'status' => 403 ) );
		}

		if ( isset( $prev['meta'] ) ) {
			foreach ( $prev['meta'] as $key => $old ) {
				if ( null === $old ) {
					delete_post_meta( $post->ID, $key );
				} else {
					update_post_meta( $post->ID, $key, wp_slash( $old ) );
				}
			}
		}
		if ( array_key_exists( 'excerpt', $prev ) || isset( $prev['content'] ) ) {
			if ( isset( $prev['after_hash'] ) && md5( $post->post_content . '|' . $post->post_excerpt ) !== $prev['after_hash'] ) {
				return new WP_Error( 'aica_undo_conflict', __( 'The post was edited after the fix. Use the post’s revisions to restore an earlier version.', 'ai-content-auditor' ), array( 'status' => 409 ) );
			}
			$update = array( 'ID' => $post->ID );
			if ( array_key_exists( 'excerpt', $prev ) ) {
				$update['post_excerpt'] = $prev['excerpt'];
			}
			if ( isset( $prev['content'] ) ) {
				$update['post_content'] = $prev['content'];
			}
			$r = wp_update_post( wp_slash( $update ), true );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
		}
		if ( isset( $prev['attachment'] ) && current_user_can( 'edit_post', (int) $prev['attachment']['id'] ) ) {
			update_post_meta( (int) $prev['attachment']['id'], '_wp_attachment_image_alt', wp_slash( (string) $prev['attachment']['alt'] ) );
		}

		Repository::update_finding(
			$finding['id'],
			array(
				'status'       => 'open',
				'fix_previous' => null,
			)
		);
		Repository::log_event( $finding['scan_id'], 'fix', sprintf( 'Fix undone by %s: %s', wp_get_current_user()->user_login, $finding['title'] ), array( 'finding_id' => $finding['id'] ) );
		return true;
	}

	/**
	 * Meta keys to write: ours plus any active SEO plugin's.
	 *
	 * @return string[]
	 */
	public static function meta_description_keys() {
		$keys = array( '_aica_meta_description' );
		if ( defined( 'WPSEO_VERSION' ) ) {
			$keys[] = '_yoast_wpseo_metadesc';
		}
		if ( class_exists( 'RankMath' ) ) {
			$keys[] = 'rank_math_description';
		}
		return $keys;
	}

	/**
	 * Write meta description.
	 *
	 * @param \WP_Post $post  Post.
	 * @param string   $value Value.
	 * @return array
	 */
	private static function apply_meta_description( $post, $value ) {
		$previous = array( 'meta' => array() );
		foreach ( self::meta_description_keys() as $key ) {
			$previous['meta'][ $key ] = metadata_exists( 'post', $post->ID, $key ) ? get_post_meta( $post->ID, $key, true ) : null;
			update_post_meta( $post->ID, $key, wp_slash( $value ) );
		}
		return array( 'previous' => $previous );
	}

	/**
	 * Write excerpt.
	 *
	 * @param \WP_Post $post  Post.
	 * @param string   $value Value.
	 * @return array|WP_Error
	 */
	private static function apply_excerpt( $post, $value ) {
		$previous = array( 'excerpt' => $post->post_excerpt );
		$r        = wp_update_post(
			wp_slash(
				array(
					'ID'           => $post->ID,
					'post_excerpt' => $value,
				)
			),
			true
		);
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$fresh                  = get_post( $post->ID );
		$previous['after_hash'] = md5( $fresh->post_content . '|' . $fresh->post_excerpt );
		return array( 'previous' => $previous );
	}

	/**
	 * Set alt text on matching <img> tags (and the attachment, when identifiable).
	 *
	 * @param \WP_Post $post   Post.
	 * @param string   $target Image src.
	 * @param string   $value  Alt text.
	 * @return array|WP_Error
	 */
	private static function apply_image_alt( $post, $target, $value ) {
		if ( '' === $target ) {
			return new WP_Error( 'aica_fix_target', __( 'No image was specified for this fix.', 'ai-content-auditor' ), array( 'status' => 400 ) );
		}
		$updated = self::set_img_alt( $post->post_content, $target, $value );
		if ( null === $updated['html'] ) {
			return new WP_Error( 'aica_fix_target', __( 'The image could not be found in the post anymore.', 'ai-content-auditor' ), array( 'status' => 404 ) );
		}

		$previous = array( 'content' => $post->post_content );
		$r        = wp_update_post(
			wp_slash(
				array(
					'ID'           => $post->ID,
					'post_content' => $updated['html'],
				)
			),
			true
		);
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$fresh                  = get_post( $post->ID );
		$previous['after_hash'] = md5( $fresh->post_content . '|' . $fresh->post_excerpt );

		if ( $updated['attachment_id'] && current_user_can( 'edit_post', $updated['attachment_id'] ) && 'attachment' === get_post_type( $updated['attachment_id'] ) ) {
			$previous['attachment'] = array(
				'id'  => $updated['attachment_id'],
				'alt' => (string) get_post_meta( $updated['attachment_id'], '_wp_attachment_image_alt', true ),
			);
			update_post_meta( $updated['attachment_id'], '_wp_attachment_image_alt', wp_slash( $value ) );
		}
		return array( 'previous' => $previous );
	}

	/**
	 * Pure helper: set alt on <img> tags whose src matches. Uses WP_HTML_Tag_Processor
	 * so block markup and other attributes are preserved byte-for-byte.
	 *
	 * @param string $html   HTML.
	 * @param string $target src to match (exact, or same file name).
	 * @param string $alt    Alt text.
	 * @return array{html:?string,attachment_id:int}
	 */
	public static function set_img_alt( $html, $target, $alt ) {
		if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return array(
				'html'          => null,
				'attachment_id' => 0,
			);
		}
		$matched = false;
		$att_id  = 0;
		foreach ( array( 'exact', 'basename' ) as $mode ) {
			$p = new \WP_HTML_Tag_Processor( $html );
			while ( $p->next_tag( 'img' ) ) {
				$src = (string) $p->get_attribute( 'src' );
				$hit = 'exact' === $mode ? $src === $target : ( '' !== $src && basename( (string) wp_parse_url( $src, PHP_URL_PATH ) ) === basename( (string) wp_parse_url( $target, PHP_URL_PATH ) ) );
				if ( $hit ) {
					$p->set_attribute( 'alt', $alt );
					$matched = true;
					if ( preg_match( '/wp-image-(\d+)/', (string) $p->get_attribute( 'class' ), $m ) ) {
						$att_id = (int) $m[1];
					}
				}
			}
			if ( $matched ) {
				return array(
					'html'          => $p->get_updated_html(),
					'attachment_id' => $att_id,
				);
			}
		}
		return array(
			'html'          => null,
			'attachment_id' => 0,
		);
	}
}
