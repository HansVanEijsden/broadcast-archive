<?php
/**
 * Shortcode output and per-instance configuration.
 *
 * @package BroadcastArchive
 */

declare( strict_types=1 );

namespace BroadcastArchive;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the listen-again surface.
 */
final class Shortcode {

	/**
	 * Instance counter for unique DOM ids.
	 *
	 * @var int
	 */
	private static int $instances = 0;

	/**
	 * Render the shortcode.
	 *
	 * @param array<string, string>|string $atts Shortcode attributes.
	 * @return string
	 */
	public static function render( $atts ): string {
		$settings = Settings::get();

		$atts = shortcode_atts(
			array(
				'per_page' => (string) ( $settings['items_per_page'] ?? 24 ),
				'station'  => '',
				'accent'   => '',
				'search'   => (string) ( $settings['show_search'] ?? 1 ),
				'programs' => (string) ( $settings['show_program_filter'] ?? 1 ),
				'playlist' => (string) ( $settings['show_playlist'] ?? 1 ),
				'download' => (string) ( $settings['download_enabled'] ?? 1 ),
				'class'    => '',
			),
			is_array( $atts ) ? $atts : array(),
			'broadcast_archive'
		);

		$feed_url = Settings::feed_url();

		if ( '' === $feed_url ) {
			return self::render_unconfigured();
		}

		Plugin::instance()->enqueue_assets();

		$per_page = max( 6, min( 100, (int) $atts['per_page'] ) );

		$accent = sanitize_hex_color( (string) $atts['accent'] );
		$accent = $accent ? $accent : Settings::accent_color();

		$station = trim( (string) $atts['station'] );
		$station = '' !== $station ? $station : Settings::station_name();

		$classes = array( 'ba' );

		if ( ! empty( $atts['class'] ) ) {
			foreach ( preg_split( '/\s+/', (string) $atts['class'] ) ?: array() as $extra ) {
				$extra = sanitize_html_class( $extra );

				if ( '' !== $extra ) {
					$classes[] = $extra;
				}
			}
		}

		++self::$instances;

		$id = 1 === self::$instances ? 'broadcast-archive' : 'broadcast-archive-' . self::$instances;

		$data = array(
			'data-ba-root'     => '',
			'data-ba-per-page' => (string) $per_page,
			'data-ba-station'  => $station,
			'data-ba-search'   => self::flag( $atts['search'] ) ? '1' : '0',
			'data-ba-programs' => self::flag( $atts['programs'] ) ? '1' : '0',
			'data-ba-playlist' => self::flag( $atts['playlist'] ) ? '1' : '0',
			'data-ba-download' => self::flag( $atts['download'] ) && (bool) Settings::option( 'download_enabled', 1 ) ? '1' : '0',
		);

		$attributes = '';

		foreach ( $data as $name => $value ) {
			$attributes .= ' ' . $name . ( '' !== $value ? '="' . esc_attr( $value ) . '"' : '' );
		}

		$style = '--ba-accent:' . esc_attr( $accent ) . ';';

		ob_start();
		?>
		<div id="<?php echo esc_attr( $id ); ?>" class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>"<?php echo $attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attributes escaped above. ?> style="<?php echo esc_attr( $style ); ?>" aria-busy="true">
			<div class="ba-skeleton" aria-hidden="true">
				<div class="ba-skeleton__toolbar">
					<span class="ba-skeleton__block ba-skeleton__block--search"></span>
					<span class="ba-skeleton__block ba-skeleton__block--select"></span>
				</div>
				<div class="ba-skeleton__days">
					<span class="ba-skeleton__block ba-skeleton__block--chip"></span>
					<span class="ba-skeleton__block ba-skeleton__block--chip"></span>
					<span class="ba-skeleton__block ba-skeleton__block--chip"></span>
					<span class="ba-skeleton__block ba-skeleton__block--chip"></span>
				</div>
				<div class="ba-skeleton__day"></div>
				<?php for ( $i = 0; $i < 6; $i++ ) : ?>
					<div class="ba-skeleton__row">
						<span class="ba-skeleton__block ba-skeleton__block--time"></span>
						<span class="ba-skeleton__block ba-skeleton__block--title"></span>
						<span class="ba-skeleton__block ba-skeleton__block--meta"></span>
					</div>
				<?php endfor; ?>
			</div>
			<p class="ba-sr-only ba-status" role="status"><?php echo esc_html__( 'Loading broadcasts…', 'broadcast-archive' ); ?></p>
		</div>
		<noscript>
			<p class="ba-noscript"><?php echo esc_html__( 'This overview needs JavaScript. You can open the archive directly:', 'broadcast-archive' ); ?> <a href="<?php echo esc_url( $feed_url ); ?>"><?php echo esc_html__( 'Open the archive', 'broadcast-archive' ); ?></a>.</p>
		</noscript>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Message when no feed URL is configured.
	 *
	 * @return string
	 */
	private static function render_unconfigured(): string {
		Plugin::instance()->enqueue_assets();

		$message = '<div class="ba ba-notice">';

		if ( current_user_can( 'manage_options' ) ) {
			$message .= '<p>' . esc_html__( 'Broadcast Archive is active, but no feed URL has been configured yet.', 'broadcast-archive' ) . '</p>';
			$message .= '<p><a class="button" href="' . esc_url( admin_url( 'options-general.php?page=broadcast-archive' ) ) . '">' . esc_html__( 'Configure the feed', 'broadcast-archive' ) . '</a></p>';
		} else {
			$message .= '<p>' . esc_html__( 'The broadcast archive is currently unavailable. Please try again later.', 'broadcast-archive' ) . '</p>';
		}

		$message .= '</div>';

		return $message;
	}

	/**
	 * Interpret a shortcode boolean.
	 *
	 * @param mixed $value Attribute value.
	 * @return bool
	 */
	private static function flag( mixed $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		return filter_var( (string) $value, FILTER_VALIDATE_BOOLEAN );
	}
}
