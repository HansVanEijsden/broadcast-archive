# Changelog

All notable changes to Broadcast Archive are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/);
the project adheres to [Semantic Versioning](https://semver.org/).

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
- Parser tests (59 assertions) and a WordPress-free design preview.

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
