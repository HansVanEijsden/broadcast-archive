---
version: 1
slug: "page-uitzending-gemist"
primary_target: "page:/uitzending-gemist"
related_targets: []
---

Scope: the "listen again" surface rendered by the `[broadcast_archive]` shortcode on a station page. One shortcode, one route, embedded in the station's existing theme. Mode: Operate — the visitor arrives to find and start a specific broadcast.

Audience: a listener on a phone or laptop who missed a programme, knows roughly the day and hour, and wants to listen now. Job: locate the broadcast fast, start it, keep browsing without losing playback. Also the editor who installs the plugin for another station.

Chosen direction: a broadcast diary. The day and the time slot are the spine; episodes are schedule rows, not a grid of cards. All playback happens in one persistent bottom transport, so the list stays a list and listening survives search, filters and scrolling.

Memorable moment: pressing play turns the quiet schedule row into the station's transport bar, which rises from the bottom edge and carries the programme, a scrubber and ±15 s controls. It stays there while the listener keeps browsing.

## Direction contract

THESIS: A radio schedule you can play. Time is the organising element, one persistent transport is the only player; it refuses the category default of identical rounded cards, decorative covers per row and a player duplicated inside every row.

OWN-WORLD: Light editorial surface inherited from the Newspaper theme; hairline rules, a quiet time rail with tabular numerals, tinted row hover, station red `#e50526` only for play state, current position and primary action. Icons are authored one-stroke SVGs. The transport is a dark ink bar that contrasts with the light page, so it reads as hardware, not another card.

STORY: The visitor sees today first, recognises the hour and programme, presses play, and keeps filtering while it plays. A returning visitor sees where they stopped and resumes.

FIRST VIEWPORT: Page title already exists in the theme; the plugin opens with one compact toolbar — search field (grows), programme select, day chips scrolling horizontally — then the first day heading and its rows. The first row starts within the first screen on mobile. No hero, no intro paragraph, no cover collage.

FORM: Schedule/diary, chosen from the surface's own content (hourly slots, seven days, one-week feed). Seed: none (pinned by the confirmed brief).

FINISH: unreviewed and undocumented is unfinished; this build ends with the finish review, the verdict, DESIGN.md, and every shipping raster carrying its provenance.
