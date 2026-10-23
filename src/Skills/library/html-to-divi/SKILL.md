---
name: html-to-divi
description: How to recreate an HTML page or mockup (a design file, a static prototype, another site's page) as a real, editable Divi page: separating page content from site header and footer, mapping the design's layout, colours, fonts and spacing onto Divi module settings, handling images, embeds and things Divi has no setting for, and building it section by section. Read with divi-build-page before turning any HTML into Divi.
requires: divi
---

# Turning an HTML design into a Divi page

An HTML file is a picture of the result, not something to paste in. The
job is to rebuild it from Divi's own sections, rows, columns and modules,
with every colour, size and spacing set in module settings, so the client
can edit it in the Visual Builder afterwards. Read `divi-build-page` first:
its hard rules apply here (no raw-HTML layouts, no custom CSS).

## 1. Read the whole file before building

1. **List the sections in order**: one line each, with what it holds
   (heading, text, buttons, image, video, cards in a grid...). This list
   is your plan and your checklist at the end.
2. **Split page content from site chrome.** The `<header>`, the menu and
   the `<footer>` belong to the whole site (Theme Builder, menus), not to
   this page. Build only what is inside `<main>`. If the design's header
   or footer differs from the site's, say so in your report; do not
   rebuild them on the page.
3. **Collect the design tokens** from the CSS: colours (often CSS
   variables), font families and weights, the content width (`max-width`
   of the wrapper), section padding, corner radius, button styles. Write
   them down once; reuse them in every call.
4. **Note the breakpoints.** `@media (max-width: …)` rules become the
   tablet (≤980px) and phone (≤767px) values of the same settings.
5. **Mark what is not content**: scripts, animations, placeholder boxes
   ("feed loads here"), visually hidden headings. See section 4.

## 2. Check what the site already has

- `divi-get-page` on the site's home page and `divi-get-module` on a
  heading, a text and a button there: copy their attribute names and
  formats. Divi ignores attribute names it does not know, without any
  error, so a name you guessed can silently do nothing. When unsure,
  read an existing module that has that setting.
- Images: find each one in the media library with `list-media`
  (search by file name). Use the site's own copy. If an image is not
  there, `upload-media-from-url` brings it in (staging sites), then use
  the new URL. Never hotlink an image from another site.
- Embeds the design only marks with a placeholder (an Instagram feed, a
  form) usually exist on the site already as a plugin shortcode: look at
  `list-plugins` and the current page before inventing anything.

## 3. Map the layout

Divi 4 columns come in fixed structures. Pick the closest one:

| CSS in the design | Divi columns |
| --- | --- |
| one column, `max-width` wrapper | `4_4`, row `max_width` = the wrapper width |
| `1fr 1fr` | `1_2, 1_2` |
| `repeat(3, 1fr)` | `1_3 ×3` |
| `repeat(4, 1fr)` | `1_4 ×4` |
| `repeat(5, 1fr)` | `1_5 ×5` |
| `5fr 7fr`, `2fr 3fr` | `2_5, 3_5` |
| `3fr 9fr`, `1fr 3fr` | `1_4, 3_4` |
| `1fr 2fr` | `1_3, 2_3` |
| `2fr 1.4fr 1fr` and other odd splits | nearest: `1_2, 1_4, 1_4` |

Other structures Divi 4 offers: `2_3,1_3`, `3_4,1_4`, `3_5,2_5`,
`1_4,1_2,1_4`, `1_5,3_5,1_5`, `1_4,1_4,1_2`, `1_2,1_4,1_4`, `1_6 ×6`.

- A grid that changes column count on smaller screens: Divi stacks
  columns on phones by itself. Two-per-row on tablet is not a setting;
  accept the stacking and say so.
- A full-bleed section with the content in a narrower wrapper: section
  with the background, row `width` 100% and `max_width` = wrapper width.
- Content pushed to the bottom of a tall hero: section `min_height`, and
  top padding larger than bottom.

## 4. Map elements to modules

| HTML | Divi 4 module |
| --- | --- |
| `h1`/`h2`/`h3` + paragraphs | `text`, with the heading tags kept in `content` |
| a big decorative number or word | `text`, large `text_font_size` |
| a link styled as a button | `button` (or a button module the site already uses, e.g. `dsm_button`) |
| `img` | `image` (`src`, `alt`) |
| an image behind content | the section's or column's `background_image` |
| `iframe` from Vimeo/YouTube | `video` (`src` = the normal watch URL) |
| cards: image + title + text, linked | `blurb` (`image`, `title`, `url`, content) |
| a quote with a name | `testimonial`, or `text` when the design is custom |
| a row of stats or short items | one `text` per column |
| a list of links | `text` with the `<ul>` in its content |
| a form | the site's form plugin shortcode in a `text` module |
| a third-party embed the person asked for | `code`, the only case for it |

Things with no module setting, and what to do instead:

- **Gradient overlay on a photo** (`::after` with a gradient): section
  `background_image` plus `use_background_color_gradient` "on",
  `background_color_gradient_stops`
  ("rgba(22,33,58,0.55) 0%|rgba(22,33,58,0.92) 100%"),
  `background_color_gradient_direction` ("180deg") and
  `background_color_gradient_overlays_image` "on".
- **Lines between grid items** (`border-left`, `border-top`): border
  settings on the column (`border_width_left`, `border_color_left`) or a
  `divider` module.
- **Entrance animations**: Divi's own `animation_style` on the module, or
  leave them out. Never copy `@keyframes`.
- **`clamp()` font sizes**: the largest value on desktop, the middle on
  tablet, the smallest on phone (`text_font_size`,
  `text_font_size_tablet`, `text_font_size_phone`,
  `text_font_size_last_edited` "on|phone").
- **Pill buttons** (`border-radius: 999px`): `button_border_radius` "100px".
- **Hover colours**: the same setting with `__hover` and
  `…__hover_enabled` "on|hover", as Home's buttons do.
- **Placeholder boxes** in the design (dashed "goes here" boxes): never
  build the box. Put the real thing in (the site's shortcode), or leave
  the section out and list it in your report.

## 5. Map styles to settings (Divi 4)

| CSS | Setting |
| --- | --- |
| `background` colour | `background_color` |
| `padding: 120px 0` on a section | `custom_padding` "120px\|\|120px\|\|true\|false" (top\|right\|bottom\|left\|linked\|linked) |
| wrapper `max-width: 1180px` | row `width` "100%", `max_width` "1180px" |
| `gap` between columns | row `use_custom_gutter` "on", `gutter_width` 1-4 |
| `align-items: center` | row `make_equal` "on" plus column/module alignment |
| body font, size, colour | text `text_font`, `text_font_size`, `text_text_color`, `text_line_height` |
| `h2` font, size, colour | text `header_2_font`, `header_2_font_size`, `header_2_text_color`, `header_2_line_height` (`header_font` for h1, `header_3_…` for h3) |
| `text-transform: uppercase`, weight | inside the font value: "Family\|800\|\|on\|\|\|\|\|" (family\|weight\|italic\|uppercase\|…) |
| `text-align` | `text_orientation` |
| `max-width: 56ch` on text | module `max_width` (e.g. "620px") |
| button colours, border, radius | `custom_button` "on", `button_bg_color`, `button_text_color`, `button_border_width`, `button_border_color`, `button_border_radius`, `button_font`, `button_text_size` |
| `border-radius` on an image or video | `border_radii` "on\|12px\|12px\|12px\|12px" |

Colours stay exactly as in the design. Font families are written by name
("Barlow Condensed"); Divi loads Google Fonts itself.

## 6. Build

- Use the page the person named. For a new one, `create-post` with
  `builder: "divi"` first.
- **One section per call**: `divi-add-module` with `type: "section"` and
  `children` holding the whole tree (rows → columns with `width` →
  modules). The tool checks the structure, saves it as one change and
  returns one `undo_id` and every new path.
- Read each result's `verification` before the next call. A refused
  tree saves nothing; the message names the child that is wrong
  (`children[0].children[1]: …`). Fix that part and send it again.
- Replacing an existing page: build the new sections first, then remove
  the old ones with `divi-remove-module`, so the page is never empty.
- Copy text exactly as the design has it. Facts not in the design
  (phone numbers, dates, prices) are never invented.

## 7. Check and report

1. `divi-get-page`: compare the outline with your section list from
   step 1. Every section accounted for, nothing empty
   (`empty_structural`), no `code` module you did not mean.
2. `divi-clear-cache` for the page.
3. Report: the page link, every `undo_id`, and an honest list of what
   differs from the HTML: rounded column widths, dropped animations,
   header and footer left to the Theme Builder, placeholders not built,
   anything the person should look at on a phone.
