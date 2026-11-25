<?php
/**
 * Minimal WordPress stubs so the parser can be tested without WordPress.
 *
 * @package BroadcastArchive
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
define( 'BROADCAST_ARCHIVE_VERSION', '2.0.0-test' );
define( 'BROADCAST_ARCHIVE_FILE', dirname( __DIR__ ) . '/broadcast-archive.php' );
define( 'BROADCAST_ARCHIVE_DIR', dirname( __DIR__ ) . '/' );
define( 'BROADCAST_ARCHIVE_URL', 'https://example.test/wp-content/plugins/broadcast-archive/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

if ( ! function_exists( '__' ) ) {
	/**
	 * Translation stub.
	 *
	 * @param string $text   Text.
	 * @param string $domain Domain.
	 * @return string
	 */
	function __( string $text, string $domain = 'default' ): string { // phpcs:ignore
		return $text;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	/**
	 * Escaped translation stub.
	 *
	 * @param string $text   Text.
	 * @param string $domain Domain.
	 * @return string
	 */
	function esc_html__( string $text, string $domain = 'default' ): string { // phpcs:ignore
		return $text;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Filter stub: returns the value unchanged.
	 *
	 * @param string $hook  Hook.
	 * @param mixed  $value Value.
	 * @return mixed
	 */
	function apply_filters( string $hook, mixed $value ): mixed { // phpcs:ignore
		return $value;
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	/**
	 * WordPress-compatible tag stripper.
	 *
	 * @param string $text           Text.
	 * @param bool   $remove_breaks  Collapse whitespace.
	 * @return string
	 */
	function wp_strip_all_tags( string $text, bool $remove_breaks = false ): string { // phpcs:ignore
		$text = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $text ) ?? '';
		$text = strip_tags( $text );

		if ( $remove_breaks ) {
			$text = preg_replace( '/[\r\n\t ]+/', ' ', $text ) ?? '';
		}

		return trim( $text );
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	/**
	 * parse_url wrapper.
	 *
	 * @param string $url       URL.
	 * @param int    $component Component.
	 * @return mixed
	 */
	function wp_parse_url( string $url, int $component = -1 ): mixed { // phpcs:ignore
		return -1 === $component ? parse_url( $url ) : parse_url( $url, $component );
	}
}

require_once BROADCAST_ARCHIVE_DIR . 'includes/class-episode.php';
require_once BROADCAST_ARCHIVE_DIR . 'includes/class-feed.php';
