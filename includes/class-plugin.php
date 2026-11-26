<?php
/**
 * Plugin bootstrap: hooks, defaults and asset loading.
 *
 * @package BroadcastArchive
 */

declare( strict_types=1 );

namespace BroadcastArchive;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin class.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Whether the front-end configuration script was already printed.
	 *
	 * @var bool
	 */
	private bool $config_printed = false;

	/**
	 * Whether front-end assets were already enqueued.
	 *
	 * @var bool
	 */
	private bool $assets_enqueued = false;

	/**
	 * Get the singleton instance.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register hooks.
	 */
	private function __construct() {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( $this, 'register_shortcodes' ) );
		add_action( 'rest_api_init', array( Rest_Controller::class, 'register_routes' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_config_notice' ) );

		new SEO();

		if ( is_admin() ) {
			Settings::init();
		}
	}

	/**
	 * Load translations.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'broadcast-archive',
			false,
			dirname( plugin_basename( BROADCAST_ARCHIVE_FILE ) ) . '/languages'
		);
	}

	/**
	 * Register the shortcodes, including the legacy Salland1 name.
	 *
	 * @return void
	 */
	public function register_shortcodes(): void {
		add_shortcode( 'broadcast_archive', array( Shortcode::class, 'render' ) );

		// Backwards compatibility with the original Salland1 plugin.
		add_shortcode( 'salland1_uitzending_gemist', array( Shortcode::class, 'render' ) );
	}

	/**
	 * Remind editors when the plugin is active without a feed URL.
	 *
	 * Kept out of the shortcode output so cached public HTML stays identical.
	 *
	 * @return void
	 */
	public function maybe_show_config_notice(): void {
		if ( ! current_user_can( 'manage_options' ) || '' !== Settings::feed_url() ) {
			return;
		}

		$url = admin_url( 'options-general.php?page=broadcast-archive' );

		printf(
			'<div class="notice notice-warning"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
			esc_html__( 'Broadcast Archive is active, but no feed URL has been configured yet.', 'broadcast-archive' ),
			esc_url( $url ),
			esc_html__( 'Configure the feed', 'broadcast-archive' )
		);
	}

	/**
	 * Enqueue the assets on singular views that actually contain a shortcode.
	 *
	 * The shortcode renderer enqueues as a fallback, but doing it here keeps the
	 * stylesheet in the head instead of the footer.
	 *
	 * @return void
	 */
	public function maybe_enqueue_assets(): void {
		if ( ! is_singular() ) {
			return;
		}

		$post = get_queried_object();

		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		$content = (string) $post->post_content;

		if ( has_shortcode( $content, 'broadcast_archive' ) || has_shortcode( $content, 'salland1_uitzending_gemist' ) ) {
			$this->enqueue_assets();
		}
	}

	/**
	 * Enqueue stylesheet, script and the global JS configuration.
	 *
	 * Safe to call several times; WordPress de-duplicates by handle.
	 *
	 * @return void
	 */
	public function enqueue_assets(): void {
		if ( $this->assets_enqueued ) {
			return;
		}

		$this->assets_enqueued = true;

		$css_path = BROADCAST_ARCHIVE_DIR . 'assets/css/broadcast-archive.css';
		$js_path  = BROADCAST_ARCHIVE_DIR . 'assets/js/broadcast-archive.js';

		$css_version = is_readable( $css_path ) ? (string) filemtime( $css_path ) : BROADCAST_ARCHIVE_VERSION;
		$js_version  = is_readable( $js_path ) ? (string) filemtime( $js_path ) : BROADCAST_ARCHIVE_VERSION;

		wp_enqueue_style(
			'broadcast-archive',
			BROADCAST_ARCHIVE_URL . 'assets/css/broadcast-archive.css',
			array(),
			$css_version
		);

		wp_enqueue_script(
			'broadcast-archive',
			BROADCAST_ARCHIVE_URL . 'assets/js/broadcast-archive.js',
			array(),
			$js_version,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		if ( ! $this->config_printed ) {
			$this->config_printed = true;
			wp_add_inline_script( 'broadcast-archive', $this->config_script(), 'before' );
		}
	}

	/**
	 * Enqueue the shared player without requiring the archive shortcode.
	 *
	 * Used by site-specific hubs so their recent-episode rows can open the
	 * same bottom transport as the archive page.
	 *
	 * @return void
	 */
	public function enqueue_player_assets(): void {
		$this->enqueue_assets();
	}

	/**
	 * Build the global configuration object for the front end.
	 *
	 * Deliberately contains no nonces and no per-visitor values: the markup and
	 * this script have to be identical for every anonymous visitor so that a
	 * full-page cache can serve them indefinitely.
	 *
	 * @return string
	 */
	private function config_script(): string {
		$settings = Settings::get();
		$locale   = Settings::locale();

		$config = array(
			'restUrl'     => rest_url( 'broadcast-archive/v1/' ),
			'locale'        => $locale,
			'isRtl'         => is_rtl(),
			'station'       => Settings::station_name(),
			'accent'        => Settings::accent_color(),
			'placeholder'   => Settings::placeholder_url(),
			'downloadEnabled' => (bool) Settings::option( 'download_enabled', 1 ),
			'showPlaylist'  => (bool) Settings::option( 'show_playlist', 1 ),
			'labels'      => array(
				'loading'          => __( 'Loading broadcasts…', 'broadcast-archive' ),
				'loadErrorTitle'   => __( 'Could not load the broadcasts', 'broadcast-archive' ),
				'loadErrorBody'    => __( 'Please check your connection and try again.', 'broadcast-archive' ),
				'staleNotice'      => __( 'The archive is temporarily unavailable. Showing the last known broadcasts.', 'broadcast-archive' ),
				'retry'            => __( 'Try again', 'broadcast-archive' ),
				'emptyTitle'       => __( 'No broadcasts match these filters', 'broadcast-archive' ),
				'clearFilters'     => __( 'Clear filters', 'broadcast-archive' ),
				'searchLabel'      => __( 'Search broadcasts', 'broadcast-archive' ),
				'searchPlaceholder' => __( 'Search programme, date or title', 'broadcast-archive' ),
				'clearSearch'      => __( 'Clear search', 'broadcast-archive' ),
				'nowPlaying'       => __( 'Now playing: %s', 'broadcast-archive' ),
				'programLabel'     => __( 'Programme', 'broadcast-archive' ),
				'allProgrammes'    => __( 'All programmes', 'broadcast-archive' ),
				'dayLabel'         => __( 'Day', 'broadcast-archive' ),
				'allDays'          => __( 'All days', 'broadcast-archive' ),
				'episodeCount'     => __( '%s broadcasts', 'broadcast-archive' ),
				'episodeCountOne'  => __( '1 broadcast', 'broadcast-archive' ),
				'loadMore'         => __( 'Load more', 'broadcast-archive' ),
				'loadMoreCount'    => __( 'Load more (%s remaining)', 'broadcast-archive' ),
				'play'             => __( 'Play', 'broadcast-archive' ),
				'pause'            => __( 'Pause', 'broadcast-archive' ),
				'playItem'         => __( 'Play “%s”', 'broadcast-archive' ),
				'pauseItem'        => __( 'Pause “%s”', 'broadcast-archive' ),
				'showDetails'      => __( 'Show details', 'broadcast-archive' ),
				'hideDetails'      => __( 'Hide details', 'broadcast-archive' ),
				'info'             => __( 'Info', 'broadcast-archive' ),
				'description'      => __( 'Description', 'broadcast-archive' ),
				'showDescription'  => __( 'Show description', 'broadcast-archive' ),
				'hideDescription'  => __( 'Hide description', 'broadcast-archive' ),
				'close'            => __( 'Close', 'broadcast-archive' ),
				'download'         => __( 'Download', 'broadcast-archive' ),
				'playlist'         => __( 'Playlist', 'broadcast-archive' ),
				'presentedBy'      => __( 'Presented by %s', 'broadcast-archive' ),
				'continueListening' => __( 'Continue listening', 'broadcast-archive' ),
				'continueAt'       => __( 'Resume at %s', 'broadcast-archive' ),
				'player'           => __( 'Player', 'broadcast-archive' ),
				'closePlayer'      => __( 'Close player', 'broadcast-archive' ),
				'back15'           => __( 'Back 15 seconds', 'broadcast-archive' ),
				'forward15'        => __( 'Forward 15 seconds', 'broadcast-archive' ),
				'position'         => __( 'Playback position', 'broadcast-archive' ),
				'playbackError'    => __( 'This broadcast could not be played.', 'broadcast-archive' ),
				'ended'            => __( 'This broadcast has ended.', 'broadcast-archive' ),
				'replay'           => __( 'Play again', 'broadcast-archive' ),
				'unknownDate'      => __( 'Unknown date', 'broadcast-archive' ),
				'hours'            => __( '%s hr', 'broadcast-archive' ),
				'minutes'          => __( '%s min', 'broadcast-archive' ),
				'hoursMinutes'     => __( '%1$s hr %2$s min', 'broadcast-archive' ),
			),
		);

		/**
		 * Filter the front-end configuration object.
		 *
		 * @param array<string, mixed> $config Configuration passed to JavaScript.
		 */
		$config = (array) apply_filters( 'broadcast_archive_frontend_config', $config );

		return 'window.BroadcastArchiveConfig = ' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES ) . ';';
	}

	/**
	 * Default options.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'feed_url'            => '',
			'station_name'        => get_bloginfo( 'name' ),
			'accent_color'        => '#e50526',
			'locale'              => str_replace( '_', '-', (string) get_locale() ),
			'timezone'            => wp_timezone_string(),
			'items_per_page'      => 24,
			'cache_minutes'       => 5,
			'download_enabled'    => 1,
			'extra_download_hosts' => '',
			'show_search'         => 1,
			'show_program_filter' => 1,
			'show_playlist'       => 1,
			'placeholder_url'     => '',
		);
	}

	/**
	 * Activation: store defaults without overwriting an existing configuration.
	 *
	 * @return void
	 */
	public static function activate(): void {
		$existing = get_option( 'broadcast_archive_options', null );

		if ( ! is_array( $existing ) ) {
			add_option( 'broadcast_archive_options', self::defaults() );
		}

	}

	/**
	 * Deactivation: nothing persistent to clean up.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		// Intentionally empty. Removing options here would delete station
		// configuration on a simple deactivate/reactivate cycle.
	}
}
