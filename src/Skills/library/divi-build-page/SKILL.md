---
name: divi-build-page
description: How to build or edit a page with WPRoadie's Divi tools so it comes out as a real Divi page: the structure Divi expects, the page settings, what never to do (fake layouts with raw HTML, custom CSS), how to check your work, and the differences between Divi 4 and Divi 5. Read before creating a Divi page, adding sections, or restyling modules.
requires: divi
---

# Building a page with Divi through WPRoadie

Use this whenever the target site runs Divi and the job is a new page, a new
section, or a visual change. Recreating an HTML design? Also read
`html-to-divi`. WPRoadie's Divi tools (`divi-get-page`,
`divi-get-module`, `divi-get-module-schema`, `divi-add-module`, `divi-update-module`, `divi-move-module`,
`divi-duplicate-module`, `divi-remove-module`, `divi-replace-text`,
`divi-list-layouts`, `divi-insert-layout`, `divi-list-theme-builder`,
`divi-clear-cache`) edit the page's stored Divi content directly, the same
content the Visual Builder saves. They work on both storage formats, Divi 4
(shortcodes) and Divi 5 (blocks), and every write returns an `undo_id`.

## Before you start

1. `divi-list-pages` tells you which pages are Divi pages and in which format
   (4 or 5). `divi-get-page` gives the outline with a path for every element.
2. Read before you change: `divi-get-module` on the element you will touch.
   Copy its existing attributes for anything new you add next to it, so the
   new module matches the page's spacing, colours and fonts.
3. For an attribute you have not seen on the page, `divi-get-module-schema`
   with the module's name lists every attribute it takes, with types,
   options and the responsive and hover variants. Divi ignores attribute
   names it does not know, so the tools refuse an unknown name and tell
   you the likely right one; never guess a name twice.
4. On a live site every write waits for a person's approval in HQ. Batch your
   work into few, clear calls and give each an `approval_note`.

## Hard rules

1. **Build real modules. Never fake a layout with raw HTML.** Do not drop a
   Code module holding a block of HTML and CSS to reproduce a design. It
   renders once and is dead in the builder: no responsive settings, no
   editing, no dynamic content. Compose from native modules: `text`,
   `heading` (Divi 5), `image`, `button`, `blurb`, `cta`, `testimonial`,
   `pricing_tables`, `gallery`, `video`, `contact_form`. A Code module is only
   for a snippet the person explicitly asked to embed (a form embed, a map, a
   widget script).
2. **Never style with custom CSS.** No `<style>` blocks, no Code modules of
   CSS, no stylesheet files. Every visual property has a module setting:
   background, text colour, font, size, spacing, border, shadow, width. Set
   those with `divi-update-module`. If the same look is needed on many
   modules, that is a Divi preset or a global colour, set in the builder's
   theme options by a person, not a stylesheet.
3. **Keep the structure.** Section → row → column → module. Modules live in
   columns, never loose in a section or row. Rows define the column layout.
   Fullwidth sections take fullwidth modules only. A specialty section's own
   columns cannot be created by these tools: add rows inside its existing
   columns instead.
4. **Small steps on existing pages.** Change one section at a time and read
   the result. Never rebuild a live page in one call.
5. **Never touch WPRoadie, the MCP Adapter, or the Theme Builder header and
   footer** unless the person asked for exactly that; Theme Builder layouts
   render on every page.

## Page settings

A page whose Divi content was written without the Visual Builder needs the
settings the builder records on save, or WordPress shows it as an ordinary
page: default template, sidebar, title. WPRoadie handles this:

- `create-post` with `builder: "divi"` creates a page with the builder on, full
  width, title hidden, and on Divi 5 the two Divi 5 markers.
- The Divi tools record the same settings whenever they save a page that was
  never opened in the builder, and say so in the result (`page_settings`).
- `update-post` with `builder: "divi"` fixes an existing page; `template`
  picks a page template (`page-template-blank.php` hides header and footer).

If a page still shows a sidebar or its title after your edit, check its
settings with `get-post-meta` (`_et_pb_page_layout`, `_et_pb_show_title`).

## Building a new page

1. `create-post` with `post_type: "page"`, a title, `status: "draft"`,
   `builder: "divi"`, and no content.
2. Add structure top down with `divi-add-module`: `type: "section"` with
   `columns` (e.g. `["1_2", "1_2"]`) creates the section, its row and its
   empty columns in one call and returns their paths.
3. Add modules into columns: `container` is the column's path, `type` the
   module, `attributes` its settings, `content` its text or HTML. Divi 4
   attributes are flat (`button_text`, `button_url`, `background_color`);
   Divi 5 attributes are dotted paths into the block's JSON
   (`module.decoration.background.desktop.value.color`). Copy the shape from
   an existing module on the same site rather than guessing.
4. Faster for a whole section: one `divi-add-module` call with `type:
   "section"` and `children`, the full tree of rows, columns (each with a
   `width` such as `"1_2"`) and modules. It is checked as a whole, saved
   as one change and undone with one `undo_id`. Build one section per call.
5. Read the page back with `divi-get-page` and compare with your plan.
6. Publish with `update-post` `status: "publish"` only when the person asked
   for a published page; otherwise leave the draft and send the preview link.

## Divi 4 and Divi 5

- The format is per page, decided by the stored content. Never mix: do not
  add shortcodes to a Divi 5 page or blocks to a Divi 4 page; the tools
  choose the right kind from the page's format when you give a short type
  name such as `text` or `button`.
- Divi 4 values are plain strings. Quotes and brackets are escaped by the
  tool; send them as written.
- Divi 5 values are JSON: objects for responsive values
  (`{"desktop": {"value": "#fff"}}`), strings for simple ones. Columns carry
  both a type (`1_2`) and a flex width (`12_24`); `divi-add-module` sets
  both for new rows.
- Global library items render the library version everywhere: edit the item
  (its own ID, same tools), not its copy on the page.

## Verify your work

Every write returns a `verification` block. Read it before the next call:

- `verified: false` means the saved page differs from what was written (a
  plugin's save filter changed it). Read the page again with `divi-get-page`
  and do not retry blindly; a retry adds the element twice.
- `empty_structural` lists sections, rows and columns with nothing inside.
  Fill them or remove them before finishing.
- `raw_html_modules` lists Code modules. Each needs a reason the person gave.
- `module_count` should match your plan.
- `warnings` name anything else worth acting on, including an editor lock.

Then look at the page as a visitor: fetch the page link (`link` in the
result) and read the HTML for the headings and buttons you added, in order.
Divi's static CSS is cleared by the tools; if the front end looks stale,
`divi-clear-cache` for that page.

## Finish

Report what changed with links, every `undo_id`, any placeholders the person
must fill (never invent phone numbers, prices or addresses), and what to
check in the browser.
