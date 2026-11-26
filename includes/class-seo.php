<?php
/**
 * SEO integration for the archive page.
 *
 * The archive itself is a client-rendered application: the page HTML is a
 * skeleton and the episode data is fetched afterwards. That is deliberate and
 * keeps the full-page cache safe. It does mean the page benefits from static,
 * server-rendered metadata: a description, an Open Graph image and a
 * PodcastSeries node that describes the archive without baking episode data
 * into a cached page.
 *
 * @package BroadcastArchive
 */

declare( strict_types=1 );

namespace BroadcastArchive;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Yoast-aware metadata with a plain WordPress fallback.
 */
final class SEO {

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_filter( 'wpseo_metadesc', array( $this, 'filter_description' ) );
		add_filter( 'wpseo_opengraph_desc', array( $this, 'filter_description' ) );
		add_filter( 'wpseo_twitter_description', array( $this, 'filter_description' ) );
		add_filter( 'wpseo_opengraph_image', array( $this, 'filter_image' ) );
		add_filter( 'wpseo_twitter_image', array( $this, 'filter_image' ) );
		add_action( 'wp_head', array( $this, 'print_schema' ), 20 );
		add_action( 'wp_head', array( $this, 'print_fallback_head' ), 1 );
	}

	/**
	 * The page that hosts the archive shortcode, if the current request is it.
	 *
	 * @return \WP_Post|null
	 */
	private function archive_post(): ?\WP_Post {
		if ( ! is_singular() ) {
			return null;
		}

		$post = get_queried_object();

		if ( ! $post instanceof \WP_Post ) {
			return null;
		}

		$content = (string) $post->post_content;

		if ( ! has_shortcode( $content, 'broadcast_archive' ) && ! has_shortcode( $content, 'salland1_uitzending_gemist' ) ) {
			return null;
		}

		return $post;
	}

	/**
	 * Static archive description.
	 */
	private function description(): string {
		return sprintf(
			/* translators: %s: station name. */
			__( 'Listen back to broadcasts from %s. Search by programme, date or title and open the playlist.', 'broadcast-archive' ),
			Settings::station_name()
		);
	}

	/**
	 * Replace the meta and social description on the archive page.
	 *
	 * @param string $description Current description.
	 */
	public function filter_description( $description ): string {
		$post = $this->archive_post();

		if ( null === $post ) {
			return (string) $description;
		}

		if ( '' !== trim( (string) get_post_meta( $post->ID, '_yoast_wpseo_metadesc', true ) ) ) {
			return (string) $description;
		}

		return $this->description();
	}

	/**
	 * Use the site icon when the archive page has no featured image.
	 *
	 * @param string $image Current image URL.
	 */
	public function filter_image( $image ): string {
		$post = $this->archive_post();

		if ( null === $post ) {
			return (string) $image;
		}

		foreach ( array( '_yoast_wpseo_opengraph-image-id', '_yoast_wpseo_opengraph-image' ) as $key ) {
			if ( '' !== trim( (string) get_post_meta( $post->ID, $key, true ) ) ) {
				return (string) $image;
			}
		}

		$icon = get_site_icon_url( 512 );

		return $icon ? $icon : (string) $image;
	}

	/**
	 * Print a plain meta description when Yoast is not active.
	 */
	public function print_fallback_head(): void {
		if ( defined( 'WPSEO_VERSION' ) ) {
			return;
		}

		$post = $this->archive_post();

		if ( null === $post ) {
			return;
		}

		echo '<meta name="description" content="' . esc_attr( $this->description() ) . '" />' . "\n";
	}

	/**
	 * Print the PodcastSeries node.
	 *
	 * The node is static page metadata; it never contains episode data, so it
	 * is safe in a page cache with no expiry.
	 */
	public function print_schema(): void {
		$post = $this->archive_post();

		if ( null === $post ) {
			return;
		}

		$publisher = defined( 'WPSEO_VERSION' )
			? array( '@id' => home_url( '/#organization' ) )
			: array(
				'@type' => 'Organization',
				'name'  => get_bloginfo( 'name' ),
				'url'   => home_url( '/' ),
			);

		$data = array(
			'@context'    => 'https://schema.org',
			'@type'       => 'PodcastSeries',
			'@id'         => get_permalink( $post ) . '#podcast',
			'name'        => get_the_title( $post ),
			'url'         => get_permalink( $post ),
			'description' => $this->description(),
			'inLanguage'  => get_bloginfo( 'language' ),
			'publisher'   => $publisher,
		);

		$image = get_site_icon_url( 512 );

		if ( $image ) {
			$data['image'] = $image;
		}

		$feed = Settings::feed_url();

		if ( '' !== $feed ) {
			$data['webFeed'] = $feed;
		}

		$json = wp_json_encode(
			$data,
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
		);

		if ( false === $json ) {
			return;
		}

		echo '<script type="application/ld+json">' . $json . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON encoded with HEX flags.
	}
}
