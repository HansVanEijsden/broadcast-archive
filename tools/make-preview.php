<?php
/**
 * Development helper: turn an RSS/Atom file into the JSON the preview page
 * consumes. Not loaded by WordPress.
 *
 * Usage: php tools/make-preview.php /path/to/feed.xml preview/episodes.json
 *
 * @package BroadcastArchive
 */

declare( strict_types=1 );

require_once __DIR__ . '/../tests/bootstrap.php';

$feed = $argv[1] ?? '';
$out  = $argv[2] ?? '';

if ( '' === $feed || '' === $out || ! is_readable( $feed ) ) {
	fwrite( STDERR, "Usage: php tools/make-preview.php <feed.xml> <out.json>\n" );
	exit( 1 );
}

$payload = BroadcastArchive\Feed::parse(
	(string) file_get_contents( $feed ),
	'Salland1',
	new DateTimeZone( 'Europe/Amsterdam' ),
	'nl-NL'
);

$json = json_encode(
	$payload,
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);

if ( false === $json ) {
	fwrite( STDERR, "Could not encode JSON\n" );
	exit( 1 );
}

file_put_contents( $out, $json . "\n" );

printf( "%s: %d broadcasts\n", $out, (int) $payload['count'] );
