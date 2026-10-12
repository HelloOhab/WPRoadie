# Elementor tools

WPRoadie can read and edit pages built with [Elementor](https://elementor.com): change a heading or a button link, add or move widgets, swap a phone number on every page, insert a saved template, or adjust the site's global colors and fonts. Every change is logged and can be undone, like every other WPRoadie tool.

The tools appear only on sites where Elementor is active. They are in the **Elementor** group (on by default) on the WPRoadie settings screen. They work with Elementor 3.x and 4.x and with Elementor Pro (tested against 3.24, 3.35 and 4.0).

## Tools

| Tool | What it does | Risk |
| --- | --- | --- |
| `elementor-list-pages` | Pages, posts and other items built with Elementor: id, title, status, link, editor link, last modified, number of elements. Filter by post type, status or search text. | read |
| `elementor-get-page` | The layout of one page as an outline tree: each container, section, column and widget with its element id, type, path and a short summary (heading text, button text and link, image URL and alt, the start of a text block). `depth`, `limit` and `root_id` keep long pages manageable. Also shows the page settings. | read |
| `elementor-get-element` | One element's full settings exactly as stored, other stored keys, its children, and with `include_controls` the settings keys its widget type understands. | read |
| `elementor-update-element` | Changes settings of one element. Keys are set, `null` removes a key, objects merge (so `{"link": {"url": …}}` keeps the link's other options), lists such as icon-list items are replaced whole. | write |
| `elementor-add-element` | Adds a widget (`widget_type` plus `settings`) or a full element (a container with widgets inside, a section with columns) under a parent at a position. New ids are generated. A widget sent to the top level is wrapped in a container. | write |
| `elementor-remove-element` | Removes an element and everything inside it. | write |
| `elementor-move-element` | Moves an element to another parent or position on the same page. | write |
| `elementor-duplicate-element` | Copies an element (with fresh ids) right after the original. | write |
| `elementor-replace-text` | Finds and replaces text in widget settings on one page or on every Elementor page and template (`all_pages`). `dry_run` is on by default and lists every match first. | write |
| `elementor-list-templates` | Saved templates in the Elementor library (pages, sections, containers, and with Pro headers, footers, popups). | read |
| `elementor-insert-template` | Copies a template's content into a page at a position, with fresh ids. | write |
| `elementor-get-kit` | The global colors and fonts (with the references elements use for them) and the names of the other site settings. | read |
| `elementor-update-kit` | Changes, adds or removes global colors and fonts, or other kit settings. Restyles the whole site. | admin |
| `elementor-regenerate-css` | Clears Elementor's generated CSS for one page or the whole site, like Elementor > Tools > Regenerate CSS. | write |

Read tools work in every mode, edits work in Production (like content edits), and kit changes need Staging, because they restyle every page at once.

## How changes are saved

Elementor stores a page as a JSON tree in the `_elementor_data` post meta. The tools:

1. Read and decode that tree.
2. Change only the element (or text) you asked for. Every other element, setting and unknown key stays as it was, including widgets from plugins that are switched off at the time and Elementor 4 (atomic) data.
3. Write the tree back encoded exactly as Elementor encodes it, so an unchanged page is stored byte for byte as before.
4. Do what Elementor's own save does next: delete the page's generated CSS and element cache (Elementor rebuilds them on the next view) and update the plain-text copy of the page in `post_content`, which search, excerpts and SEO plugins use. WordPress keeps a revision of the previous version as well.

WPRoadie does not save through Elementor's `Document::save()`: that rebuilds every element through the widgets registered at that moment and silently drops any widget it does not know (for example an Elementor Pro form while Pro is deactivated or its licence lapsed) along with element keys it does not recognise. A tool that edits one heading must never do that to the rest of a client's page.

Only posts edited with Elementor (`_elementor_edit_mode` is `builder`) are touched. Other posts get a clear refusal that points to `get-post` and `update-post`. The kit (Site Settings) is changed only through the kit tools.

## Safety

- **Undo.** Every write returns an `undo_id`. Pass it to `undo-change`, or use Undo in the WPRoadie activity log in wp-admin. Undo restores the stored data exactly and clears the CSS built from the undone version. One `elementor-replace-text` call across many pages has one undo that restores all of them.
- **Permissions.** The user needs `edit_post` on the page, and Elementor's Role Manager must not block their role from the editor. Inserting a template also needs `edit_post` on the template. Kit changes need `edit_theme_options`. Site-wide CSS regeneration needs `manage_options`.
- **HTML filtering.** For users without the `unfiltered_html` capability (for example Editors on multisite, or any site with `DISALLOW_UNFILTERED_HTML`), the values they send are run through `wp_kses_post`, as Elementor and WordPress do, so scripts and event handlers are removed. Existing content on the page is never re-filtered, so an administrator's tracking script in an HTML widget survives an editor's change to a heading.
- **Structure.** Elementor's nesting rules are enforced: widgets go in containers or columns, columns only in sections, no sections inside containers, no inner section inside an inner section. New widget types are checked against the widgets Elementor has registered.
- **Size limits.** An element (or a settings change) over 256 KB is refused, and so is any change that would make a page's data larger than 5 MB.
- **Someone editing at the same time.** If another user has the page open in an editor, the result includes a warning: their next save in Elementor will overwrite the change, so ask them to reload first.

## Global colors and fonts

`elementor-get-kit` lists every global color and font with its id and the reference elements use for it, such as `globals/colors?id=primary`. To make an element use a global, set it in the element's `__globals__` setting:

```json
{ "post_id": 42, "id": "4f5e6d7", "settings": { "__globals__": { "title_color": "globals/colors?id=primary" } } }
```

`elementor-update-kit` changes globals by id (`{"colors": [{"id": "primary", "color": "#1A73E8"}]}`). An id that does not exist yet adds a new custom color or font when you also send a title. The whole site's CSS is regenerated afterwards.

## Example prompts

- "List the Elementor pages on this site and show me the layout of the homepage."
- "On the Contact page, change the heading to 'Get in touch' and point the button to /book-a-call."
- "Our phone number is changing from 555-0100 to 555-0199. Find it on every Elementor page and template, show me where it appears, then replace it."
- "Duplicate the second testimonial on the About page and change the copy to: 'Great service!', Sam R."
- "Move the FAQ section above the pricing section on the Services page."
- "Add a container with a heading 'Opening hours' and an icon list (Mon–Fri 8–6, Sat 9–2) at the end of the Visit page."
- "Insert the 'Newsletter signup' template at the bottom of every blog landing page."
- "What are our global colors? Change the primary color to #0B5FFF." (needs Staging mode)
- "The Pricing page looks unstyled. Regenerate its Elementor CSS."

## Notes and limits

- Page caches and CDNs are not purged by these tools. The plain-text sync updates the post, which makes most cache plugins purge that page, but purge others yourself if needed.
- If a user has a newer unsaved Elementor autosave of a page, the editor may offer it when they next open the page. The live page shows the change made by WPRoadie.
- Page settings (`_elementor_page_settings`, such as hiding the title) are shown by `elementor-get-page` but not changed by these tools.
- `elementor-replace-text` matches stored values, so text written as HTML entities (`&amp;`) must be searched that way. Element ids, dynamic tags (`__dynamic__`), global references (`__globals__`) and CSS classes are never changed.
- Converting a classic WordPress page to Elementor is not supported. Open it in Elementor once first.

## Testing

`tests/integration/elementor.php` runs with the rest of the integration suite. With Elementor active on the test site, it uses Elementor's own APIs (CSS generation, document reads, widget registry, plain-text rendering). Without Elementor, it switches the tools on with the `wproadie_elementor_active` filter and tests the same storage, tree and undo logic on the fixture in `tests/integration/fixtures/elementor-page.json`.

To add Elementor to a test site made by `bin/test-site.sh`:

```bash
bash bin/test-elementor.sh <dir> [tag]   # default tag: v3.35.9
```

It fetches only Elementor's PHP from GitHub (a sparse checkout of the release tag, about 25 MB). CI runs the PHP 8.3 job with Elementor and the PHP 7.4 job on fixtures.
