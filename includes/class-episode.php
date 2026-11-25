<?php
/**
 * Normalise one feed item into the array the front end consumes.
 *
 * @package BroadcastArchive
 */

declare( strict_types=1 );

namespace BroadcastArchive;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Feed item normaliser.
 *
 * Everything here is pure PHP (plus wp_strip_all_tags) so it can be unit
 * tested without booting WordPress.
 */
final class Episode {

	/**
	 * Month names and abbreviations, several languages.
	 *
	 * Keys are lower case; values are month numbers.
	 *
	 * @var array<string, int>
	 */
	private const MONTHS = array(
		'januari' => 1,
		'january' => 1,
		'januar'  => 1,
		'janvier' => 1,
		'janv'    => 1,
		'enero'   => 1,
		'jan'     => 1,
		'februari' => 2,
		'february' => 2,
		'februar'  => 2,
		'février'  => 2,
		'fevrier'  => 2,
		'févr'     => 2,
		'fevr'     => 2,
		'febrero'  => 2,
		'feb'      => 2,
		'maart'    => 3,
		'march'    => 3,
		'märz'     => 3,
		'marz'     => 3,
		'mars'     => 3,
		'marzo'    => 3,
		'mrt'      => 3,
		'mar'      => 3,
		'april'    => 4,
		'avril'    => 4,
		'abril'    => 4,
		'apr'      => 4,
		'mei'      => 5,
		'may'      => 5,
		'mai'      => 5,
		'mayo'     => 5,
		'juni'     => 6,
		'june'     => 6,
		'juin'     => 6,
		'junio'    => 6,
		'jun'      => 6,
		'juli'     => 7,
		'july'     => 7,
		'juillet'  => 7,
		'julio'    => 7,
		'jul'      => 7,
		'augustus' => 8,
		'august'   => 8,
		'août'     => 8,
		'aout'     => 8,
		'agosto'   => 8,
		'aug'      => 8,
		'september' => 9,
		'septembre' => 9,
		'septiembre' => 9,
		'setiembre' => 9,
		'sept'     => 9,
		'sep'      => 9,
		'oktober'  => 10,
		'october'  => 10,
		'octobre'  => 10,
		'octubre'  => 10,
		'okt'      => 10,
		'oct'      => 10,
		'november' => 11,
		'novembre' => 11,
		'noviembre' => 11,
		'nov'      => 11,
		'december' => 12,
		'décembre' => 12,
		'decembre' => 12,
		'diciembre' => 12,
		'dec'      => 12,
	);

	/**
	 * Build the normalised episode, or null when it has no playable audio.
	 *
	 * @param array<string, mixed> $raw     Raw feed fields.
	 * @param \DateTimeZone        $tz      Station timezone.
	 * @param string               $station Station name.
	 * @return array<string, mixed>|null
	 */
	public static function from_raw( array $raw, \DateTimeZone $tz, string $station ): ?array {
		$title = self::clean_text( (string) ( $raw['title'] ?? '' ) );
		$audio = self::url( (string) ( $raw['audio'] ?? '' ) );

		if ( '' === $audio ) {
			return null;
		}

		$date_match = self::find_date( $title );
		$time_range = self::find_time_range( $title );

		$start = null;

		if ( null !== $date_match ) {
			$hour   = null !== $time_range ? $time_range[0] : 0;
			$minute = null !== $time_range ? $time_range[1] : 0;

			if ( checkdate( $date_match['month'], $date_match['day'], $date_match['year'] ) && $hour < 24 && $minute < 60 ) {
				$start = ( new \DateTimeImmutable( 'now', $tz ) )
					->setDate( $date_match['year'], $date_match['month'], $date_match['day'] )
					->setTime( $hour, $minute, 0 );
			}
		}

		if ( null === $start && ! empty( $raw['published'] ) ) {
			$published = (string) $raw['published'];
			$timestamp = strtotime( $published );

			if ( false !== $timestamp ) {
				$start = ( new \DateTimeImmutable( '@' . $timestamp ) )->setTimezone( $tz );
			}
		}

		$duration = self::duration_seconds( (string) ( $raw['duration'] ?? '' ) );

		$end = null;

		if ( null !== $start && null !== $time_range ) {
			$end = $start->setTime( $time_range[2], $time_range[3], 0 );

			if ( $end <= $start ) {
				$end = $end->modify( '+1 day' );
			}
		}

		if ( null === $end && null !== $start && $duration > 0 ) {
			$end = $start->modify( '+' . $duration . ' seconds' );
		}

		if ( $duration <= 0 && null !== $start && null !== $end ) {
			$duration = $end->getTimestamp() - $start->getTimestamp();
		}

		$show = self::derive_show( $title, $date_match, $station );

		$description = self::description_text( (string) ( $raw['description'] ?? '' ) );
		$playlist    = self::extract_playlist( $description );

		if ( array() !== $playlist ) {
			$description = self::strip_playlist_lines( $description );
		}

		$author = self::clean_presenter( (string) ( $raw['author'] ?? '' ), $station );

		$image   = self::url( (string) ( $raw['image'] ?? '' ) );
		$channel = self::url( (string) ( $raw['channel_image'] ?? '' ) );

		if ( '' === $image && '' !== $audio ) {
			$image = self::derived_image( $audio );
		}

		if ( '' === $image && '' !== $channel ) {
			$image = $channel;
		}

		$fallback_image = ( '' !== $channel && $channel !== $image ) ? $channel : '';

		$time_label = '';

		if ( null !== $time_range ) {
			$time_label = sprintf(
				'%02d:%02d – %02d:%02d',
				$time_range[0],
				$time_range[1],
				$time_range[2],
				$time_range[3]
			);
		} elseif ( null !== $start ) {
			$time_label = $start->format( 'H:i' );
		}

		$id_source = (string) ( $raw['guid'] ?? '' );

		if ( '' === $id_source ) {
			// Some feeds reuse the same enclosure URL every week. dir2cast
			// archives are a common example: the live folder always contains
			// e.g. "wednesday_17-18.m4a", which is replaced with a new episode
			// every week. Including the broadcast start keeps resume positions
			// and deep links attached to the episode they belong to instead of
			// leaking into next week's broadcast.
			$id_source = $audio;

			if ( null !== $start ) {
				$id_source .= '#' . $start->format( DATE_ATOM );
			} elseif ( '' !== $title ) {
				$id_source .= '#' . $title;
			}
		}

		$id = substr( hash( 'sha256', $id_source ), 0, 16 );

		return array(
			'id'          => $id,
			'ts'          => null !== $start ? $start->getTimestamp() : 0,
			'title'       => $title,
			'show'        => $show,
			'presenter'   => $author,
			'start'       => null !== $start ? $start->format( DATE_ATOM ) : null,
			'end'         => null !== $end ? $end->format( DATE_ATOM ) : null,
			'date'        => null !== $start ? $start->format( 'Y-m-d' ) : '',
			'time'        => $time_label,
			'duration'    => $duration,
			'description' => $description,
			'playlist'    => $playlist,
			'audio'       => $audio,
			'type'        => self::clean_text( (string) ( $raw['type'] ?? '' ) ),
			'size'        => max( 0, (int) ( $raw['size'] ?? 0 ) ),
			'image'       => $image,
			'fallback'    => $fallback_image,
			'link'        => $audio,
		);
	}

	/**
	 * Find a date written in a title.
	 *
	 * @param string $title Title.
	 * @return array{year:int, month:int, day:int, offset:int, length:int}|null
	 */
	public static function find_date( string $title ): ?array {
		$months = array_keys( self::MONTHS );
		usort( $months, static fn( string $a, string $b ): int => strlen( $b ) <=> strlen( $a ) );
		$month_pattern = implode( '|', array_map( static fn( string $m ): string => preg_quote( $m, '/' ), $months ) );

		// 25 november 2026, 25 Nov. 2026, 1e mei 2026, 25th November 2026.
		if ( preg_match(
			'/(?<!\p{L})(\d{1,2})(?:ste|de|th|st|nd|rd|e)?\.?\s+(?:de\s+)?(' . $month_pattern . ')\.?\s+(\d{4})(?!\p{L})/iu',
			$title,
			$m,
			PREG_OFFSET_CAPTURE
		) ) {
			$month = self::MONTHS[ strtolower( $m[2][0] ) ] ?? null;

			if ( null !== $month ) {
				return array(
					'year'   => (int) $m[3][0],
					'month'  => $month,
					'day'    => (int) $m[1][0],
					'offset' => (int) $m[0][1],
					'length' => strlen( $m[0][0] ),
				);
			}
		}

		// 25-11-2026, 25/11/2026, 25.11.2026 (day first).
		if ( preg_match( '/(?<!\d)(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{4})(?!\d)/', $title, $m, PREG_OFFSET_CAPTURE ) ) {
			return array(
				'year'   => (int) $m[3][0],
				'month'  => (int) $m[2][0],
				'day'    => (int) $m[1][0],
				'offset' => (int) $m[0][1],
				'length' => strlen( $m[0][0] ),
			);
		}

		// 2026-11-25.
		if ( preg_match( '/(?<!\d)(\d{4})-(\d{2})-(\d{2})(?!\d)/', $title, $m, PREG_OFFSET_CAPTURE ) ) {
			return array(
				'year'   => (int) $m[1][0],
				'month'  => (int) $m[2][0],
				'day'    => (int) $m[3][0],
				'offset' => (int) $m[0][1],
				'length' => strlen( $m[0][0] ),
			);
		}

		return null;
	}

	/**
	 * Find a time range in a title.
	 *
	 * @param string $title Title.
	 * @return array{0:int,1:int,2:int,3:int}|null Start hour, start minute, end hour, end minute.
	 */
	public static function find_time_range( string $title ): ?array {
		if ( preg_match(
			'/(?<!\d)(\d{1,2})[:.](\d{2})\s*(?:-|–|—|tot|to|until|bis)\s*(\d{1,2})[:.](\d{2})(?!\d)/iu',
			$title,
			$m
		) ) {
			$start_hour = (int) $m[1];
			$start_min  = (int) $m[2];
			$end_hour   = (int) $m[3];
			$end_min    = (int) $m[4];

			if ( $start_hour < 24 && $start_min < 60 && $end_hour < 24 && $end_min < 60 ) {
				return array( $start_hour, $start_min, $end_hour, $end_min );
			}
		}

		return null;
	}

	/**
	 * Convert an iTunes duration to seconds.
	 *
	 * @param string $value Duration ("1:00:12", "45:30" or "3612").
	 * @return int
	 */
	public static function duration_seconds( string $value ): int {
		$value = trim( $value );

		if ( '' === $value ) {
			return 0;
		}

		if ( ctype_digit( $value ) ) {
			return (int) $value;
		}

		$parts = array_map( 'intval', explode( ':', $value ) );

		if ( 3 === count( $parts ) ) {
			return $parts[0] * 3600 + $parts[1] * 60 + $parts[2];
		}

		if ( 2 === count( $parts ) ) {
			return $parts[0] * 60 + $parts[1];
		}

		return 0;
	}

	/**
	 * Derive the programme name by cutting the date/time part off the title.
	 *
	 * @param string                                            $title      Full title.
	 * @param array{year:int, month:int, day:int, offset:int}|null $date_match Date found in the title.
	 * @param string                                            $station    Station name.
	 * @return string
	 */
	public static function derive_show( string $title, ?array $date_match, string $station ): string {
		$show = $title;

		if ( null !== $date_match ) {
			$show = substr( $title, 0, $date_match['offset'] );

			// A title that starts with the date: use the text after it.
			if ( '' === trim( $show, " \t\n\r\0\x0B-–—|:," ) ) {
				$show = substr( $title, $date_match['offset'] + ( $date_match['length'] ?? 0 ) );
			}

			$show = preg_replace( '/[\s\-–—|:,]+$/u', '', $show ) ?? $show;
			$show = preg_replace( '/^[\s\-–—|:,]+/u', '', $show ) ?? $show;
		}

		// Remove leading or trailing time information that was not the date.
		$show = preg_replace( '/^\s*\d{1,2}[:.]\d{2}\s*(?:-|–|—|tot|to)?\s*/u', '', $show ) ?? $show;
		$show = preg_replace( '/\s+\d{1,2}[:.]\d{2}\s*(?:-|–|—|tot|to)\s*\d{1,2}[:.]\d{2}.*$/u', '', $show ) ?? $show;
		$show = preg_replace( '/\s*[\(\[]\s*\d{1,2}[:.]\d{2}.*$/u', '', $show ) ?? $show;
		$show = preg_replace( '/[\s\-–—|:,]+$/u', '', $show ) ?? $show;

		$show = self::clean_text( $show );

		if ( '' === $show ) {
			$show = $station;
		}

		return $show;
	}

	/**
	 * Clean a presenter name.
	 *
	 * @param string $author  Raw author.
	 * @param string $station Station name.
	 * @return string
	 */
	public static function clean_presenter( string $author, string $station ): string {
		$author = self::clean_text( $author );

		$author = preg_replace( '/^Artiest\s*:\s*/iu', '', $author ) ?? $author;

		if ( '' !== $station ) {
			$author = preg_replace(
				'/^' . preg_quote( $station, '/' ) . '\s*[:\-–—]\s*/iu',
				'',
				$author
			) ?? $author;
		}

		$author = trim( $author, " \t\n\r\0\x0B-–—:" );

		if ( 0 === strcasecmp( $author, $station ) ) {
			return '';
		}

		return $author;
	}

	/**
	 * Turn description HTML into readable plain text.
	 *
	 * The front end writes this with textContent, so no markup is trusted.
	 *
	 * @param string $html Raw description.
	 * @return string
	 */
	public static function description_text( string $html ): string {
		$html = trim( $html );

		if ( '' === $html ) {
			return '';
		}

		$html = preg_replace( '#<br\s*/?>#i', "\n", $html ) ?? $html;
		$html = preg_replace( '#</(?:p|div|li|tr|h[1-6]|blockquote)\s*>#i', "\n", $html ) ?? $html;

		$text = wp_strip_all_tags( $html, false );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );
		$text = preg_replace( '/[ \t]+/u', ' ', $text ) ?? $text;
		$text = preg_replace( '/ *\n */u', "\n", $text ) ?? $text;
		$text = preg_replace( '/\n{3,}/u', "\n\n", $text ) ?? $text;

		$text = trim( $text );

		if ( function_exists( 'mb_substr' ) && mb_strlen( $text, 'UTF-8' ) > 6000 ) {
			$text = mb_substr( $text, 0, 6000, 'UTF-8' );
		}

		return $text;
	}

	/**
	 * Extract a track list from description text.
	 *
	 * Lines like "15:04 - Krezip - Plug It In & Turn Me On" become structured
	 * entries. Two or more such lines make a playlist; one could be prose.
	 *
	 * @param string $text Description text.
	 * @return array<int, array{time:string, artist:string, title:string}>
	 */
	public static function extract_playlist( string $text ): array {
		if ( '' === $text ) {
			return array();
		}

		$entries = array();

		foreach ( preg_split( '/\n/', $text ) ?: array() as $line ) {
			$line = trim( $line );

			if ( '' === $line ) {
				continue;
			}

			if ( ! preg_match( '/^(\d{1,2})[:.](\d{2})\s*[-–—:]?\s*(.+)$/u', $line, $m ) ) {
				continue;
			}

			$hour = (int) $m[1];
			$min  = (int) $m[2];

			if ( $hour > 23 || $min > 59 ) {
				continue;
			}

			$rest = trim( $m[3] );

			// The first " - " separates artist from title.
			$parts = preg_split( '/\s+[-–—]\s+/u', $rest, 2 );

			if ( ! is_array( $parts ) || 2 !== count( $parts ) ) {
				continue;
			}

			$artist = trim( $parts[0] );
			$track  = trim( $parts[1] );

			if ( '' === $artist || '' === $track ) {
				continue;
			}

			$entries[] = array(
				'time'   => sprintf( '%02d:%02d', $hour, $min ),
				'artist' => $artist,
				'title'  => $track,
			);
		}

		if ( count( $entries ) < 2 ) {
			return array();
		}

		return array_slice( $entries, 0, 200 );
	}

	/**
	 * Remove playlist lines and playlist headings from the description.
	 *
	 * @param string $text Description text.
	 * @return string
	 */
	public static function strip_playlist_lines( string $text ): string {
		$kept = array();

		foreach ( preg_split( '/\n/', $text ) ?: array() as $line ) {
			$trimmed = trim( $line );

			if ( preg_match( '/^(?:afspeellijst|speellijst|playlist|muzieklijst|tracks?)\s*:?\s*$/iu', $trimmed ) ) {
				continue;
			}

			if ( preg_match( '/^\d{1,2}[:.]\d{2}\s*[-–—:]?\s*(.+)$/u', $trimmed ) ) {
				continue;
			}

			$kept[] = $line;
		}

		$text = implode( "\n", $kept );
		$text = preg_replace( '/\n{3,}/u', "\n\n", $text ) ?? $text;

		return trim( $text );
	}

	/**
	 * Guess a sibling cover image from the audio URL.
	 *
	 * The archive stores `wednesday_15-16.m4a` next to `wednesday_15-16.jpg`.
	 * No HTTP request is made; the browser falls back to the placeholder when
	 * the guess 404s.
	 *
	 * @param string $audio Audio URL.
	 * @return string
	 */
	public static function derived_image( string $audio ): string {
		$path = (string) wp_parse_url( $audio, PHP_URL_PATH );

		if ( '' === $path || ! preg_match( '/\.[a-z0-9]{2,5}$/i', $path ) ) {
			return '';
		}

		$image = preg_replace( '/\.[a-z0-9]{2,5}$/i', '.jpg', $path ) ?? '';

		if ( '' === $image ) {
			return '';
		}

		$scheme = (string) wp_parse_url( $audio, PHP_URL_SCHEME );
		$host   = (string) wp_parse_url( $audio, PHP_URL_HOST );

		if ( '' === $host ) {
			return '';
		}

		return ( '' !== $scheme ? $scheme : 'https' ) . '://' . $host . $image;
	}

	/**
	 * Validate an absolute http(s) URL.
	 *
	 * @param string $url Raw URL.
	 * @return string Empty when invalid.
	 */
	public static function url( string $url ): string {
		$url = trim( $url );

		if ( '' === $url ) {
			return '';
		}

		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}

		if ( ! in_array( strtolower( (string) $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return '';
		}

		return $url;
	}

	/**
	 * Collapse whitespace and strip control characters.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	public static function clean_text( string $text ): string {
		$text = wp_strip_all_tags( $text, true );
		$text = str_replace( array( "\r\n", "\r", "\n", "\t" ), ' ', $text );
		$text = preg_replace( '/[\x00-\x1F\x7F]/u', '', $text ) ?? $text;
		$text = preg_replace( '/\s{2,}/u', ' ', $text ) ?? $text;

		return trim( $text );
	}
}
