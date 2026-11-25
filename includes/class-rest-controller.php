<?php
/**
 * Public REST endpoints: episode list and download proxy.
 *
 * Both endpoints are deliberately anonymous and nonce-free: their responses
 * are public data, and the page that calls them is stored in a full-page cache
 * for weeks. A nonce in cached HTML would expire and break every visitor.
 *
 * @package BroadcastArchive
 */

declare( strict_types=1 );

namespace BroadcastArchive;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST controller.
 */
final class Rest_Controller {

	/**
	 * REST namespace.
	 */
	public const REST_NAMESPACE = 'broadcast-archive/v1';

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/episodes',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'get_episodes' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'refresh' => array(
						'type'              => 'boolean',
						'default'           => false,
						'sanitize_callback' => 'rest_sanitize_boolean',
						'description'       => 'Bypass the cache. Requires an editor session.',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/download',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'download' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'url'  => array(
						'required'          => true,
						'type'              => 'string',
						'format'            => 'uri',
						'sanitize_callback' => array( self::class, 'sanitize_url' ),
					),
					'name' => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Return the normalised episode payload.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_episodes( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( $request->get_param( 'refresh' ) && current_user_can( 'edit_posts' ) ) {
			Feed::clear_cache();
		}

		$payload = Feed::get();

		if ( is_wp_error( $payload ) ) {
			return new \WP_Error(
				$payload->get_error_code(),
				$payload->get_error_message(),
				array( 'status' => 503 )
			);
		}

		$response = rest_ensure_response( $payload );

		$response->header( 'Cache-Control', 'public, max-age=60, stale-while-revalidate=300' );
		$response->header( 'X-Broadcast-Archive-Cache', ! empty( $payload['stale'] ) ? 'stale' : 'fresh' );
		$response->header( 'X-Robots-Tag', 'noindex, nofollow' );

		return $response;
	}

	/**
	 * Stream an episode file through the site with a friendly filename.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_Error|void
	 */
	public static function download( \WP_REST_Request $request ) {
		if ( ! (bool) Settings::option( 'download_enabled', 1 ) ) {
			return new \WP_Error(
				'ba_download_disabled',
				__( 'Downloads are disabled.', 'broadcast-archive' ),
				array( 'status' => 403 )
			);
		}

		$url = esc_url_raw( (string) $request->get_param( 'url' ), array( 'http', 'https' ) );

		if ( '' === $url || ! self::is_allowed_url( $url ) ) {
			return new \WP_Error(
				'ba_download_forbidden',
				__( 'This file cannot be downloaded.', 'broadcast-archive' ),
				array( 'status' => 403 )
			);
		}

		$requested_name = (string) $request->get_param( 'name' );

		$temp = wp_tempnam( 'broadcast-archive-download' );

		if ( ! $temp ) {
			return new \WP_Error(
				'ba_download_temp',
				__( 'The download could not be prepared.', 'broadcast-archive' ),
				array( 'status' => 500 )
			);
		}

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'     => 300,
				'redirection' => 0,
				'sslverify'   => true,
				'stream'      => true,
				'filename'    => $temp,
				'headers'     => array(
					'User-Agent' => 'BroadcastArchive/' . BROADCAST_ARCHIVE_VERSION . '; ' . home_url( '/' ),
					'Accept'     => 'audio/*, video/mp4, application/octet-stream;q=0.8, */*;q=0.1',
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			if ( file_exists( $temp ) ) {
				wp_delete_file( $temp );
			}

			return new \WP_Error(
				'ba_download_unavailable',
				__( 'The file is temporarily unavailable. Please try again later.', 'broadcast-archive' ),
				array( 'status' => 502 )
			);
		}

		$size = file_exists( $temp ) ? (int) filesize( $temp ) : 0;

		if ( $size <= 0 ) {
			wp_delete_file( $temp );

			return new \WP_Error(
				'ba_download_empty',
				__( 'The file is empty.', 'broadcast-archive' ),
				array( 'status' => 502 )
			);
		}

		$raw_type = strtolower( trim( (string) wp_remote_retrieve_header( $response, 'content-type' ) ) );
		$raw_type = trim( explode( ';', $raw_type )[0] );

		$path_ext = strtolower( (string) pathinfo( (string) wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
		$audio_ext = in_array( $path_ext, array( 'm4a', 'm4b', 'mp3', 'aac', 'ogg', 'opus', 'mp4', 'wav', 'webm' ), true );
		$audio_type = str_starts_with( $raw_type, 'audio/' ) || 'video/mp4' === $raw_type;
		$octet_audio = in_array( $raw_type, array( 'application/octet-stream', 'binary/octet-stream', '' ), true ) && $audio_ext;

		if ( ! $audio_type && ! $octet_audio ) {
			wp_delete_file( $temp );

			return new \WP_Error(
				'ba_download_type',
				__( 'This file is not an audio broadcast.', 'broadcast-archive' ),
				array( 'status' => 415 )
			);
		}

		$type = self::safe_content_type( $raw_type );

		$filename = self::filename( $requested_name, $url, $type );

		// Send the file as-is; no REST envelope, no buffering.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		nocache_headers();

		header( 'Content-Type: ' . $type );
		header( 'Content-Disposition: attachment; filename="' . self::ascii_filename( $filename ) . '"; filename*=UTF-8\'\'' . rawurlencode( $filename ) );
		header( 'Content-Length: ' . $size );
		header( 'Content-Transfer-Encoding: binary' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Cache-Control: no-store, max-age=0' );

		readfile( $temp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile

		wp_delete_file( $temp );

		exit;
	}

	/**
	 * Sanitise the download URL argument.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_url( mixed $value ): string {
		return esc_url_raw( is_string( $value ) ? $value : '', array( 'http', 'https' ) );
	}

	/**
	 * Whether the URL may be fetched by the download proxy.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function is_allowed_url( string $url ): bool {
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		$host   = strtolower( rtrim( (string) wp_parse_url( $url, PHP_URL_HOST ), '.' ) );
		$port   = wp_parse_url( $url, PHP_URL_PORT );

		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === $host ) {
			return false;
		}

		if ( null !== $port && ! in_array( (int) $port, array( 80, 443 ), true ) ) {
			return false;
		}

		if ( str_contains( $url, '@' ) ) {
			return false;
		}

		foreach ( Settings::download_hosts() as $allowed ) {
			$allowed = strtolower( rtrim( $allowed, '.' ) );

			if ( '' !== $allowed && ( $host === $allowed || str_ends_with( $host, '.' . $allowed ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Normalise an upstream content type.
	 *
	 * @param string $type Raw content type.
	 * @return string
	 */
	private static function safe_content_type( string $type ): string {
		$type = trim( explode( ';', $type )[0] );

		$allowed = array(
			'audio/mp4',
			'audio/m4a',
			'audio/x-m4a',
			'audio/mpeg',
			'audio/mp3',
			'audio/aac',
			'audio/ogg',
			'audio/opus',
			'audio/wav',
			'audio/webm',
			'video/mp4',
			'application/octet-stream',
		);

		if ( in_array( $type, $allowed, true ) ) {
			return $type;
		}

		return 'application/octet-stream';
	}

	/**
	 * Build a readable download filename.
	 *
	 * @param string $requested Requested name.
	 * @param string $url       Source URL.
	 * @param string $type      Content type.
	 * @return string
	 */
	private static function filename( string $requested, string $url, string $type ): string {
		$extensions = array(
			'audio/mpeg'   => 'mp3',
			'audio/mp3'    => 'mp3',
			'audio/aac'    => 'aac',
			'audio/ogg'    => 'ogg',
			'audio/opus'   => 'opus',
			'audio/wav'    => 'wav',
			'audio/webm'   => 'webm',
			'video/mp4'    => 'mp4',
			'audio/mp4'    => 'm4a',
			'audio/m4a'    => 'm4a',
			'audio/x-m4a'  => 'm4a',
		);

		$extension = $extensions[ $type ] ?? '';
		$path      = (string) wp_parse_url( $url, PHP_URL_PATH );
		$url_ext   = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );

		if ( '' === $extension && in_array( $url_ext, array( 'm4a', 'm4b', 'mp3', 'aac', 'ogg', 'opus', 'mp4', 'wav', 'webm' ), true ) ) {
			$extension = $url_ext;
		}

		if ( '' === $extension ) {
			$extension = 'm4a';
		}

		$name = sanitize_file_name( $requested );

		if ( '' === $name || '.' === $name ) {
			$name = sanitize_file_name( rawurldecode( (string) pathinfo( $path, PATHINFO_FILENAME ) ) );
		}

		if ( '' === $name ) {
			$name = 'broadcast';
		}

		// Strip an existing known extension before appending the canonical one.
		$name = (string) preg_replace( '/\.(m4a|m4b|mp3|aac|ogg|opus|mp4|wav|webm)$/i', '', $name );

		return $name . '.' . $extension;
	}

	/**
	 * ASCII-safe filename for the legacy Content-Disposition parameter.
	 *
	 * @param string $filename UTF-8 filename.
	 * @return string
	 */
	private static function ascii_filename( string $filename ): string {
		$ascii = remove_accents( $filename );
		$ascii = preg_replace( '/[^A-Za-z0-9._ -]/', '_', $ascii ) ?? 'broadcast';

		return '' !== trim( $ascii, '._- ' ) ? trim( $ascii ) : 'broadcast';
	}
}
