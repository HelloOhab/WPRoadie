<?php
/**
 * Signs and verifies HQ requests (docs/hq-protocol.md, "Signing").
 *
 * @package WPRoadie
 */

namespace WPRoadie\Hq;

defined( 'ABSPATH' ) || exit;

final class Signer {

	const VERSION      = 'v1';
	const MAX_SKEW     = 300;
	const NONCE_WINDOW = 600;

	const HEADER_SITE      = 'X-WPRoadie-Site';
	const HEADER_TIMESTAMP = 'X-WPRoadie-Timestamp';
	const HEADER_NONCE     = 'X-WPRoadie-Nonce';
	const HEADER_SIGNATURE = 'X-WPRoadie-Signature';

	/**
	 * The signature for a request, as sent in the signature header.
	 *
	 * @param string $key       Raw 32-byte key.
	 * @param string $purpose   "heartbeat" or "command".
	 * @param string $site_id   Site ID.
	 * @param string $timestamp Unix seconds.
	 * @param string $nonce     32 lowercase hex characters.
	 * @param string $body      Exact request body.
	 */
	public static function sign( string $key, string $purpose, string $site_id, string $timestamp, string $nonce, string $body ): string {
		$message = implode( "\n", array( self::VERSION, $purpose, $site_id, $timestamp, $nonce, hash( 'sha256', $body ) ) );
		return self::VERSION . '=' . hash_hmac( 'sha256', $message, $key );
	}

	/**
	 * Headers for an outgoing signed request.
	 *
	 * @return array<string, string>
	 */
	public static function headers( string $key, string $purpose, string $site_id, string $body ): array {
		$timestamp = (string) time();
		$nonce     = bin2hex( random_bytes( 16 ) );
		return array(
			'Content-Type'         => 'application/json',
			self::HEADER_SITE      => $site_id,
			self::HEADER_TIMESTAMP => $timestamp,
			self::HEADER_NONCE     => $nonce,
			self::HEADER_SIGNATURE => self::sign( $key, $purpose, $site_id, $timestamp, $nonce, $body ),
		);
	}

	/**
	 * Verifies an incoming signed request and records its nonce.
	 *
	 * @param string $purpose   Expected purpose.
	 * @param string $site_id   Header value.
	 * @param string $timestamp Header value.
	 * @param string $nonce     Header value.
	 * @param string $signature Header value.
	 * @param string $body      Raw body.
	 * @return true|string True, or a protocol error code.
	 */
	public static function verify( string $purpose, string $site_id, string $timestamp, string $nonce, string $signature, string $body ) {
		$config = Connection::get();
		$key    = Connection::key();
		if ( ! $config || null === $key || ! hash_equals( $config['site_id'], strtolower( $site_id ) ) ) {
			return 'unknown_site';
		}
		if ( ! ctype_digit( $timestamp ) || abs( time() - (int) $timestamp ) > self::MAX_SKEW ) {
			return 'stale';
		}
		if ( ! preg_match( '/^[0-9a-f]{32}$/', $nonce ) ) {
			return 'bad_signature';
		}

		$expected = self::sign( $key, $purpose, $config['site_id'], $timestamp, $nonce, $body );
		if ( ! hash_equals( $expected, $signature ) ) {
			return 'bad_signature';
		}

		// Checked after the signature, so unsigned junk cannot fill the cache.
		$nonce_key = 'wproadie_hq_n_' . $nonce;
		if ( false !== get_transient( $nonce_key ) ) {
			return 'replayed';
		}
		set_transient( $nonce_key, 1, self::NONCE_WINDOW );

		return true;
	}
}
