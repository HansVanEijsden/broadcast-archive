<?php
/**
 * Parser tests. Run with: php tests/run.php
 *
 * @package BroadcastArchive
 */

declare( strict_types=1 );

require_once __DIR__ . '/bootstrap.php';

$GLOBALS['ba_checks']   = 0;
$GLOBALS['ba_failures'] = array();

/**
 * Assert a condition.
 *
 * @param bool   $condition Condition.
 * @param string $label     Description.
 * @param mixed  $detail    Optional detail.
 * @return void
 */
function check( bool $condition, string $label, mixed $detail = null ): void {
	++$GLOBALS['ba_checks'];

	if ( ! $condition ) {
		$GLOBALS['ba_failures'][] = $label . ( null !== $detail ? ' — ' . var_export( $detail, true ) : '' );
	}
}

/**
 * Assert equality.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual   Actual.
 * @param string $label    Description.
 * @return void
 */
function same( mixed $expected, mixed $actual, string $label ): void {
	check(
		$expected === $actual,
		$label,
		array(
			'expected' => $expected,
			'actual'   => $actual,
		)
	);
}

/**
 * Find an item by title fragment.
 *
 * @param array<int, array<string, mixed>> $items Items.
 * @param string                           $needle Fragment.
 * @return array<string, mixed>
 */
function find_item( array $items, string $needle ): array {
	foreach ( $items as $item ) {
		if ( str_contains( (string) $item['title'], $needle ) ) {
			return $item;
		}
	}

	return array();
}

$timezone = new DateTimeZone( 'Europe/Amsterdam' );
$xml      = (string) file_get_contents( __DIR__ . '/fixtures/feed.xml' );
$payload  = BroadcastArchive\Feed::parse( $xml, 'Salland1', $timezone, 'nl-NL' );
$items    = $payload['items'];

same( 7, $payload['count'], 'Seven playable items, the item without audio is skipped' );
same( 'De Nachtvlucht - 26 November 2026 (00:00 - 01:00)', $items[0]['title'], 'Newest broadcast first' );

$afternoon = find_item( $items, 'Goedemiddag Salland' );
same( 'Goedemiddag Salland', $afternoon['show'], 'Programme name is cut before the date' );
same( '2026-11-25', $afternoon['date'], 'Dutch month name parsed' );
same( '15:00 – 16:00', $afternoon['time'], 'Time range from title' );
same( '2026-11-25T15:00:00+01:00', $afternoon['start'], 'Start in station timezone' );
same( '2026-11-25T16:00:00+01:00', $afternoon['end'], 'End in station timezone' );
same( 3612, $afternoon['duration'], 'iTunes duration parsed' );
same( '', $afternoon['presenter'], 'Station name as author becomes empty' );
same( 'https://archive.salland1.nl/wednesday_15-16.jpg', $afternoon['image'], 'Item artwork from itunes:image' );
same( 16303506, $afternoon['size'], 'Enclosure size' );
same( 'audio/mp4', $afternoon['type'], 'Enclosure type' );
same( 'https://archive.salland1.nl/wednesday_15-16.m4a', $afternoon['audio'], 'Enclosure URL' );
same( 'Elke werkdag de beste muziek allertijden.', $afternoon['description'], 'Playlist lines removed from description' );
same( 4, count( $afternoon['playlist'] ), 'Playlist extracted' );
same( '15:04', $afternoon['playlist'][0]['time'], 'Playlist time' );
same( 'Krezip', $afternoon['playlist'][0]['artist'], 'Playlist artist' );
same( 'Plug It In & Turn Me On', $afternoon['playlist'][0]['title'], 'Entities decoded in playlist' );
same( 'Sallandschijf - Feels Like Heaven', $afternoon['playlist'][3]['title'], 'Split only on the first separator' );

$midnight = find_item( $items, 'De Nachtvlucht' );
same( '2026-11-26', $midnight['date'], 'Midnight broadcast keeps its own date' );
same( 'https://archive.salland1.nl/thursday_00-01.jpg', $midnight['image'], 'Cover guessed from audio URL without HTTP requests' );

$late = find_item( $items, 'Avond aan de IJssel' );
same( '2026-11-25T00:00:00+01:00', $late['end'], 'End time wraps to the next day' );
same( '2026-11-24', $late['date'], 'Date stays the broadcast date' );

$breakfast = find_item( $items, 'Breakfast Show' );
same( 'Jane Doe', $breakfast['presenter'], 'Station prefix stripped from presenter' );
same( 2730, $breakfast['duration'], 'MM:SS duration parsed' );

$sunday = find_item( $items, 'Zondagochtend' );
same( '2026-11-23', $sunday['date'], 'Falls back to pubDate when the title has no date' );
same( '09:30', $sunday['time'], 'Single time label without a range' );
same( 'Zondagochtend', $sunday['show'], 'Title without a date stays the programme' );

$late_night = find_item( $items, 'Late Night' );
same( '2026-11-22', $late_night['date'], 'ISO date parsed' );
same( '22:00 – 23:30', $late_night['time'], 'Dotted time range parsed' );

$morning = find_item( $items, 'Ochtendshow' );
check( ! str_contains( $morning['description'], '<' ), 'HTML removed from description', $morning['description'] );
check( str_contains( $morning['description'], 'Goedemorgen & welkom' ), 'Entities decoded in description' );
check( str_contains( $morning['description'], 'Tweede regel met uitleg.' ), 'Paragraphs kept' );

$ids = array_column( $items, 'id' );
same( count( $ids ), count( array_unique( $ids ) ), 'Episode ids are unique' );

$second      = BroadcastArchive\Feed::parse( $xml, 'Salland1', $timezone, 'nl-NL' );
$second_ids  = array_column( $second['items'], 'id' );
same( $ids, $second_ids, 'Episode ids are stable across parses' );

$atom = BroadcastArchive\Feed::parse(
	(string) file_get_contents( __DIR__ . '/fixtures/atom.xml' ),
	'Example FM',
	$timezone,
	'en-GB'
);

same( 1, $atom['count'], 'Atom feed parsed' );
same( 'Morning Show', $atom['items'][0]['show'], 'Atom title parsed' );
same( 'Chris', $atom['items'][0]['presenter'], 'Atom itunes:author parsed and station prefix stripped' );
same( 3540, $atom['items'][0]['duration'], 'Atom duration parsed' );
same( 'https://example.org/morning.jpg', $atom['items'][0]['image'], 'Atom cover derived from enclosure' );
same( 'Hello & welcome to the morning show.', $atom['items'][0]['description'], 'Atom summary decoded' );

same( 3612, BroadcastArchive\Episode::duration_seconds( '1:00:12' ), 'H:MM:SS duration' );
same( 2730, BroadcastArchive\Episode::duration_seconds( '45:30' ), 'MM:SS duration' );
same( 3612, BroadcastArchive\Episode::duration_seconds( '3612' ), 'Seconds duration' );
same( 0, BroadcastArchive\Episode::duration_seconds( '' ), 'Empty duration' );

$date = BroadcastArchive\Episode::find_date( 'Uitzending - 1e mei 2026 (10:00 - 11:00)' );
same( array( 'year' => 2026, 'month' => 5, 'day' => 1 ), array_intersect_key( $date, array( 'year' => 1, 'month' => 1, 'day' => 1 ) ), 'Dutch ordinal date' );

$date = BroadcastArchive\Episode::find_date( 'Show 25 Nov. 2026' );
same( 11, $date['month'], 'Abbreviated month with period' );

$date = BroadcastArchive\Episode::find_date( 'Show 25/11/2026' );
same( 25, $date['day'], 'Numeric day-first date' );

$time = BroadcastArchive\Episode::find_time_range( 'Show (15.00 tot 16.30)' );
same( array( 15, 0, 16, 30 ), $time, 'Dotted time range with "tot"' );

same( 'Ochtendshow', BroadcastArchive\Episode::derive_show( '25 November 2026 - Ochtendshow (09:00 - 10:00)', BroadcastArchive\Episode::find_date( '25 November 2026 - Ochtendshow (09:00 - 10:00)' ), 'Salland1' ), 'Date-first title keeps the programme' );
same( 'Goedemiddag Salland', BroadcastArchive\Episode::derive_show( '15:00 Goedemiddag Salland - 25 November 2026', BroadcastArchive\Episode::find_date( '15:00 Goedemiddag Salland - 25 November 2026' ), 'Salland1' ), 'Leading time is removed from the programme' );
same( 'Ochtendshow', BroadcastArchive\Episode::derive_show( 'Ochtendshow (09:00 - 10:00)', null, 'Salland1' ), 'Time range without date is removed' );

same( 'Jane Doe', BroadcastArchive\Episode::clean_presenter( 'Artiest: Jane Doe', 'Salland1' ), 'Artiest prefix stripped' );
same( '', BroadcastArchive\Episode::clean_presenter( 'Salland1', 'Salland1' ), 'Station-only presenter becomes empty' );
same( 'https://example.org/a/b.jpg', BroadcastArchive\Episode::derived_image( 'https://example.org/a/b.m4a?token=1' ), 'Derived cover ignores query string' );

$raw = BroadcastArchive\Episode::from_raw(
	array(
		'title'     => 'Unsafe - 25 November 2026 (10:00 - 11:00)',
		'audio'     => 'javascript:alert(1)',
		'published' => 'Wed, 25 Nov 2026 10:00:00 +0000',
	),
	$timezone,
	'Salland1'
);
same( null, $raw, 'Non-http audio URLs are rejected' );

$threw = false;
try {
	BroadcastArchive\Feed::parse( '<?xml version="1.0"?><!DOCTYPE rss [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><rss></rss>' );
} catch ( RuntimeException $exception ) {
	$threw = true;
}
check( $threw, 'Documents with a DOCTYPE are rejected' );

$threw = false;
try {
	BroadcastArchive\Feed::parse( 'not xml at all' );
} catch ( RuntimeException $exception ) {
	$threw = true;
}
check( $threw, 'Invalid XML is rejected' );

$programs = array_column( $payload['programs'], 'count', 'name' );
same( 1, $programs['Goedemiddag Salland'] ?? 0, 'Programme counts are included' );

if ( $GLOBALS['ba_failures'] ) {
	echo "FAIL (" . count( $GLOBALS['ba_failures'] ) . '/' . $GLOBALS['ba_checks'] . ")\n";

	foreach ( $GLOBALS['ba_failures'] as $failure ) {
		echo ' - ' . $failure . "\n";
	}

	exit( 1 );
}

echo 'OK — ' . $GLOBALS['ba_checks'] . " assertions passed\n";
exit( 0 );
