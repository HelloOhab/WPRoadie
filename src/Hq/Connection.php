<?php
/**
 * The site's link to WPRoadie HQ: URL, site ID and shared secret.
 *
 * See docs/hq-protocol.md for the protocol both sides implement.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Hq;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Connection {

	const OPTION        = 'wproadie_hq';
	const STRING_PREFIX = 'wproadie-hq:v1:';

	/**
	 * Whether constants in wp-config.php define the connection. They take
	 * precedence over the stored option.
	 */
	public static function from_constants(): bool {
		return defined( 'WPROADIE_HQ_URL' ) && defined( 'WPROADIE_HQ_SITE_ID' ) && defined( 'WPROADIE_HQ_SECRET' );
	}

	/**
	 * @return array{url: string, site_id: string, secret: string}|null Secret is base64url.
	 */
	public static function get(): ?array {
		if ( self::from_constants() ) {
			$config = array(
				'url'     => (string) WPROADIE_HQ_URL,
				'site_id' => (string) WPROADIE_HQ_SITE_ID,
				'secret'  => (string) WPROADIE_HQ_SECRET,
			);
		} else {
			$stored = get_option( self::OPTION );
			if ( ! is_array( $stored ) ) {
				return null;
			}
			$config = array(
				'url'     => (string) ( $stored['url'] ?? '' ),
				'site_id' => (string) ( $stored['site_id'] ?? '' ),
				'secret'  => (string) ( $stored['secret'] ?? '' ),
			);
		}

		return is_wp_error( self::validate( $config ) ) ? null : $config;
	}

	public static function is_connected(): bool {
		return null !== self::get();
	}

	/**
	 * The raw 32-byte HMAC key.
	 */
	public static function key(): ?string {
		$config = self::get();
		return $config ? self::decode_secret( $config['secret'] ) : null;
	}

	/**
	 * Parses a connection string from HQ.
	 *
	 * @return array{url: string, site_id: string, secret: string}|WP_Error
	 */
	public static function parse( string $connection_string ) {
		$connection_string = trim( $connection_string );
		if ( 0 !== strpos( $connection_string, self::STRING_PREFIX ) ) {
			return self::error( __( 'That is not a WPRoadie HQ connection string. Copy it again from HQ.', 'wproadie' ) );
		}

		$json = self::base64url_decode( substr( $connection_string, strlen( self::STRING_PREFIX ) ) );
		$data = is_string( $json ) ? json_decode( $json, true ) : null;
		if ( ! is_array( $data ) ) {
			return self::error( __( 'The connection string is damaged. Copy it again from HQ.', 'wproadie' ) );
		}

		$config = array(
			'url'     => untrailingslashit( (string) ( $data['url'] ?? '' ) ),
			'site_id' => strtolower( (string) ( $data['site_id'] ?? '' ) ),
			'secret'  => (string) ( $data['secret'] ?? '' ),
		);

		$valid = self::validate( $config );
		return is_wp_error( $valid ) ? $valid : $config;
	}

	/**
	 * Stores a connection from a connection string.
	 *
	 * @return array|WP_Error The stored config.
	 */
	public static function connect( string $connection_string ) {
		if ( self::from_constants() ) {
			return self::error( __( 'HQ is configured with constants in wp-config.php; change it there.', 'wproadie' ) );
		}
		$config = self::parse( $connection_string );
		if ( is_wp_error( $config ) ) {
			return $config;
		}
		update_option( self::OPTION, $config, false );
		return $config;
	}

	public static function disconnect(): void {
		Heartbeat::goodbye( 'disconnected' );
		delete_option( self::OPTION );
	}

	/**
	 * @param array $config Candidate config.
	 * @return true|WP_Error
	 */
	private static function validate( array $config ) {
		$url    = (string) ( $config['url'] ?? '' );
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		$host   = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( '' === $host || ( 'https' !== $scheme && ! ( 'http' === $scheme && self::allow_http() ) ) ) {
			return self::error( __( 'The HQ address must be an https:// URL.', 'wproadie' ) );
		}
		if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', (string) ( $config['site_id'] ?? '' ) ) ) {
			return self::error( __( 'The site ID in the connection string is not valid.', 'wproadie' ) );
		}
		$key = self::decode_secret( (string) ( $config['secret'] ?? '' ) );
		if ( null === $key ) {
			return self::error( __( 'The secret in the connection string is not valid.', 'wproadie' ) );
		}
		return true;
	}

	/**
	 * Checks and tidies an HQ address typed by a person: adds https:// when
	 * no scheme is given, drops any query, fragment and trailing slash.
	 *
	 * @return string|WP_Error
	 */
	public static function normalize_url( string $url ) {
		$url = trim( $url );
		if ( '' !== $url && ! preg_match( '#^[a-z][a-z0-9+.-]*://#i', $url ) ) {
			$url = 'https://' . $url;
		}
		$parts  = wp_parse_url( $url );
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = strtolower( (string) ( $parts['host'] ?? '' ) );
		$path   = untrailingslashit( (string) ( $parts['path'] ?? '' ) );
		$valid  = is_array( $parts ) && ! isset( $parts['user'] ) && ! isset( $parts['pass'] )
			&& preg_match( '/^(\[[0-9a-f:.]+\]|[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*)$/', $host )
			&& preg_match( '#^[A-Za-z0-9._~%/-]*$#', $path );
		if ( ! $valid ) {
			return self::error( __( 'That does not look like a web address. Enter HQ\'s address, e.g. https://hq.example.com.', 'wproadie' ) );
		}
		if ( 'https' !== $scheme && ! ( 'http' === $scheme && self::allow_http() ) ) {
			return self::error( __( 'The HQ address must be an https:// URL.', 'wproadie' ) );
		}
		$port = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		return $scheme . '://' . $host . $port . $path;
	}

	/**
	 * A URL's origin (scheme, host and port, default ports left out), for
	 * comparing two addresses. Empty if the URL has no host.
	 */
	public static function origin( string $url ): string {
		$parts  = wp_parse_url( trim( $url ) );
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = strtolower( (string) ( $parts['host'] ?? '' ) );
		if ( ! is_array( $parts ) || '' === $host ) {
			return '';
		}
		$port    = isset( $parts['port'] ) ? (int) $parts['port'] : 0;
		$default = 'https' === $scheme ? 443 : ( 'http' === $scheme ? 80 : 0 );
		return $scheme . '://' . $host . ( $port && $port !== $default ? ':' . $port : '' );
	}

	/**
	 * Plain-HTTP HQ addresses, for local development only.
	 */
	public static function allow_http(): bool {
		return 'local' === wp_get_environment_type() || (bool) apply_filters( 'wproadie_hq_allow_http', false );
	}

	private static function decode_secret( string $secret ): ?string {
		$raw = self::base64url_decode( $secret );
		return ( is_string( $raw ) && 32 === strlen( $raw ) ) ? $raw : null;
	}

	/**
	 * @return string|false
	 */
	private static function base64url_decode( string $value ) {
		if ( '' === $value || preg_match( '/[^A-Za-z0-9_-]/', $value ) ) {
			return false;
		}
		$padded  = strtr( $value, '-_', '+/' );
		$padded .= str_repeat( '=', ( 4 - strlen( $padded ) % 4 ) % 4 );
		return base64_decode( $padded, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Protocol encoding.
	}

	/**
	 * Base64url without padding, as the protocol encodes.
	 */
	public static function base64url_encode( string $bytes ): string {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Protocol encoding.
	}

	private static function error( string $message ): WP_Error {
		return new WP_Error( 'wproadie_hq_invalid', $message, array( 'status' => 400 ) );
	}
}
