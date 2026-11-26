=== Broadcast Archive ===
Contributors: hansvaneijsden
Tags: radio, podcast, audio, player, archive, uitzending gemist
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 2.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turn any radio station's podcast feed into a fast, searchable listen-again page with a persistent player, filters and downloads. Station-neutral and safe behind full-page caches.

== Description ==

Broadcast Archive reads a station's existing podcast/archive feed (RSS 2.0 or Atom with audio enclosures) and renders a listen-again page: broadcasts grouped per day, search, programme and day filters, one persistent player, resume support and downloads.

The plugin is station-neutral: feed URL, station name, accent colour, locale, timezone, page size, cache lifetime and download hosts are settings, so one codebase serves several stations.

It is built for aggressively cached WordPress sites. The shortcode output contains no nonce and no per-visitor value, so it can stay in a full-page cache for weeks. Episode data is loaded from public, nonce-free REST endpoints after page load.

Features:

* Day-grouped schedule with time slot, programme, presenter and duration.
* Search over programme, title, description and playlist.
* Programme and day filters; result counts announced to screen readers.
* One persistent transport: playback survives searching, filtering and paging.
* Continue listening: the last position per episode is stored in the browser.
* Playlist parsing from the description.
* Downloads streamed through your domain with a readable filename and a strict host allowlist.
* Cached feed plus a seven-day last-known-good fallback when the archive is unreachable.
* English source strings, Dutch translation included.
* No build step, no Composer/npm dependencies, no external font or icon services.

== Installation ==

1. Upload the `broadcast-archive` folder to `/wp-content/plugins/`, or upload the zip via Plugins → Add New → Upload Plugin.
2. Activate the plugin.
3. Go to Settings → Broadcast Archive and enter the archive feed URL.
4. Add `[broadcast_archive]` to a page. The legacy shortcode `[salland1_uitzending_gemist]` is also supported.

== Frequently Asked Questions ==

= Why is there no nonce on the AJAX/REST calls? =

The endpoints only read public data and stream public files; there is no state to protect. A nonce would expire while the page stays cached and break every logged-out visitor, which is exactly the bug this version fixes.

= Does it work with a full-page cache? =

Yes. The shortcode output is identical for every visitor and contains no per-user values. Episode data comes from `/wp-json/broadcast-archive/v1/episodes`, which most cache configurations bypass (and which sends its own short cache lifetime).

= How fresh is the episode list? =

The parsed feed is cached for five minutes by default (configurable). Visitors never wait for the archive host.

= What happens if the archive is down? =

The last good payload is served for up to seven days. Without one, visitors get a friendly error with a retry button.

= Can I use a CDN for the audio files? =

Yes. Add the CDN host under "Extra download hosts". All other hosts are rejected by the download proxy.

= Can I use it for a second station? =

Yes. Install the same plugin on the second site and set that station's feed URL, name, colour and locale. Options are per site.

= Does it need JavaScript? =

Playback, search and filters need JavaScript, as any audio player does. Without it, the page links to the archive feed directly.

== Screenshots ==

1. Desktop schedule with the persistent player.
2. Mobile schedule with the transport open.

== Changelog ==

= 2.3.0 =
* Artist names in the playlist can link to artist pages through the `broadcast_archive_payload` filter; plain text without a companion plugin.
* Shareable `?q=` search URLs, kept in sync with the address bar.
* Static `PodcastSeries` JSON-LD, meta description and Open Graph image for the archive page.

= 2.0.0 =
* Rewrite. Fixed intermittent "Security check failed" / failed-to-load errors for logged-out visitors caused by a nonce embedded in page-cached HTML.
* Public, nonce-free REST endpoints; episode payload cached server-side with a last-known-good fallback.
* Station-neutral settings (feed, station name, accent, locale, timezone, page size, cache, download hosts).
* New schedule UI, search, programme/day filters, playlist parsing, persistent player, resume, downloads.
* Secure download proxy with host allowlist, streamed temp files and no redirects.
* Dutch translation, accessible controls, reduced-motion support.

= 1.0.3 =
* Legacy Salland1 plugin: shortcode, admin-ajax feed fetch and download endpoint.
