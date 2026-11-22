<?php
/**
 * API key storage. The key is never sent to the browser.
 *
 * Resolution order: AICA_ANTHROPIC_API_KEY constant → ANTHROPIC_API_KEY env var
 * → encrypted option. Encryption uses AES-256-GCM keyed from the site's salts so
 * a leaked database dump alone does not reveal the key.
 *
 * @package AICA
 */

namespace AICA;

defined( 'ABSPATH' ) || exit;

/**
 * Secret storage helper.
 */
class Secrets {

	const OPTION = 'aica_api_key';

	/**
	 * Where the active key comes from: constant|env|option|none.
	 *
	 * @return string
	 */
	public static function source() {
		if ( defined( 'AICA_ANTHROPIC_API_KEY' ) && AICA_ANTHROPIC_API_KEY ) {
			return 'constant';
		}
		if ( getenv( 'ANTHROPIC_API_KEY' ) ) {
			return 'env';
		}
		return get_option( self::OPTION ) ? 'option' : 'none';
	}

	/**
	 * Whether any key is configured.
	 *
	 * @return bool
	 */
	public static function has_key() {
		return 'none' !== self::source();
	}

	/**
	 * Return the plaintext API key or ''.
	 *
	 * @return string
	 */
	public static function get_key() {
		switch ( self::source() ) {
			case 'constant':
				return (string) AICA_ANTHROPIC_API_KEY;
			case 'env':
				return (string) getenv( 'ANTHROPIC_API_KEY' );
			case 'option':
				return self::decrypt( (string) get_option( self::OPTION ) );
		}
		return '';
	}

	/**
	 * Store (or clear, with '') the key.
	 *
	 * @param string $key Plaintext key.
	 */
	public static function set_key( $key ) {
		$key = trim( (string) $key );
		if ( '' === $key ) {
			delete_option( self::OPTION );
			return;
		}
		update_option( self::OPTION, self::encrypt( $key ), false );
	}

	/**
	 * Masked representation for display, e.g. "sk-ant-…a1b2".
	 *
	 * @return string
	 */
	public static function masked() {
		$key = self::get_key();
		if ( strlen( $key ) < 12 ) {
			return $key ? '••••' : '';
		}
		return substr( $key, 0, 7 ) . '…' . substr( $key, -4 );
	}

	/**
	 * Derive a 32-byte key from the site's salts.
	 *
	 * @return string
	 */
	private static function crypto_key() {
		$salt = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) . ( defined( 'SECURE_AUTH_SALT' ) ? SECURE_AUTH_SALT : '' );
		if ( '' === $salt && function_exists( 'wp_salt' ) ) {
			$salt = wp_salt( 'auth' );
		}
		return hash( 'sha256', 'aica|' . $salt, true );
	}

	/**
	 * Encrypt a value. Falls back to a tagged base64 value when OpenSSL is missing.
	 *
	 * @param string $plain Plaintext.
	 * @return string
	 */
	public static function encrypt( $plain ) {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return 'b64:' . base64_encode( $plain ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}
		$iv     = random_bytes( 12 );
		$tag    = '';
		$cipher = openssl_encrypt( $plain, 'aes-256-gcm', self::crypto_key(), OPENSSL_RAW_DATA, $iv, $tag );
		return 'gcm:' . base64_encode( $iv . $tag . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypt a value produced by encrypt().
	 *
	 * @param string $stored Stored value.
	 * @return string Plaintext or '' on failure.
	 */
	public static function decrypt( $stored ) {
		if ( 0 === strpos( $stored, 'b64:' ) ) {
			return (string) base64_decode( substr( $stored, 4 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		}
		if ( 0 !== strpos( $stored, 'gcm:' ) || ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}
		$raw = base64_decode( substr( $stored, 4 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) < 29 ) {
			return '';
		}
		$plain = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', self::crypto_key(), OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
		return false === $plain ? '' : $plain;
	}
}
