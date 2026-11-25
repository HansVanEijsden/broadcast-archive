<?php
/**
 * Feed fetching, caching and parsing.
 *
 * @package BroadcastArchive
 */

declare( strict_types=1 );

namespace BroadcastArchive;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns a podcast/archive feed into a cached, normalised payload.
 */
final class Feed {

	/**
	 * Option holding ETag/Last-Modified and last successful fetch details.
	 */
	private const META_OPTION = 'broadcast_archive_feed_meta';

	/**
	 * How long the last good payload is kept for outages.
	 */
	private const STALE_TTL = 7 * DAY_IN_SECONDS;

	/**
	 * Maximum accepted feed size (bytes).
	 */
	private const MAX_FEED_BYTES = 8 * 1024 * 1024;

	/**
	 * Get the payload, from cache when possible.
	 *
	 * A stale payload is served while the archive is unreachable, so visitors
	 * never see an error just because the archive host hiccups.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function get(): array|\WP_Error {
		$cache_key = self::cache_key();
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) && ! empty( $cached['items'] ) ) {
			$cached['stale'] = false;
			return $cached;
		}

		$lock_key = 'ba_feed_lock_' . md5( self::feed_url() );

		if ( get_transient( $lock_key ) ) {
			$stale = get_transient( self::last_good_key() );

			if ( is_array( $stale ) && ! empty( $stale['items'] ) ) {
				$stale['stale'] = true;
				return $stale;
			}
		}

		set_transient( $lock_key, 1, 30 );

		$result = self::refresh();

		delete_transient( $lock_key );

		return $result;
	}

	/**
	 * Fetch, parse and cache the feed.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function refresh(): array|\WP_Error {
		$feed_url = self::feed_url();

		if ( '' === $feed_url ) {
			return new \WP_Error(
				'ba_no_feed',
				__( 'No archive feed has been configured yet.', 'broadcast-archive' ),
				array( 'status' => 503 )
			);
		}

		$meta    = self::meta();
		$headers = array(
			'Accept'     => 'application/rss+xml, application/atom+xml, application/xml;q=0.9, text/xml;q=0.8, */*;q=0.1',
			'User-Agent' => 'BroadcastArchive/' . BROADCAST_ARCHIVE_VERSION . '; ' . home_url( '/' ),
		);

		if ( ! empty( $meta['etag'] ) ) {
			$headers['If-None-Match'] = (string) $meta['etag'];
		}

		if ( ! empty( $meta['last_modified'] ) ) {
			$headers['If-Modified-Since'] = (string) $meta['last_modified'];
		}

		/**
		 * Filter the feed request timeout in seconds.
		 *
		 * @param int $timeout Timeout.
		 */
		$timeout = (int) apply_filters( 'broadcast_archive_feed_timeout', 12 );

		$response = wp_remote_get(
			$feed_url,
			array(
				'timeout'     => max( 3, $timeout ),
				'redirection' => 2,
				'sslverify'   => true,
				'headers'     => $headers,
			)
		);

		$code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );

		if ( 304 === $code ) {
			$last_good = get_transient( self::last_good_key() );

			if ( is_array( $last_good ) && ! empty( $last_good['items'] ) ) {
				$last_good['stale'] = false;
				self::store( $last_good, (int) ( $meta['fetched_at'] ?? time() ) );
				self::update_meta( $meta );

				return $last_good;
			}
		}

		if ( is_wp_error( $response ) || 200 !== $code ) {
			return self::fallback(
				is_wp_error( $response )
					? $response->get_error_message()
					: sprintf(
						/* translators: %d: HTTP status code. */
						__( 'The archive returned HTTP %d.', 'broadcast-archive' ),
						$code
					)
			);
		}

		$body = (string) wp_remote_retrieve_body( $response );

		if ( '' === $body ) {
			return self::fallback( __( 'The archive returned an empty response.', 'broadcast-archive' ) );
		}

		if ( strlen( $body ) > self::MAX_FEED_BYTES ) {
			return self::fallback( __( 'The archive response is too large to process.', 'broadcast-archive' ) );
		}

		try {
			$payload = self::parse( $body );
		} catch ( \Throwable $exception ) {
			return self::fallback( $exception->getMessage() );
		}

		if ( empty( $payload['items'] ) ) {
			return self::fallback( __( 'The archive feed contains no playable broadcasts.', 'broadcast-archive' ) );
		}

		$meta['etag']          = (string) wp_remote_retrieve_header( $response, 'etag' );
		$meta['last_modified'] = (string) wp_remote_retrieve_header( $response, 'last-modified' );
		$meta['fetched_at']    = time();
		$meta['count']         = count( $payload['items'] );
		$meta['url']           = $feed_url;

		self::store( $payload, time() );
		self::update_meta( $meta );

		return $payload;
	}

	/**
	 * Parse an RSS or Atom document into the public payload.
	 *
	 * @param string $xml Raw XML.
	 * @return array<string, mixed>
	 *
	 * @throws \RuntimeException When the document cannot be parsed.
	 */
	public static function parse( string $xml, ?string $station = null, ?\DateTimeZone $timezone = null, ?string $locale = null ): array {
		$xml = trim( $xml );

		if ( '' === $xml ) {
			throw new \RuntimeException( __( 'The archive returned an empty response.', 'broadcast-archive' ) );
		}

		if ( str_contains( strtoupper( $xml ), '<!DOCTYPE' ) ) {
			throw new \RuntimeException( __( 'The archive feed uses an unsupported document type.', 'broadcast-archive' ) );
		}

		$previous = libxml_use_internal_errors( true );
		$dom      = new \DOMDocument();
		$dom->preserveWhiteSpace = false;

		$loaded = $dom->loadXML( $xml, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT );

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded || null === $dom->documentElement ) {
			throw new \RuntimeException( __( 'The archive feed could not be parsed.', 'broadcast-archive' ) );
		}

		$xpath = new \DOMXPath( $dom );

		foreach ( array(
			'itunes'  => 'http://www.itunes.com/dtds/podcast-1.0.dtd',
			'content' => 'http://purl.org/rss/1.0/modules/content/',
			'dc'      => 'http://purl.org/dc/elements/1.1/',
			'media'   => 'http://search.yahoo.com/mrss/',
			'atom'    => 'http://www.w3.org/2005/Atom',
		) as $prefix => $namespace ) {
			$xpath->registerNamespace( $prefix, $namespace );
		}

		$is_atom = 'feed' === $dom->documentElement->localName;

		$nodes = $xpath->query( $is_atom ? '//*[local-name()="entry"]' : '//*[local-name()="channel"]/*[local-name()="item"]' );

		if ( ( ! $nodes || 0 === $nodes->length ) && ! $is_atom ) {
			// RSS 1.0 / RDF feeds keep items outside <channel>.
			$nodes = $xpath->query( '//*[local-name()="item"]' );
		}

		if ( ! $nodes || 0 === $nodes->length ) {
			throw new \RuntimeException( __( 'The archive feed contains no broadcasts.', 'broadcast-archive' ) );
		}

		$channel_image = self::channel_image( $xpath, $is_atom );
		$station       = $station ?? Settings::station_name();
		$timezone      = $timezone ?? Settings::timezone();
		$locale        = $locale ?? Settings::locale();

		$items = array();

		foreach ( $nodes as $node ) {
			if ( ! $node instanceof \DOMElement ) {
				continue;
			}

			$raw = self::raw_item( $xpath, $node, $channel_image, $is_atom );
			$item = Episode::from_raw( $raw, $timezone, $station );

			if ( null === $item ) {
				continue;
			}

			$items[ $item['id'] ] = $item;
		}

		$items = array_values( $items );

		usort(
			$items,
			static function ( array $a, array $b ): int {
				return (int) $b['ts'] <=> (int) $a['ts'];
			}
		);

		$programs = array();

		foreach ( $items as $item ) {
			$name = (string) $item['show'];

			if ( '' === $name ) {
				continue;
			}

			if ( ! isset( $programs[ $name ] ) ) {
				$programs[ $name ] = array(
					'name'  => $name,
					'count' => 0,
				);
			}

			++$programs[ $name ]['count'];
		}

		uasort(
			$programs,
			static function ( array $a, array $b ): int {
				return $b['count'] <=> $a['count'] ?: strcasecmp( $a['name'], $b['name'] );
			}
		);

		return array(
			'station'  => $station,
			'locale'   => $locale,
			'generated' => time(),
			'stale'    => false,
			'count'    => count( $items ),
			'programs' => array_values( $programs ),
			'items'    => $items,
		);
	}

	/**
	 * Extract raw fields from one item node.
	 *
	 * @param \DOMXPath $xpath         XPath helper.
	 * @param \DOMElement $node         Item node.
	 * @param string    $channel_image Channel-level artwork.
	 * @param bool      $is_atom       Whether this is an Atom feed.
	 * @return array<string, mixed>
	 */
	private static function raw_item( \DOMXPath $xpath, \DOMElement $node, string $channel_image, bool $is_atom ): array {
		$title = self::text( $xpath, $node, './*[local-name()="title"]' );

		if ( '' === $title ) {
			$title = self::text( $xpath, $node, './*[local-name()="name"]' );
		}

		$description = self::text( $xpath, $node, './*[local-name()="description"]' );

		if ( '' === $description ) {
			$description = self::text( $xpath, $node, './content:encoded' );
		}

		if ( '' === $description ) {
			$description = self::text( $xpath, $node, './itunes:summary' );
		}

		if ( '' === $description ) {
			$description = self::text( $xpath, $node, './*[local-name()="summary"]' );
		}

		if ( '' === $description ) {
			$description = self::text( $xpath, $node, './*[local-name()="content"]' );
		}

		$author = self::text( $xpath, $node, './itunes:author' );

		if ( '' === $author ) {
			$author = self::text( $xpath, $node, './*[local-name()="author"]/*[local-name()="name"]' );
		}

		if ( '' === $author ) {
			$author = self::text( $xpath, $node, './dc:creator' );
		}

		$duration = self::text( $xpath, $node, './itunes:duration' );

		if ( '' === $duration ) {
			$duration = self::attr( $xpath, $node, './media:content', 'duration' );
		}

		$image = self::attr( $xpath, $node, './itunes:image', 'href' );

		if ( '' === $image ) {
			$image = self::text( $xpath, $node, './*[local-name()="image"]' );
		}

		if ( '' === $image ) {
			$image = self::attr( $xpath, $node, './media:thumbnail', 'url' );
		}

		if ( '' === $image ) {
			$image = self::text( $xpath, $node, './*[local-name()="image"]/*[local-name()="url"]' );
		}

		$audio = self::attr( $xpath, $node, './enclosure', 'url' );

		if ( '' === $audio ) {
			$audio = self::attr( $xpath, $node, './*[local-name()="link"][@rel="enclosure"]', 'href' );
		}

		if ( '' === $audio ) {
			$audio = self::attr( $xpath, $node, './*[local-name()="link"][@type="audio/mpeg"]', 'href' );
		}

		if ( '' === $audio ) {
			$audio = self::attr( $xpath, $node, './media:content[starts-with(@type, "audio/")]', 'url' );
		}

		if ( '' === $audio ) {
			$audio = self::attr( $xpath, $node, './media:content[@medium="audio"]', 'url' );
		}

		$type = self::attr( $xpath, $node, './enclosure', 'type' );

		if ( '' === $type ) {
			$type = self::attr( $xpath, $node, './*[local-name()="link"][@rel="enclosure"]', 'type' );
		}

		$size = self::attr( $xpath, $node, './enclosure', 'length' );

		$guid = self::text( $xpath, $node, './guid' );

		if ( '' === $guid ) {
			$guid = self::text( $xpath, $node, './*[local-name()="id"]' );
		}

		$published = self::text( $xpath, $node, './*[local-name()="pubDate"]' );

		if ( '' === $published && $is_atom ) {
			$published = self::text( $xpath, $node, './*[local-name()="published"]' );
		}

		if ( '' === $published ) {
			$published = self::text( $xpath, $node, './*[local-name()="updated"]' );
		}

		if ( '' === $published ) {
			$published = self::text( $xpath, $node, './dc:date' );
		}

		return array(
			'title'         => $title,
			'description'   => $description,
			'author'        => $author,
			'duration'      => $duration,
			'image'         => $image,
			'audio'         => $audio,
			'type'          => $type,
			'size'          => $size,
			'guid'          => $guid,
			'published'     => $published,
			'channel_image' => $channel_image,
		);
	}

	/**
	 * Channel-level artwork.
	 *
	 * @param \DOMXPath $xpath   XPath helper.
	 * @param bool      $is_atom Whether this is an Atom feed.
	 * @return string
	 */
	private static function channel_image( \DOMXPath $xpath, bool $is_atom ): string {
		$image = self::attr_document( $xpath, '//*[local-name()="channel"]/*[local-name()="image"][@href]', 'href' );

		if ( '' === $image ) {
			$image = self::attr_document( $xpath, '//*[local-name()="channel"]/*[local-name()="image"]/*[local-name()="url"]', 'text' );
		}

		if ( '' === $image && $is_atom ) {
			$image = self::attr_document( $xpath, '//*[local-name()="logo"]', 'text' );
		}

		if ( '' === $image && $is_atom ) {
			$image = self::attr_document( $xpath, '//*[local-name()="icon"]', 'text' );
		}

		return $image;
	}

	/**
	 * First text node of a query.
	 *
	 * @param \DOMXPath   $xpath XPath helper.
	 * @param \DOMNode    $context Context.
	 * @param string      $query Query.
	 * @return string
	 */
	private static function text( \DOMXPath $xpath, \DOMNode $context, string $query ): string {
		$nodes = $xpath->query( $query, $context );

		if ( ! $nodes instanceof \DOMNodeList || 0 === $nodes->length ) {
			return '';
		}

		return trim( (string) $nodes->item( 0 )?->textContent );
	}

	/**
	 * First attribute of a query.
	 *
	 * @param \DOMXPath $xpath   XPath helper.
	 * @param \DOMNode  $context Context.
	 * @param string    $query   Query.
	 * @param string    $attribute Attribute name.
	 * @return string
	 */
	private static function attr( \DOMXPath $xpath, \DOMNode $context, string $query, string $attribute ): string {
		$nodes = $xpath->query( $query, $context );

		if ( ! $nodes instanceof \DOMNodeList || 0 === $nodes->length ) {
			return '';
		}

		$node = $nodes->item( 0 );

		if ( ! $node instanceof \DOMElement ) {
			return '';
		}

		return trim( $node->getAttribute( $attribute ) );
	}

	/**
	 * Text or attribute for a document-level query.
	 *
	 * @param \DOMXPath $xpath XPath helper.
	 * @param string    $query Query.
	 * @param string    $mode  "text" or an attribute name.
	 * @return string
	 */
	private static function attr_document( \DOMXPath $xpath, string $query, string $mode ): string {
		$nodes = $xpath->query( $query );

		if ( ! $nodes instanceof \DOMNodeList || 0 === $nodes->length ) {
			return '';
		}

		$node = $nodes->item( 0 );

		if ( ! $node instanceof \DOMElement ) {
			return '';
		}

		return 'text' === $mode ? trim( (string) $node->textContent ) : trim( $node->getAttribute( $mode ) );
	}

	/**
	 * Serve the last known good payload when the archive is unavailable.
	 *
	 * @param string $reason Internal reason (not shown verbatim to visitors).
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function fallback( string $reason ): array|\WP_Error {
		$stale = get_transient( self::last_good_key() );

		if ( is_array( $stale ) && ! empty( $stale['items'] ) ) {
			$stale['stale'] = true;

			// Retry the archive within a minute instead of the full cache window.
			set_transient( self::cache_key(), $stale, MINUTE_IN_SECONDS );

			return $stale;
		}

		return new \WP_Error(
			'ba_feed_unavailable',
			__( 'The archive is temporarily unavailable. Please try again in a moment.', 'broadcast-archive' ),
			array(
				'status' => 503,
				'reason' => $reason,
			)
		);
	}

	/**
	 * Store payload in the short-lived cache and the long-lived fallback.
	 *
	 * @param array<string, mixed> $payload Payload.
	 * @param int                  $fetched_at Fetch timestamp.
	 * @return void
	 */
	private static function store( array $payload, int $fetched_at ): void {
		$payload['generated'] = $fetched_at > 0 ? $fetched_at : time();

		/**
		 * Filter how long the parsed feed is cached, in minutes.
		 *
		 * @param int $minutes Minutes.
		 */
		$minutes = (int) apply_filters( 'broadcast_archive_cache_minutes', (int) Settings::option( 'cache_minutes', 5 ) );
		$minutes = max( 1, min( 1440, $minutes ) );

		set_transient( self::cache_key(), $payload, $minutes * MINUTE_IN_SECONDS );
		set_transient( self::last_good_key(), $payload, self::STALE_TTL );
	}

	/**
	 * Cache key for the parsed payload.
	 *
	 * @return string
	 */
	private static function cache_key(): string {
		return 'ba_feed_' . md5(
			self::feed_url() . '|' . Settings::timezone()->getName() . '|' . Settings::locale() . '|' . Settings::station_name()
		);
	}

	/**
	 * Cache key for the last good payload.
	 *
	 * @return string
	 */
	private static function last_good_key(): string {
		return 'ba_feed_last_good_' . md5( self::feed_url() );
	}

	/**
	 * Configured feed URL.
	 *
	 * @return string
	 */
	private static function feed_url(): string {
		/**
		 * Filter the archive feed URL.
		 *
		 * @param string $url Feed URL.
		 */
		return (string) apply_filters( 'broadcast_archive_feed_url', Settings::feed_url() );
	}

	/**
	 * Stored fetch metadata.
	 *
	 * @return array<string, mixed>
	 */
	private static function meta(): array {
		$meta = get_option( self::META_OPTION, array() );

		return is_array( $meta ) ? $meta : array();
	}

	/**
	 * Persist fetch metadata.
	 *
	 * @param array<string, mixed> $meta Metadata.
	 * @return void
	 */
	private static function update_meta( array $meta ): void {
		update_option( self::META_OPTION, $meta, false );
	}

	/**
	 * Status for the settings screen.
	 *
	 * @return array{count:int, fetched_at:int, url:string}
	 */
	public static function status(): array {
		$meta = self::meta();

		return array(
			'count'      => (int) ( $meta['count'] ?? 0 ),
			'fetched_at' => (int) ( $meta['fetched_at'] ?? 0 ),
			'url'        => (string) ( $meta['url'] ?? '' ),
		);
	}

	/**
	 * Remove cached payloads and fetch metadata.
	 *
	 * @return void
	 */
	public static function clear_cache(): void {
		global $wpdb;

		$like = $wpdb->esc_like( '_transient_ba_' ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$like,
				$wpdb->esc_like( '_transient_timeout_ba_' ) . '%'
			)
		);

		delete_option( self::META_OPTION );

		if ( function_exists( 'wp_cache_flush_group' ) ) {
			wp_cache_flush_group( 'broadcast-archive' );
		}
	}
}
