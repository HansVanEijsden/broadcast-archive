<?php
/**
 * Settings screen and option handling.
 *
 * @package BroadcastArchive
 */

declare( strict_types=1 );

namespace BroadcastArchive;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Station configuration.
 */
final class Settings {

	/**
	 * Option name.
	 */
	public const OPTION = 'broadcast_archive_options';

	/**
	 * Cached options for the current request.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $cache = null;

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( self::class, 'add_page' ) );
		add_action( 'admin_init', array( self::class, 'register' ) );
		add_action( 'admin_post_broadcast_archive_clear_cache', array( self::class, 'handle_clear_cache' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( BROADCAST_ARCHIVE_FILE ), array( self::class, 'action_links' ) );
	}

	/**
	 * Add the settings page.
	 *
	 * @return void
	 */
	public static function add_page(): void {
		add_options_page(
			__( 'Broadcast Archive', 'broadcast-archive' ),
			__( 'Broadcast Archive', 'broadcast-archive' ),
			'manage_options',
			'broadcast-archive',
			array( self::class, 'render_page' )
		);
	}

	/**
	 * Register the option and its sanitiser.
	 *
	 * @return void
	 */
	public static function register(): void {
		register_setting(
			'broadcast_archive',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'default'           => Plugin::defaults(),
			)
		);
	}

	/**
	 * Add a settings link on the plugins screen.
	 *
	 * @param array<int, string> $links Existing links.
	 * @return array<int, string>
	 */
	public static function action_links( array $links ): array {
		$url = admin_url( 'options-general.php?page=broadcast-archive' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'broadcast-archive' ) . '</a>' );
		return $links;
	}

	/**
	 * All options merged over the defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function get(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		self::$cache = array_merge( Plugin::defaults(), $stored );

		return self::$cache;
	}

	/**
	 * One option value.
	 *
	 * @param string $key     Option key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public static function option( string $key, mixed $default = null ): mixed {
		$options = self::get();

		return $options[ $key ] ?? $default;
	}

	/**
	 * Forget the request cache (after saving or clearing).
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$cache = null;
	}

	/**
	 * Configured feed URL, or an empty string.
	 *
	 * @return string
	 */
	public static function feed_url(): string {
		$url = (string) self::option( 'feed_url', '' );

		return esc_url_raw( $url, array( 'http', 'https' ) );
	}

	/**
	 * Station display name.
	 *
	 * @return string
	 */
	public static function station_name(): string {
		$name = trim( (string) self::option( 'station_name', '' ) );

		if ( '' === $name ) {
			$name = (string) get_bloginfo( 'name' );
		}

		return $name;
	}

	/**
	 * Locale used for dates in the interface, in BCP 47 form.
	 *
	 * @return string
	 */
	public static function locale(): string {
		$locale = (string) self::option( 'locale', '' );

		if ( '' === $locale ) {
			$locale = (string) get_locale();
		}

		$locale = str_replace( '_', '-', $locale );

		/**
		 * Filter the locale used for date formatting.
		 *
		 * @param string $locale BCP 47 locale.
		 */
		$locale = (string) apply_filters( 'broadcast_archive_locale', $locale );

		return preg_match( '/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/', $locale ) ? $locale : 'en';
	}

	/**
	 * Station timezone.
	 *
	 * @return \DateTimeZone
	 */
	public static function timezone(): \DateTimeZone {
		$name = (string) self::option( 'timezone', '' );

		if ( '' !== $name && in_array( $name, timezone_identifiers_list(), true ) ) {
			return new \DateTimeZone( $name );
		}

		return wp_timezone();
	}

	/**
	 * Accent colour.
	 *
	 * @return string
	 */
	public static function accent_color(): string {
		$color = sanitize_hex_color( (string) self::option( 'accent_color', '#e50526' ) );

		return $color ? $color : '#e50526';
	}

	/**
	 * Placeholder image for broadcasts without artwork.
	 *
	 * @return string
	 */
	public static function placeholder_url(): string {
		$url = (string) self::option( 'placeholder_url', '' );

		if ( '' !== $url ) {
			$url = esc_url_raw( $url, array( 'http', 'https' ) );
		}

		if ( '' === $url ) {
			$url = BROADCAST_ARCHIVE_URL . 'assets/images/placeholder.svg';
		}

		return $url;
	}

	/**
	 * Hosts the download proxy may fetch from.
	 *
	 * Always contains the feed host; editors can add a CDN host.
	 *
	 * @return array<int, string>
	 */
	public static function download_hosts(): array {
		$hosts = array();

		$feed_host = wp_parse_url( self::feed_url(), PHP_URL_HOST );

		if ( is_string( $feed_host ) && '' !== $feed_host ) {
			$hosts[] = strtolower( $feed_host );
		}

		$extra = (string) self::option( 'extra_download_hosts', '' );

		foreach ( preg_split( '/[\s,]+/', $extra ) ?: array() as $host ) {
			$host = strtolower( trim( (string) $host ) );

			if ( '' === $host ) {
				continue;
			}

			$host = preg_replace( '#^https?://#i', '', $host );
			$host = preg_replace( '#/.*$#', '', (string) $host );

			if ( preg_match( '/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/', (string) $host ) ) {
				$hosts[] = (string) $host;
			}
		}

		$hosts = array_values( array_unique( $hosts ) );

		/**
		 * Filter the hosts allowed for downloads.
		 *
		 * @param array<int, string> $hosts Lowercase host names.
		 */
		return (array) apply_filters( 'broadcast_archive_download_hosts', $hosts );
	}

	/**
	 * Validate and normalise submitted options.
	 *
	 * @param mixed $input Raw input.
	 * @return array<string, mixed>
	 */
	public static function sanitize( mixed $input ): array {
		$input    = is_array( $input ) ? $input : array();
		$defaults = Plugin::defaults();

		$clean = array();

		$feed_url = isset( $input['feed_url'] ) ? esc_url_raw( trim( (string) $input['feed_url'] ), array( 'http', 'https' ) ) : '';
		$clean['feed_url'] = $feed_url;

		$clean['station_name'] = isset( $input['station_name'] ) ? sanitize_text_field( (string) $input['station_name'] ) : '';

		$color = isset( $input['accent_color'] ) ? sanitize_hex_color( (string) $input['accent_color'] ) : '';
		$clean['accent_color'] = $color ? $color : $defaults['accent_color'];

		$locale = isset( $input['locale'] ) ? sanitize_text_field( (string) $input['locale'] ) : '';
		$locale = str_replace( '_', '-', $locale );
		$clean['locale'] = preg_match( '/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/', $locale ) ? $locale : '';

		$timezone = isset( $input['timezone'] ) ? sanitize_text_field( (string) $input['timezone'] ) : '';
		$clean['timezone'] = in_array( $timezone, timezone_identifiers_list(), true ) ? $timezone : '';

		$per_page = isset( $input['items_per_page'] ) ? absint( $input['items_per_page'] ) : (int) $defaults['items_per_page'];
		$clean['items_per_page'] = max( 6, min( 100, $per_page ) );

		$cache = isset( $input['cache_minutes'] ) ? absint( $input['cache_minutes'] ) : (int) $defaults['cache_minutes'];
		$clean['cache_minutes'] = max( 1, min( 1440, $cache ) );

		$clean['download_enabled']    = empty( $input['download_enabled'] ) ? 0 : 1;
		$clean['show_search']         = empty( $input['show_search'] ) ? 0 : 1;
		$clean['show_program_filter'] = empty( $input['show_program_filter'] ) ? 0 : 1;
		$clean['show_playlist']       = empty( $input['show_playlist'] ) ? 0 : 1;

		$hosts_raw = isset( $input['extra_download_hosts'] ) ? (string) $input['extra_download_hosts'] : '';
		$hosts     = array();

		foreach ( preg_split( '/[\s,]+/', $hosts_raw ) ?: array() as $host ) {
			$host = strtolower( preg_replace( '#^https?://#i', '', trim( (string) $host ) ) ?? '' );
			$host = preg_replace( '#/.*$#', '', $host ) ?? '';

			if ( '' !== $host && preg_match( '/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/', $host ) ) {
				$hosts[] = $host;
			}
		}

		$clean['extra_download_hosts'] = implode( "\n", array_unique( $hosts ) );

		$placeholder = isset( $input['placeholder_url'] ) ? esc_url_raw( trim( (string) $input['placeholder_url'] ), array( 'http', 'https' ) ) : '';
		$clean['placeholder_url'] = $placeholder;

		// A changed feed URL invalidates the parsed cache immediately.
		$previous = self::feed_url();
		if ( $feed_url !== $previous ) {
			Feed::clear_cache();
		}

		self::flush();

		return $clean;
	}

	/**
	 * Delete cached feed data.
	 *
	 * @return void
	 */
	public static function handle_clear_cache(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You are not allowed to do this.', 'broadcast-archive' ),
				'',
				array( 'response' => 403 )
			);
		}

		check_admin_referer( 'broadcast_archive_clear_cache' );

		Feed::clear_cache();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => 'broadcast-archive',
					'ba_cleared' => '1',
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Render the settings screen.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$options   = self::get();
		$status    = Feed::status();
		$clear_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=broadcast_archive_clear_cache' ),
			'broadcast_archive_clear_cache'
		);
		$locale    = (string) get_locale();

		if ( isset( $_GET['ba_cleared'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'The feed cache has been cleared.', 'broadcast-archive' ) . '</p></div>';
		}

		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Broadcast Archive', 'broadcast-archive' ); ?></h1>
			<p class="description" style="max-width:70ch">
				<?php echo esc_html__( 'This plugin turns any podcast or archive feed into a listen-again page. Add the shortcode [broadcast_archive] to a page and configure the station below.', 'broadcast-archive' ); ?>
			</p>

			<div class="card" style="max-width:70ch;padding:1px 20px 8px">
				<h2><?php echo esc_html__( 'Feed status', 'broadcast-archive' ); ?></h2>
				<?php if ( '' === self::feed_url() ) : ?>
					<p><strong><?php echo esc_html__( 'No feed configured yet.', 'broadcast-archive' ); ?></strong> <?php echo esc_html__( 'Add the URL of the podcast feed below.', 'broadcast-archive' ); ?></p>
				<?php else : ?>
					<p>
						<?php
						printf(
							/* translators: %d: number of broadcasts in the cached feed. */
							esc_html__( '%d broadcasts in the last fetch.', 'broadcast-archive' ),
							(int) $status['count']
						);
						?>
					</p>
					<p class="description">
						<?php
						if ( $status['fetched_at'] > 0 ) {
							printf(
								/* translators: %s: human readable time difference. */
								esc_html__( 'Last fetched %s ago.', 'broadcast-archive' ),
								esc_html( human_time_diff( $status['fetched_at'], time() ) )
							);
						} else {
							esc_html_e( 'The feed has not been fetched yet.', 'broadcast-archive' );
						}
						?>
					</p>
					<p><a class="button" href="<?php echo esc_url( $clear_url ); ?>"><?php echo esc_html__( 'Clear feed cache', 'broadcast-archive' ); ?></a></p>
				<?php endif; ?>
			</div>

			<form method="post" action="options.php">
				<?php settings_fields( 'broadcast_archive' ); ?>

				<h2 class="title"><?php echo esc_html__( 'Station', 'broadcast-archive' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ba-feed-url"><?php echo esc_html__( 'Feed URL', 'broadcast-archive' ); ?></label></th>
						<td>
							<input type="url" class="regular-text code" id="ba-feed-url" name="broadcast_archive_options[feed_url]" value="<?php echo esc_attr( (string) $options['feed_url'] ); ?>" placeholder="https://archive.example.com/podcast.php" />
							<p class="description"><?php echo esc_html__( 'RSS 2.0 or Atom feed of the station archive. The feed host is automatically allowed for downloads.', 'broadcast-archive' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ba-station"><?php echo esc_html__( 'Station name', 'broadcast-archive' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="ba-station" name="broadcast_archive_options[station_name]" value="<?php echo esc_attr( (string) $options['station_name'] ); ?>" />
							<p class="description"><?php echo esc_html__( 'Shown in the player and used to clean presenter names. Defaults to the site title.', 'broadcast-archive' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ba-accent"><?php echo esc_html__( 'Accent colour', 'broadcast-archive' ); ?></label></th>
						<td>
							<input type="color" id="ba-accent" name="broadcast_archive_options[accent_color]" value="<?php echo esc_attr( (string) $options['accent_color'] ); ?>" />
							<p class="description"><?php echo esc_html__( 'Used for play buttons, the current position and focus rings.', 'broadcast-archive' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ba-locale"><?php echo esc_html__( 'Date locale', 'broadcast-archive' ); ?></label></th>
						<td>
							<input type="text" class="regular-text code" id="ba-locale" name="broadcast_archive_options[locale]" value="<?php echo esc_attr( (string) $options['locale'] ); ?>" placeholder="nl-NL" />
							<p class="description">
								<?php
								printf(
									/* translators: %s: current WordPress locale. */
									esc_html__( 'Language used for weekday and month names, e.g. nl-NL or en-GB. Empty uses the site locale (%s).', 'broadcast-archive' ),
									esc_html( $locale )
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ba-timezone"><?php echo esc_html__( 'Timezone', 'broadcast-archive' ); ?></label></th>
						<td>
							<select id="ba-timezone" name="broadcast_archive_options[timezone]">
								<option value=""><?php echo esc_html__( 'Use the site timezone', 'broadcast-archive' ); ?></option>
								<?php echo wp_timezone_choice( (string) $options['timezone'], get_user_locale() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core function escapes. ?>
							</select>
							<p class="description"><?php echo esc_html__( 'Timezone of the broadcast times written in the feed titles.', 'broadcast-archive' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php echo esc_html__( 'Display', 'broadcast-archive' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ba-per-page"><?php echo esc_html__( 'Broadcasts per page', 'broadcast-archive' ); ?></label></th>
						<td>
							<input type="number" min="6" max="100" step="1" id="ba-per-page" name="broadcast_archive_options[items_per_page]" value="<?php echo esc_attr( (string) $options['items_per_page'] ); ?>" class="small-text" />
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Interface', 'broadcast-archive' ); ?></th>
						<td>
							<fieldset>
								<label><input type="checkbox" name="broadcast_archive_options[show_search]" value="1" <?php checked( (int) $options['show_search'], 1 ); ?> /> <?php echo esc_html__( 'Show the search field', 'broadcast-archive' ); ?></label><br />
								<label><input type="checkbox" name="broadcast_archive_options[show_program_filter]" value="1" <?php checked( (int) $options['show_program_filter'], 1 ); ?> /> <?php echo esc_html__( 'Show the programme filter', 'broadcast-archive' ); ?></label><br />
								<label><input type="checkbox" name="broadcast_archive_options[show_playlist]" value="1" <?php checked( (int) $options['show_playlist'], 1 ); ?> /> <?php echo esc_html__( 'Show playlists from the description', 'broadcast-archive' ); ?></label>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ba-placeholder"><?php echo esc_html__( 'Fallback artwork', 'broadcast-archive' ); ?></label></th>
						<td>
							<input type="url" class="regular-text code" id="ba-placeholder" name="broadcast_archive_options[placeholder_url]" value="<?php echo esc_attr( (string) $options['placeholder_url'] ); ?>" placeholder="<?php echo esc_attr( BROADCAST_ARCHIVE_URL . 'assets/images/placeholder.svg' ); ?>" />
							<p class="description"><?php echo esc_html__( 'Shown when a broadcast has no image of its own.', 'broadcast-archive' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php echo esc_html__( 'Performance and downloads', 'broadcast-archive' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ba-cache"><?php echo esc_html__( 'Feed cache', 'broadcast-archive' ); ?></label></th>
						<td>
							<input type="number" min="1" max="1440" step="1" id="ba-cache" name="broadcast_archive_options[cache_minutes]" value="<?php echo esc_attr( (string) $options['cache_minutes'] ); ?>" class="small-text" />
							<?php echo esc_html__( 'minutes', 'broadcast-archive' ); ?>
							<p class="description"><?php echo esc_html__( 'How long the parsed feed is reused before the archive is asked again. Visitors never wait for the archive.', 'broadcast-archive' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Downloads', 'broadcast-archive' ); ?></th>
						<td>
							<label><input type="checkbox" name="broadcast_archive_options[download_enabled]" value="1" <?php checked( (int) $options['download_enabled'], 1 ); ?> /> <?php echo esc_html__( 'Allow visitors to download broadcasts', 'broadcast-archive' ); ?></label>
							<p class="description"><?php echo esc_html__( 'Files are streamed through this site so the download gets a readable file name.', 'broadcast-archive' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ba-extra-hosts"><?php echo esc_html__( 'Extra download hosts', 'broadcast-archive' ); ?></label></th>
						<td>
							<textarea id="ba-extra-hosts" name="broadcast_archive_options[extra_download_hosts]" rows="3" class="regular-text code"><?php echo esc_textarea( (string) $options['extra_download_hosts'] ); ?></textarea>
							<p class="description"><?php echo esc_html__( 'Optional. One host per line, for audio served from a CDN instead of the feed host. Everything else is rejected.', 'broadcast-archive' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<h2 class="title"><?php echo esc_html__( 'Shortcode', 'broadcast-archive' ); ?></h2>
			<p><code>[broadcast_archive]</code></p>
			<p class="description">
				<?php echo esc_html__( 'Optional attributes: per_page="24", station="Salland1", accent="#e50526", search="1", programs="1", playlist="1", download="1".', 'broadcast-archive' ); ?>
			</p>
		</div>
		<?php
	}
}
