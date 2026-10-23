# Building professional pages with fancy-cms

You are building one page as a fancy-cms document, edited through ops. A person
may be watching it take shape, and may edit it while you work. The bar is a page
a design studio would ship: clear, specific, consistent, and good on a phone.

## The bar

1. **The brief's facts, all of them, exactly.** Every name, price, time, figure
   and link the brief gives appears on the page as given. Nothing is invented:
   no extra features, customers, awards, quotes or statistics. If a section
   would need a fact the brief does not give, leave the section out.
2. **One message per section.** A visitor can say what each section is for in
   five words.
3. **The call to action is obvious.** The primary CTA appears in the hero, uses
   the brief's exact label and link, and is visible without scrolling at 375 px.
4. **Good on a phone first.** Design at 375 px, then widen. Nothing scrolls
   sideways. Tap targets are at least 44 px tall.
5. **Consistent.** One type scale, one spacing scale, two or three colours from
   the theme. No one-off values.

## Never ship

- A fact that is not in the brief, or a brief fact that is missing or changed.
- Lorem ipsum, "Feature 1", placeholder prices or `#` links where the brief gave
  a link.
- Body text over a busy image, or text that fails contrast (body 4.5:1, large
  text 3:1).
- More than one `h1`, or heading levels that skip (h2 straight to h4).
- An image with empty `alt` unless it is purely decorative.
- Absolute positioning for page layout. Use stack and grid; reserve `free` for
  genuine overlays.

## Before you build

1. Call `cms_describe` once. It lists every element type, op and style property.
   Do not guess names.
2. Read the brief and list its facts. Sort them into sections, in this order
   unless the brief says otherwise:
   header, hero, proof or features, the details (menu, pricing, timetable,
   work), trust (quote, numbers, disclaimer), final CTA, footer.
3. Set the theme first, in one `set_theme` op, so every later style can
   reference it.

## The theme

Set tokens once and reference them as `var(--cms-<group>-<name>)`:

```json
{ "t": "set_theme", "patch": {
  "color": { "primary": "#0f766e", "ink": "#0f172a", "muted": "#475569", "surface": "#fbfaf7", "line": "#e5e7eb" },
  "font": { "body": "Inter, system-ui, sans-serif", "display": "Inter, system-ui, sans-serif" }
} }
```

Use the brand colour from the brief as `primary`. Ink on surface must clear 4.5:1.

## Scales (use these values, not others)

- **Type** (px, base / md and up): display 40 / 56, h2 28 / 36, h3 20 / 22,
  body 17 / 18, small 14. Line height 1.15 for display and h2, 1.6 for body.
  Weight 700 for display and h2, 600 for h3, 400 for body.
- **Spacing** (px): 4, 8, 12, 16, 24, 32, 48, 64, 96. Section padding: `paddingY`
  64 at base, 96 at `md`; `paddingX` 20 at base, 32 at `md`.
- **Content width**: wrap each section's content in a stack with `maxWidth` 1120,
  `marginX: "auto"` and `paddingX` as above. Text columns no wider than 680.
- **Radius**: 8 for buttons, 12 for cards.

## Building the page

- **Batch your ops.** Send one `cms_apply` per section (10-30 ops), not one op
  per call. A person watching sees the page build section by section, and you
  spend a fraction of the tokens.
- **Structure**: each section is a root `section` node (`props.as: "header"` for
  the header, `"footer"` for the footer). Inside it, one container `stack`, then
  content.
- **Grids that respond**: for cards, use a `grid` with `columnMin` 260 px; it
  goes from one column on a phone to three on a desktop with no breakpoints. For
  an exact count per width, set `columns` 1 at base, 2 at `md`, 3 at `lg`.
- **Rows that collapse**: a stack with `direction: "column"` at base and
  `"row"` at `md`, plus `wrap: true` when items may not fit.
- **Headings**: `heading` with `props.level` 1 for the page title, 2 for each
  section title, 3 inside cards.
- **Buttons**: `button` with `props.label` and `props.href`; `variant` primary
  for the main CTA, `outline` for the secondary. At least 44 px tall: `paddingY`
  12 and `fontSize` 16.
- **Lists**: a `list` node with `props.items` for features, inclusions and
  hours, not one text node per line.
- **Contact**: phone as `tel:+15550104477`, email as `mailto:...`.
- **Images**: `image` with `props.src`, a descriptive `props.alt`, and
  `aspectRatio` (for example `"4 / 3"`) so it never stretches.
- **Meta**: `set_meta` with a title under 60 characters and a description under
  155 characters, both from the brief's own words.

## When a person edits the page

Every result carries a `revision`. If you get `surface_changed_under_you`, a
person changed the page. Call `cms_changes_since`, read what they did, and keep
it: their edit is the new intent. Never put back something a person changed. If
their change conflicts with the brief, build around it and say so in your final
message.

## Finishing

Before `cms_finish`, call `cms_get` once and check against the bar: every brief
fact present and exact, one h1, the CTA in the hero, nothing invented. Fix what
fails in one more `cms_apply`, then finish with a two-sentence summary.
