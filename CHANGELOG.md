# Changelog

All notable changes to Broadcast Archive are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/);
the project adheres to [Semantic Versioning](https://semver.org/).

## [2.2.1] — 2026-11-25

### Fixed

- The shared player now enables the archive playlist, so a hub-originated
  episode shows the same Info button and description sheet as the archive page.
- Hosts can mark an item with `hideDuration` to omit the duration from the
  transport meta while keeping it for seeking.

## [2.2.0] — 2026-11-25

### Added

- **Shared bottom player for site hubs.** A new `window.BroadcastArchivePlayer`
  API and a player-only Archive mode let a hub open the same persistent
  transport from its own recent-episode rows. No second audio element, no
  duplicate player chrome.
- **`enqueue_player_assets()`** on the plugin instance, so a site-specific hub
  can load the shared player without rendering the archive shortcode.
- The transport now follows the station accent (`BroadcastArchiveConfig.accent`)
  and dispatches `ba:player-state` events so host lists can reflect play,
  pause, end and close.

## [2.1.0] — 2026-11-25

### Added

- **Clicking an episode's title expands its details.** The title is now a real
  button inside the heading, with the same `aria-expanded`/`aria-controls`
  contract as the chevron. Starting playback from a row also expands that row,
  so the description and playlist are immediately available.
- **Description and playlist in the persistent player.** An Info button in the
  transport opens a light sheet above the dark player with the current
  broadcast's title, time, description and playlist. It stays in sync when the
  playing episode changes, is scrollable, and closes with the close button, the
  Info button or Escape. On phones the button becomes icon-only and the sheet
  uses 64vh.

### Changed

- The expanded row's playlist rendering is shared with the player sheet.

## [2.0.3] — 2026-11-25

### Fixed

- **Download button hover state.** The host theme's global link hover (for
  example the Newspaper theme's `.tagdiv-type a:hover`) overrode the button's
  own `text-decoration: none`, so the secondary Download action turned into
  plain underlined text on hover. The button now keeps its text style in the
  hover, focus, active and visited states, and gets a subtle fill plus an ink
  border on hover.

## [2.0.2] — 2026-11-25

### Added

- **Programme cover in the expanded episode details.** Opening a broadcast now
  shows its cover at the top right of the detail panel (112 px on desktop,
  88 px on tablet, 76 px on phones). The image is lazy-loaded, decorative
  (empty `alt`; the programme name is already the row heading) and falls back
  to the channel image if the episode image cannot be loaded.

### Changed

- **Removed the "1 hour" duration from the episode rows and the expanded
  details.** Every hourly broadcast showed the same value; the time range
  already communicates it. The persistent transport still shows the duration
  for the item that is currently playing.

## [2.0.1] — 2026-11-25

### Fixed

- **Episode ids for feeds that reuse an enclosure URL every week.** Some feeds
  (for example dir2cast radio-hour archives that always serve
  `wednesday_17-18.m4a`) replace the file behind the same URL with a new
  broadcast. The fallback episode id is now derived from the URL *and* the
  parsed broadcast start, so resume positions, the "continue listening" bar and
  deep links stay with the episode they belong to instead of leaking into the
  next week's broadcast. Feeds with a `<guid>` are unchanged.

## [2.0.0] — 2026-11-25

Complete rewrite of the original `salland1-uitzending-gemist` plugin.

### Fixed

- **Intermittent "Security check failed" / "could not load" errors for
  logged-out visitors.** The original plugin embedded a WordPress nonce in the
  shortcode output. salland1.nl serves full-page cached HTML without a TTL, so
  the cached nonce expired after the nonce tick and every anonymous AJAX call
  returned `403`. Logged-in users bypassed the cache, which is why the problem
  only appeared for visitors and disappeared after clearing the page cache.
  Public endpoints are now nonce-free by design.
- Download endpoint streamed the whole file through PHP memory, disabled TLS
  verification and accepted any URL (SSRF). It now validates the host, follows
  no redirects, streams through a temporary file and deletes it.
- Feed descriptions were injected as HTML after naive entity decoding (XSS
  vector). They are now plain text inserted via `textContent`.
- Toggling an episode re-rendered the whole list and destroyed the playing
  `<audio>` element. Playback now lives in one transport outside the list.
- Per-item cover detection made up to five HTTP HEAD requests per episode with
  no timeout budget. Covers now come from the feed with a derived URL and a
  client-side fallback; no HEAD requests.
- The front end crashed on titles outside Latin-1 because `btoa()` was used for
  element ids. Ids are stable hashes generated on the server.

### Added

- Public REST API: `GET /wp-json/broadcast-archive/v1/episodes` and
  `GET /wp-json/broadcast-archive/v1/download`.
- Server-side feed cache with a seven-day last-known-good fallback and
  automatic retry after an archive outage.
- Day-grouped schedule, search, programme and day filters, "load more".
- Persistent transport with seek, ±15 s, media session metadata, volume via
  system controls, download and close.
- Resume positions in `localStorage` plus a "continue listening" row and
  per-row progress.
- Playlist extraction from description lines such as `15:04 - Artist - Title`.
- Settings screen with feed status, cache controls and shortcode help.
- Station-neutral configuration: feed URL, station name, accent colour, locale,
  timezone, page size, cache lifetime, download toggle and extra download hosts.
- Shortcode attributes and the legacy `[salland1_uitzending_gemist]` alias.
- Dutch translation (`nl_NL`), English source strings.
- Parser tests and a WordPress-free design preview.

### Changed

- Plugin identity: `Broadcast Archive` / `broadcast-archive`.
- Front-end assets are only enqueued when a shortcode is present.
- Semantic markup, native buttons, focus styles, ARIA labels, live regions,
  reduced-motion support and 320 px layout.

### Removed

- `admin-ajax.php` endpoints, nonces for public reads, inline styles per item,
  `CURLOPT_SSL_VERIFYPEER => false`, whole-file buffering, the cover grid and
  the duplicated inline player.

## [1.0.3] — 2026-08-05

Legacy Salland1 version: shortcode, `admin-ajax` feed fetch, download endpoint,
day filter, inline `<audio>` player.
