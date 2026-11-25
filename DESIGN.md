---
name: Broadcast Archive
description: A broadcast diary with one persistent transport, inside the station's own theme.
colors:
  accent: "#e50526"
  accent-ink: "#b3041e"
  ink: "#14161a"
  text: "#191c21"
  muted: "#5a6371"
  line: "#e2e5e9"
  line-strong: "#c9ced5"
  surface: "#ffffff"
  surface-alt: "#f6f7f9"
  error-surface: "#fff5f6"
  error-line: "#f0c9cf"
  error-text: "#5a3c40"
  transport-bg: "#14161a"
  transport-text: "#f4f5f7"
  transport-muted: "#a7aeb9"
  transport-line: "#3a3f48"
typography:
  body:
    fontFamily: "inherit (host theme sans: Verdana / system-ui stack)"
    fontSize: "15px"
    fontWeight: 400
    lineHeight: 1.45
  title:
    fontSize: "15px"
    fontWeight: 650
    lineHeight: 1.32
  meta:
    fontSize: "12.5px"
    fontWeight: 400
    lineHeight: 1.45
  label:
    fontSize: "11.5px"
    fontWeight: 700
    lineHeight: 1.4
    letterSpacing: "0.06em"
  transport-title:
    fontSize: "14px"
    fontWeight: 650
    lineHeight: 1.3
rounded:
  sm: "6px"
  md: "8px"
  lg: "10px"
  pill: "999px"
spacing:
  xs: "4px"
  sm: "8px"
  md: "12px"
  lg: "16px"
  xl: "22px"
  xxl: "32px"
components:
  button-primary:
    backgroundColor: "{colors.accent}"
    textColor: "{colors.surface}"
    rounded: "{rounded.md}"
    padding: "0 14px"
    height: "40px"
  button-primary-hover:
    backgroundColor: "{colors.accent-ink}"
    textColor: "{colors.surface}"
  button-ghost:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.text}"
    rounded: "{rounded.md}"
    padding: "0 14px"
    height: "40px"
  search-input:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.text}"
    rounded: "{rounded.md}"
    padding: "0 42px 0 40px"
    height: "44px"
  day-chip:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.text}"
    rounded: "{rounded.pill}"
    padding: "6px 13px"
    height: "38px"
  day-chip-active:
    backgroundColor: "{colors.ink}"
    textColor: "{colors.surface}"
  episode-play:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink}"
    rounded: "{rounded.pill}"
    size: "42px"
  episode-play-active:
    backgroundColor: "{colors.accent}"
    textColor: "{colors.surface}"
  transport:
    backgroundColor: "{colors.transport-bg}"
    textColor: "{colors.transport-text}"
---

# Design System: Broadcast Archive

## Overview

**Creative North Star: "The Broadcast Diary"**

This is a schedule you can play, not a media library in a card grid. The day is
the section, the time slot is the spine, and a programme is a row you scan the
way you scan a printed radio guide. The surface belongs to the station's own
newspaper theme: it inherits the type, the page width and the light ground, and
adds only structure, hairlines and one dark piece of hardware at the bottom.

The memorable moment is the transport. Pressing play on a quiet schedule row
raises one dark bar from the bottom edge with the programme, a scrubber and
±15-second controls. It stays there while the listener searches, filters and
scrolls, so the list never has to contain a second player and playback never
dies when the list re-renders.

**Key Characteristics:**

- Time-first hierarchy: the hour is the largest repeated anchor after the title.
- One transport for the whole surface; no inline duplicate players.
- Station red is reserved for playback, position, primary actions and focus.
- Flat schedule: hairline rules and tonal panels, no card shadows per row.
- Inherited typography; no webfont, no icon font, no external asset service.
- Every pixel is identical for every visitor, so the page can stay cached.

## Colors

A restrained editorial neutral field, one signal red, and a near-black plane for
the transport. The palette is deliberately small; hierarchy comes from space,
weight and hairlines rather than extra tints.

### Primary

- **Playback Red** (`#e50526`): the station's house red. Used for the play
  button of the current episode, the progress fill, the primary action in an
  expanded episode, the resume icon and focus rings. White text on it measures
  about 4.79:1, so it is safe for the 13.5 px button labels.
- **Playback Red Ink** (`#b3041e`): the pressed/hover state of red buttons only.

### Neutral

- **Ink** (`#14161a`): selected day chip, the transport ground and the strongest
  dark plane. Never used for long body copy.
- **Text Ink** (`#191c21`): titles, row text and settings labels. About 15:1 on
  white.
- **Studio Grey** (`#5a6371`): secondary metadata, counts, time-of-day end time
  and descriptions. About 6:1 on white.
- **Hairline** (`#e2e5e9`): row separators.
- **Hairline Strong** (`#c9ced5`): field and control outlines.
- **Paper** (`#ffffff`): row background and fields.
- **Paper Tint** (`#f6f7f9`): hover rows and the expanded episode panel.
- **Error Wash** (`#fff5f6`) with **Error Edge** (`#f0c9cf`) and **Error Ink**
  (`#5a3c40`): only the load-failure panel.
- **Transport Grey** (`#a7aeb9`) on the transport, about 8:1 against the
  near-black ground.

### Named Rules

**The Playback-Only Red Rule.** Red marks playback, current position, the
primary action and focus. It is not decoration: no red borders, no red heading
rules, no red eyebrows, no second red button competing with play.

**The Dark Hardware Rule.** Only the transport is dark. The page stays light so
the plugin reads as part of the station's site, not as a dark media app pasted
into it.

## Typography

**Display Font:** inherited from the host theme (Salland1: the Newspaper theme's
sans/roboto stack).
**Body Font:** inherited from the host theme (Verdana/system sans in the
newspaper body).
**Label Font:** the same family; labels differ by weight, size and tracking.

**Character:** workhorse product typography that disappears into the station's
site. The plugin never ships a webfont: the page's own face is the brand. Time
is the only place numerals get special treatment.

### Hierarchy

- **Day heading** (700, 16 px, 1.3): "Woensdag 25 november 2026", with the count
  pushed to the right edge in 12 px muted text.
- **Row title** (650, 15 px, 1.32): the programme name. Wraps at 320 px rather
  than truncating to an unrecognisable stub.
- **Meta** (400, 12.5 px): time, presenter, duration and excerpt. One line,
  ellipsis when needed.
- **Label** (700, 11.5 px, 0.06em, uppercase): "Speellijst", "Verder luisteren".
  Short functional labels only.
- **Transport title** (650, 14 px): one line, ellipsis; the transport is a
  glanceable piece of hardware, not a reading surface.

### Named Rules

**The Tabular Time Rule.** Every time, duration and position uses
`font-variant-numeric: tabular-nums`. The time rail must stay on the same
optical column as the digits change from 9:00 to 10:00.

## Layout

The surface is a single column that fills the host's content width. On Salland1
that is 696 px inside the Newspaper theme; the plugin itself sets no maximum
and relies on the page.

The schedule uses one row grid: play button `3rem`, time rail `5.25rem`, a
flexible body, and a `2.5rem` details button, with 12 px gaps and 8 px block
padding. Day sections are separated by 22 px and a 1 px rule. The expanded
panel is inset by 4.75rem so it aligns under the body text, and the inner panel
uses 16 px padding, 10 px radius and the paper tint.

Responsive changes are structural, not typographic: below 560 px the toolbar
stacks; below 680 px the time rail disappears and the meta line leads with the
time; below 420 px the transport drops the artwork and below 360 px it tightens its controls.
Touch targets stay at 38–44 px. The page never overflows horizontally at
320 px.

Spacing rhythm: 4 / 8 / 12 / 16 / 22 / 32 px. Tight inside a row, generous
between days. More space above a day heading than below it.

## Elevation & Depth

Flat by default. Rows are separated by 1 px hairlines and state is carried by
tone: paper white at rest, paper tint on hover, a tinted panel for expanded
content. There are no card shadows on rows, chips or fields.

The only depth event is the transport: a fixed, full-width near-black bar that
runs edge to edge, separated from the page by a 1 px light border on its top
edge. It earns attention through contrast and position, not through a floating
shadow. The focus ring is the only glow-like effect (a 3 px translucent red
ring); it is a state, not decoration.

## Shapes

Corners are small and consistent: 8 px for fields and buttons, 10 px for the
expanded panel, 6 px for the transport artwork. Filter chips are full pills
(`999px`) and play buttons are perfect circles. Nothing is clipped, notched or
masked. The form language is the station's existing editorial rectangles with
one softer pill row for dates.

Borders are 1 px. The active day chip inverts to ink; the play button inverts
to red only while that episode is playing.

## Components

### Buttons

- **Shape:** 8 px radius, 40 px height, 14 px inline padding.
- **Primary:** playback red with white text and a leading one-stroke icon.
  Hover deepens to `#b3041e`.
- **Ghost:** paper with a strong hairline; hover darkens the border to ink.
- **Play circle:** 42 px (44 px on small screens), 1 px strong hairline at rest,
  red fill and white pause glyph while the episode is current.
- **Focus:** 2 px red outline at 2 px offset on light surfaces; a white outline
  on the transport.

### Chips (day filter)

- **Style:** 38 px pill, 13 px/600 text, 1 px strong hairline, paper ground.
- **State:** unselected paper; selected ink with white text. Hover darkens the
  border only.
- **Row:** horizontally scrollable with scroll snap; the cut-off chip at the
  right edge is the affordance for more dates.

### Episode rows

- **Structure:** play, time rail, body, details button; a 2 px progress track
  sits on the bottom hairline when the browser has a stored position.
- **Hover:** paper tint over the whole row; no lift, no shadow.
- **Details:** expands in place; the row's chevron rotates 180°, the button's
  `aria-expanded` and label change.

### Inputs / Fields

- **Style:** 44 px, 8 px radius, paper ground, 1 px strong hairline, 15 px text.
- **Focus:** red border plus a 3 px translucent red ring.
- **Search:** 17 px search icon inset 14 px; a custom clear button replaces the
  browser's native cancel control.

### Transport (signature component)

A fixed, full-width dark plane (`#14161a`, white text) with a 44 px cover, the
programme title and one meta line, ±15 s buttons, a 46 px red play control, a
scrubber with red progress, elapsed/total time, download and close. On phones it
becomes two rows: track and controls, then the scrubber; below 420 px the cover
is dropped so the title stays readable. It rises with a 260 ms
`cubic-bezier(0.2, 0.8, 0.25, 1)` transform and respects reduced motion. It is
the only player; the inline details never duplicate it.

### Feedback states

- **Empty:** a dashed paper panel with one sentence and a "Clear filters" ghost
  button; never a blank page.
- **Load failure:** a light error wash, the failure title, a plain-language
  body and a red "Try again" button.
- **Stale feed:** a neutral paper-tint strip above the day chips: "The archive
  is temporarily unavailable. Showing the last known broadcasts." plus an
  underlined "Try again". It uses no red, because playback is not affected.

### Resume row

A single paper row above the date chips, with a 34 px red circular play icon,
an uppercase "Verder luisteren" label, the programme name and the position.
It disappears while that episode is playing and returns after the transport is
closed.

## Do's and Don'ts

### Do:

- **Do** keep the time rail tabular and on one optical column.
- **Do** keep the page output identical for every visitor: no nonces, no
  per-user values, no cookies in the shortcode markup.
- **Do** keep one transport; playback must survive filtering, paging and
  scrolling.
- **Do** reserve red for playback, position, primary action and focus.
- **Do** keep rows flat and separate days with a hairline and 22 px of air.
- **Do** show the time first in the mobile meta line, because the rail is gone.
- **Do** prefer a real `<button>` and a visible focus ring over a clickable div.

### Don't:

- **Don't** turn rows into cards with shadows, or put a cover thumbnail in every
  hourly row; the archive reuses one cover per hour slot, so it is noise.
- **Don't** duplicate the player inside the expanded row.
- **Don't** put a nonce or a user-specific value in cached HTML; that was the
  original bug.
- **Don't** use display fonts, all-caps sentences or letter-spaced body copy.
- **Don't** use red for passive labels, counts, day headings or borders.
- **Don't** hide the close button behind a site chat widget: the transport must
  paint above late-injected fixed widgets.
- **Don't** rely on hover for state; every hover has a focus and active peer.
