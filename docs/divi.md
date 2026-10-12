# Divi tools

WPRoadie can read and edit pages built with the Divi Builder (Elegant Themes' Divi or Extra theme, or the Divi Builder plugin), in both storage formats:

- **Divi 4**: nested shortcodes in `post_content`: `[et_pb_section][et_pb_row][et_pb_column type="1_2"][et_pb_text]…[/et_pb_text]…`.
- **Divi 5**: block markup with `divi/*` blocks and JSON attributes, wrapped in `<!-- wp:divi/placeholder -->`.

The tools detect the format per page and work the same way on both. They are in the **Divi** group (risk `write`, on by default), so they work in Staging and Production; in Read-only mode only the reading tools are offered. The group only appears on sites where Divi is active.

## Tools

| Tool | Risk | What it does |
| --- | --- | --- |
| `divi-list-pages` | read | Pages, posts (and, by type, Divi Library or Theme Builder layouts) built with Divi: id, title, type, status, format (4/5), module count, links. |
| `divi-get-page` | read | Flat outline of a page: path, type, human name, admin label, CSS ID, column width, global library link and a one-line summary per element. `depth`, `path` and `limit` for long pages. |
| `divi-get-module` | read | Every attribute and the content of one element (by path, admin label or CSS ID), its children, optionally its raw markup. |
| `divi-update-module` | write | Set/remove attributes and/or replace the content of one element. Divi 4: `{name: value}`; Divi 5: `{dotted.path: value}`. |
| `divi-add-module` | write | Insert a module into a column, or a new section/row with empty columns (`columns: ["1_2","1_2"]`). |
| `divi-remove-module` | write | Remove an element and everything inside it. |
| `divi-move-module` | write | Move an element to another container/position on the same page. |
| `divi-duplicate-module` | write | Insert an exact copy right after an element. |
| `divi-replace-text` | write | Find/replace on one page or (with `all_pages: true`) every Divi item. `dry_run` defaults to true. `scope: "text"` (visible text only) or `"all"` (also URLs and settings). |
| `divi-list-layouts` | read | The Divi Library (`et_pb_layout`): type (layout/section/row/module), global or not, categories, format. |
| `divi-insert-layout` | write | Put a library item on a page; global items are inserted as links to the item (or as a copy with `link_global: false`). |
| `divi-list-theme-builder` | read | Live Theme Builder templates, their conditions and the header/body/footer layouts they use — where the site header and footer live. |
| `divi-clear-cache` | write | Clear Divi's static CSS (and page caches Divi supports) for one page or the whole site. |

All tools are named `wproadie-divi-…` in MCP clients. Every change returns an `undo_id`.

### Addressing elements

Paths count children from 0: `0.1.0.2` is section 0 → row 1 → column 0 → module 2. Divi 5's `divi/placeholder` wrapper is not counted. Children of parent modules continue the path (`0.1.0.2.0` is the first tab of a Tabs module). Elements can also be found by admin label (`label`, case-insensitive, must be unique) or CSS ID (`css_id`). Paths change after inserts, moves and removals; each result returns the new paths.

## Safety

- **Byte-exact round trips.** The parsers keep every byte of the page: each element remembers its exact opening and closing tags and the text between elements. Saving a page that was not changed reproduces it byte for byte, and an edit rewrites only the opening tag of the element it targets (Divi 4: only the one attribute; Divi 5: only that block's JSON comment, re-encoded with WordPress's `serialize_block_attributes()`, the encoder Divi 5 itself uses).
  - Divi 4 parsing follows WordPress's shortcode grammar (`get_shortcode_regex()` / `get_shortcode_atts_regex()`): a tag's content runs to the first matching closing tag inside its parent. Only layout shortcodes and known parent modules (tabs, slider, accordion, pricing tables, contact form, social follow, counters, maps) are parsed for children, so shortcodes from other plugins inside a Text module stay text.
  - Divi 5 parsing uses the exact token pattern and nesting rules of WordPress's `WP_Block_Parser`, and the tests check the result matches `parse_blocks()`. `parse_blocks()` + `serialize_blocks()` alone was not used for writing because it re-encodes every block's JSON (unicode escapes, `\/`, spacing), so unchanged blocks would not keep their bytes.
  - Divi 5 attributes are decoded as objects, so empty objects stay `{}` (not `[]`) when a block is re-encoded.
  - Divi 4 attribute values are shown decoded (`%22` → `"`, `%91`/`%93` → `[`/`]`, `%92`/`%5c` → `\`) and encoded the same way on write.
- **Only Divi posts.** Posts without the Divi Builder enabled (`_et_pb_use_builder` = `on`) and without Divi 5 content are refused with a pointer to `get-post`/`update-post`. Library and Theme Builder layouts always count as Divi.
- **Permissions.** Every tool that takes an `id` needs `edit_post` on it (reads too, since outlines show drafts and private pages). Lists only show what the user can edit. Site-wide cache clearing needs `manage_options`.
- **Scripts.** Users without `unfiltered_html` (e.g. administrators on multisite) get HTML filtered with `wp_kses_post()`; Divi 4 attribute values are stripped of tags. Before saving, WPRoadie runs WordPress's own save filters over the new page; if they would change anything else on the page (e.g. strip an embed or script an administrator added to a Code module), the edit is refused and nothing changes. For Divi 5 the comparison is on parsed blocks, because kses re-encodes block JSON without changing its meaning.
- **Global modules.** Elements that are (or are inside) a global Divi Library item are shown with `global_layout`. Divi renders the library version everywhere, so edits inside them on a page are refused with the library item's ID; edit the item itself (same tools, its ID). The global element as a whole can still be moved, copied or removed on the page.
- **Undo and revisions.** Every write saves a WordPress revision first and records a post undo record, so `undo-change` (or Undo in the activity log) restores `post_content` exactly. Saving goes through `wp_update_post()`, so revisions and `save_post` hooks (including Divi's own cache clearing) run.
- **Caches.** After a write WPRoadie calls `ET_Core_PageResource::remove_static_resources( $id, 'all' )` and `et_core_clear_wp_cache( $id )` when they exist, and fires `wproadie_divi_cache_cleared`. After an undo, the `wproadie_undo_applied` action clears the restored page the same way.
- **Editor locks.** If someone has the page open in an editor, the result includes a warning: their save would overwrite the change.
- **Limits.** Pages over 4 MB are refused; `content` is limited to 500 KB per call; outlines to 1,000 items; replace-text scans up to 500 items per call (`offset` for the next batch).

## Verification

**Verified (automated tests, `tests/integration/divi.php`, run in CI without Divi):**

- Byte-exact parse → serialize for Divi 4 fixtures with nested quotes, `%22`/`%91`/`%93`/`%92` escapes, raw HTML and `<script>` in Code modules, shortcodes from other plugins inside Text modules, single-quoted, unquoted, positional and empty attributes, self-closing and unclosed modules, specialty sections with `row_inner`/`column_inner`, fullwidth sections, tabs, global sections, CRLF line endings, escaped `[[shortcodes]]` and stray brackets.
- Byte-exact parse → serialize for Divi 5 fixtures with the placeholder wrapper, unicode escapes (`é`, `“`), escaped slashes, `<`-encoded HTML, `{}` and `[]` attributes, responsive (`tablet`) and `hover` values, groups, `divi/global-layout`, freeform HTML, core blocks, invalid JSON, unclosed blocks and stray closers; block names and attributes equal `parse_blocks()`.
- Every tool on both formats, edits that change only the target, undo restoring the exact bytes, kses for users without `unfiltered_html`, refusal of non-Divi posts and of edits that WordPress's filters would spread, `edit_post` checks, availability without Divi, and which tools Production and Read-only modes expose.
- The tools over MCP STDIO with the real MCP Adapter (tools/list and calls, including Divi 5 object values).

**Not verified against a real Divi install** (Divi is commercial and was not available):

- Pages created or edited by these tools were not opened in the Divi Builder or rendered by Divi. The formats were built from Divi's documented storage format and public sources: Divi's GPL PHP source as mirrored on GitHub (shortcode attribute encoding; `et_pb_is_pagebuilder_used()`; the Divi 5 placeholder wrapper and `serialize_block()` saving in `core.php`/`MigrationUtils.php`; `divi/global-layout` with `globalModule` in `BlockParser.php`; Theme Builder post types and meta in `theme-builder.php`; `ET_Core_PageResource::remove_static_resources()`), and the render-verified Divi 5 notes in the public `divilovewp/divi5-skill` repository (attribute layout such as `content.innerContent.desktop.value`, `button.innerContent.desktop.value.{text,linkUrl}`, `module.meta.adminLabel`, `module.advanced.htmlAttributes`, column/row flex attributes).
- New Divi 5 columns get both `module.advanced.type` (e.g. `1_2`) and `module.decoration.sizing…flexType` (e.g. `12_24`), and equal-width rows get `flexColumnStructure`; whether the Divi 5 builder needs anything more for a row it did not create itself is unverified. Adding modules into existing sections/rows is the safer path.
- When Divi is active, `divi-add-module` refuses module types Divi has not registered (`shortcode_exists()` / the block type registry); this check does not run in tests.
- Third-party Divi modules with child items keep their children inside the parent's content (edit them with `content`), since only Divi's own parent modules are parsed for children.
- `_et_pb_old_content` (the pre-builder content Divi keeps) is never touched.

## Example prompts

- "On the Smith Dental site, list the Divi pages and show me the outline of the Home page."
- "Change the hero button on the Services page to say 'Book a consultation' and link to /contact."
- "Replace our old phone number 555-0100 with 555-0199 on every Divi page — show me the matches first."
- "Move all links from oldclinic.com to smithdental.com across the site's Divi pages." (scope `all`)
- "Add a two-column section above the footer with a heading on the left and a button on the right."
- "Which Theme Builder template holds the site header? Update the phone number in it."
- "Insert the 'Book now CTA' library section at the end of the About page."
- "Undo the last change you made to the Home page."
