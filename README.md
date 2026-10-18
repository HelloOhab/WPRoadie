# WPRoadie

Rhythm Collective's WordPress plugin for AI agents. It lets Claude, Cursor or any MCP client read and change a client site safely: environment modes, per-user access, guardrails inside every tool, undo, rate limits, a kill switch and an audit log of every call.

WPRoadie is built on WordPress's official AI building blocks: the **Abilities API** (core since 6.9) and the **MCP Adapter** plugin. It is GPL-2.0-or-later.

## Contents

- [What it can do](#what-it-can-do)
- [Set up a site in four clicks](#set-up-a-site-in-four-clicks)
- [Requirements](#requirements)
- [Install by hand or over SSH](#install-by-hand-or-over-ssh) and [roll out to many sites](#rolling-out-to-many-sites)
- [Connect an AI client](#connect-an-ai-client)
- [Safety model](#safety-model)
- [Undo](#undo)
- [WPRoadie HQ](#wproadie-hq)
- [WP-CLI commands](#wp-cli)
- [Updates and releases](#updates-and-releases)
- [Development](#development)

## What it can do

79 tools and 2 prompts, in groups an admin can switch on and off. The Elementor and Divi groups only appear on sites running that builder. A group's **risk** decides which modes allow it.

| Group | Tools | Risk | Default |
| --- | --- | --- | --- |
| Site info | `site-overview`, `active-theme`, `list-plugins`, `versions`, `health-check`, `activity-log` | read | on |
| Prompts | `site-briefing`, `care-checklist` | read | on |
| Content | `list-posts`, `get-post`, `create-post`, `update-post`, `trash-post`, `get-post-meta`, `update-post-meta`, `list-terms`, `create-term`, `set-post-terms`, `undo-change` | write | on |
| Media | `list-media`, `upload-media-from-url`, `set-featured-image`, `update-media-details` | write | on |
| Navigation | `list-menus`, `get-menu`, `create-menu`, `add-menu-item`, `update-menu-item`, `delete-menu-item`, `assign-menu-location`, `list-widgets`, `add-block-widget`, `remove-widget` | write | on |
| Elementor | `elementor-list-pages`, `elementor-get-page`, `elementor-get-element`, `elementor-update-element`, `elementor-add-element`, `elementor-remove-element`, `elementor-move-element`, `elementor-duplicate-element`, `elementor-replace-text`, `elementor-list-templates`, `elementor-insert-template`, `elementor-get-kit`, `elementor-update-kit` (admin), `elementor-regenerate-css` | write | on (only while Elementor is active) |
| Divi | `divi-list-pages`, `divi-get-page`, `divi-get-module`, `divi-update-module`, `divi-add-module`, `divi-remove-module`, `divi-move-module`, `divi-duplicate-module`, `divi-replace-text`, `divi-list-layouts`, `divi-insert-layout`, `divi-list-theme-builder`, `divi-clear-cache` | write | on (only while Divi is active) |
| Settings | `get-options`, `update-options` (allow-listed options only) | write | on |
| Skills | `get-skill` (reads a built-in step-by-step guide, e.g. `divi-build-page`; listed in the tool's description and the site briefing) | read | on |
| Plugins & themes | `install-plugin`, `activate-plugin`, `deactivate-plugin`, `install-theme`, `activate-theme` (admin); `update-plugin`, `update-theme`, `apply-updates`, `update-wordpress` (maintain: allowed on live sites; plugins and themes are backed up, health-checked, rolled back on failure and undoable; WordPress itself uses WordPress's own upgrader and is not undoable) | admin / maintain | on |
| Users | `list-users`, `create-user`, `change-user-role` | admin | **off** |
| Files | `list-files`, `read-file`, `write-file`, `delete-file` | dangerous | on |
| Database (read) | `list-tables`, `run-query` | dangerous | on |
| Database (write) | `run-write-query` | dangerous | **off** |
| WP-CLI | `run-wp-cli` (allow-listed commands) | dangerous | on |
| PHP | `run-php` | dangerous | **off** |

All tools are named `wproadie-<name>` in MCP clients. Every change returns an `undo_id`.

**Elementor.** On sites built with Elementor, the AI can read a page as a compact outline, change, add, move, duplicate or remove widgets, insert saved templates, replace text such as a phone number across every page (with a dry run first), and adjust global colors and fonts. Only the targeted element changes: widgets from inactive add-ons and unknown data are never lost. Every change can be undone, and Elementor's CSS is regenerated automatically. Tested against Elementor 3.24, 3.35 and 4.0. See [docs/elementor.md](docs/elementor.md).

**Divi.** On sites running Divi (theme, Extra or the Divi Builder plugin) the AI can outline and edit Divi 4 (shortcode) and Divi 5 (block) pages module by module, add sections and modules, use Divi Library items, find the Theme Builder header and footer, and find and replace text across all Divi pages with a dry run first. Edits change only the targeted module (an unchanged page stays byte-for-byte the same), save a revision, clear Divi's CSS cache and can be undone. Built from Divi's storage formats and source; **check it on a staging copy of a Divi site first**. See [docs/divi.md](docs/divi.md).

## Set up a site in four clicks

No code, no SSH, no passwords to copy:

1. **Install WPRoadie.** In wp-admin go to **Plugins → Add New → Upload Plugin**, choose `wproadie.zip` (from the [latest release](https://github.com/HelloOhab/WPRoadie/releases/latest)) and activate it.
2. **Install the MCP Adapter.** Go to **Tools → WPRoadie**. The **Get set up** checklist offers **Install and activate** for the free MCP Adapter plugin WPRoadie needs (it downloads the tested version from GitHub). The same button appears in the notice on every admin screen.
3. **Connect to HQ.** Check HQ's address (usually filled in already) and click **Connect to HQ**.
4. **Approve in HQ.** HQ shows the site; an HQ admin clicks **Approve**. You land back on the WPRoadie screen: the site is connected and you have AI access.

Then, to use AI on your sites, add **HQ itself as a connector in Claude** (Settings → Connectors → Add custom connector, address `<your HQ>/mcp`); HQ shows the exact steps. One connector covers every site you have access to, with the site's own safety rules and activity log.

The checklist collapses to a single "All set" line once all three steps are done. Prefer the command line, or not using HQ? See [Install by hand or over SSH](#install-by-hand-or-over-ssh) and [Connect an AI client](#connect-an-ai-client).

**Who sees the checklist.** People with WPRoadie AI access always do. Other administrators see only the checklist (and can give themselves access with **Give me access**) unless stealth mode is on and someone already has access; then WPRoadie stays hidden from them. Only people with access can replace an existing HQ connection. Every self-grant, adapter install and HQ pairing is written to the activity log.

## Requirements

- WordPress 6.9+, PHP 7.4+
- The [MCP Adapter](https://github.com/WordPress/mcp-adapter) plugin, **v0.7.1**, installed and active (WPRoadie installs it for you with one click unless the site sets `DISALLOW_FILE_MODS`)
- HTTPS, so WordPress allows Application Passwords (the `local` environment type works without it)

> **Why MCP Adapter is a separate plugin, not bundled.** The plan called for bundling a pinned copy via Composer. MCP Adapter 0.7.0 deprecated that: a bundled copy logs a deprecation notice and support will be removed. It cannot be declared with `Requires Plugins` yet either, because it is not on WordPress.org. So WPRoadie checks for it at runtime, shows an admin notice if it is missing, and can install, activate or update it with one click. That button installs the pinned release (`https://github.com/WordPress/mcp-adapter/releases/download/v<WPROADIE_MCP_ADAPTER_MIN>/mcp-adapter.zip`; change it with the `wproadie_mcp_adapter_package` filter), never "latest", so test each upgrade first. On multisite only a network administrator can use it.

## Install by hand or over SSH

Over SSH (Kinsta gives every site WP-CLI):

```bash
wp plugin install https://github.com/WordPress/mcp-adapter/releases/download/v0.7.1/mcp-adapter.zip --activate
wp plugin install /path/to/wproadie.zip --activate
wp wproadie grant <your-username>
wp wproadie status
```

Activating from wp-admin grants access to the person who activated it. Activating over WP-CLI grants nobody, so run `wp wproadie grant`.

### Rolling out to many sites

`bin/rollout.sh` installs the pinned MCP Adapter and WPRoadie on a list of sites over SSH, optionally grants a user and sets the mode, and prints each site's status:

```bash
# sites.txt: one "<ssh-target> <wordpress-path>" per line
bash bin/rollout.sh sites.txt --grant=abdul --mode=read_only --zip=dist/wproadie.zip
```

Roll out in waves (3 staging sites, then 10, 25, the rest) and start production sites in `read_only` or `production` mode.

## Connect an AI client

In wp-admin go to **Tools → WPRoadie → Connect** and create a connection password. It is shown once, with ready-to-paste config:

- **Claude Code:** a `claude mcp add --transport http …` command. Connects over HTTP directly.
- **Claude Desktop / Cursor:** a JSON block using `@automattic/mcp-wordpress-remote`, which needs Node.js.

The AI acts as you, so everything it does is logged under your name. Keep the password in the team password manager. Give everyone their own WordPress user: MCP Adapter keeps at most 32 live sessions per user and silently drops the oldest, so a shared login makes teammates knock each other off.

**Working across many sites:** the `wproadie` command-line tool in [`cli/`](cli/README.md) holds the site list and gives your AI one connection that routes to whichever site you name ("work on the Smith Dental site").

**Local sites** can skip passwords and use STDIO:

```json
{
  "mcpServers": {
    "wproadie-local": {
      "command": "wp",
      "args": ["--path=/path/to/site", "mcp-adapter", "serve", "--server=wproadie", "--user=admin"]
    }
  }
}
```

## Safety model

1. **Who can connect.** Only users with `manage_options` **and** the `wproadie_connect` capability. The capability is granted per user, never per role, so client admins do not get AI access by default. It is checked on the HTTP transport and again inside every tool, because STDIO has no transport check. The admin screen and its REST API need the same, except the setup checklist ([who sees it](#set-up-a-site-in-four-clicks); turn self-setup off with the `wproadie_admin_can_set_up` filter).
2. **HQ only sets people up as themselves.** When WPRoadie HQ asks for a connection password, it names the signed-in person (their verified Google email). The site issues one only if that email is the WordPress user's own email, or the HQ email a site administrator linked to the user (Who has AI access → HQ sign-in email, or `wp wproadie hq-email <user> <email>`). Connecting with the **Connect to HQ** button links the approving HQ admin's email automatically. Turn the check off with the `wproadie_hq_require_email_match` filter.
3. **The adapter's default server is off.** It admits any logged-in user, including Subscribers. Turn it back on with `add_filter( 'wproadie_allow_default_server', '__return_true' );`.
4. **Modes.** Every tool declares a risk level:

   | Mode | Allows |
   | --- | --- |
   | Staging | everything enabled |
   | Production | read + write (content, media, menus, settings) + maintain (plugin and theme updates) |
   | Read-only | read |

   The mode comes from `WPROADIE_MODE` in wp-config.php, then the admin setting, then `wp_get_environment_type()` (`staging`, `development` and `local` map to Staging; anything else, including unset, maps to Production). It is checked again on every call.
5. **Tool switches.** A tool whose group is off, whose risk the mode forbids, or which this host cannot run (e.g. no `proc_open` for WP-CLI, no direct filesystem for installs, `DISALLOW_FILE_EDIT`/`DISALLOW_FILE_MODS` for file writes) is never registered, so the AI never sees it.
6. **Guardrails inside tools** (all in `src/Safety/Guardrails.php`, each list filterable):
   - **Options:** a write allow-list that excludes anything about logins or the site's address (`admin_email`, `siteurl`, `home`, `users_can_register`, `default_role`…), and a slightly wider read list. Salts and API keys are never readable.
   - **Files:** reads only in themes, plugins, must-use plugins and `wp-content/wproadie`; writes only in the **active child theme** and `wp-content/wproadie` (no PHP there). Symlinks are resolved first; `wp-config*.php`, `.env`, keys and SQL dumps are off limits. PHP is syntax-checked before writing, and after a PHP change the home page is loaded; if it breaks, the change is rolled back automatically.
   - **Database:** reads are one `SELECT`/`SHOW`/`DESCRIBE`/`EXPLAIN`, checked by a quote- and comment-aware lexer and run inside a read-only transaction that is always rolled back; password hashes and session tokens are masked. Writes (separate switch, off by default) are one `INSERT`/`UPDATE`/`DELETE`/`REPLACE`, never schema changes, `UPDATE`/`DELETE` need a `WHERE`, run in a transaction, with `dry_run` and `expected_rows` to check before committing.
   - **WP-CLI:** an allow-list of commands, run without a shell as the connected user; `--exec`, `--require`, `--path`, `--user` and similar flags are refused; `search-replace` only with `--dry-run`; `option get` only for readable options.
   - **Plugins:** WPRoadie and MCP Adapter cannot be deactivated or updated by the AI. Downloads (media, zip installs) only from public addresses: private, loopback, link-local (cloud metadata) and carrier-grade NAT addresses are refused, redirects included.
   - **Users:** no creating or promoting to administrator-like roles, no changing your own role or another admin's.
7. **Audit log.** Every call is written to `{prefix}wproadie_log`: user, tool, input, result (capped at 8 KB), status, duration, transport, IP and undo data. Entries older than 365 days are pruned daily (`wproadie_log_retention_days` filter), with their file backups.
8. **Rate limit.** 120 calls per minute per user by default; change it on the admin screen (0 turns it off).
9. **Kill switch.** `define( 'WPROADIE_DISABLED', true );` in wp-config.php always wins. The admin button and `wp wproadie disable` do the same from the UI or CLI. The admin screen keeps working so WPRoadie can be turned back on.
10. **Stealth mode.** Hides WPRoadie and MCP Adapter from the Plugins screen and Site Health for everyone except WPRoadie users, and hides the WPRoadie menu and setup checklist from other administrators (unless nobody has access yet, so a site cannot be locked out). The connection, log and kill switch keep working.

Known limits: DNS can change between the URL check and the download (rebinding). `run-php` runs in the same PHP process, so a true fatal error (memory, redeclared function) ends that request; over STDIO that ends the session, and the fatal is logged. Over STDIO, PHP files changed by the AI may be served from the web server's OPcache for up to its `revalidate_freq`.

## Undo

Changes made through WPRoadie record how to reverse them. The AI can call `undo-change` with the `undo_id` a tool returned, and people can click **Undo** in **Tools → WPRoadie → Activity**.

Covered: posts and pages (a WordPress revision is also saved), creating and trashing posts, post meta, terms, menus and widgets, options, theme mods, files (backed up to `wp-content/wproadie/backups`), user roles and creation, plugin activation and theme switches.

Not covered: raw SQL writes, PHP snippets, and plugin or theme installs. Their tool descriptions say so. Plugin and theme updates are covered: the previous version is kept in `wp-content/wproadie/backups`, an update that leaves the site erroring is rolled back on the spot, and `undo-change` restores the previous version later. The AI may only undo a change if the current mode allows the original tool; a person can undo from the admin screen in any mode.

## WPRoadie HQ

[WPRoadie HQ](https://github.com/HelloOhab/WPRoadie-HQ) (a separate, private app) shows every site's status in one place and manages the team's connection passwords. Sites report to HQ; AI traffic never goes through it.

**Connect a site:** click **Connect to HQ** under **Tools → WPRoadie** and approve the site in HQ ([details](#set-up-a-site-in-four-clicks)). Only a one-time code travels in the browser; the site trades it, with a verifier it never sends anywhere else, for its secret. To suggest HQ's address on every site, define `WPROADIE_HQ_DEFAULT_URL` in wp-config.php or use the `wproadie_hq_default_url` filter; otherwise the last address used on the site is suggested.

**Or with a connection string:** add the site in HQ, which shows a connection string once, then on the site run

```bash
wp wproadie hq connect 'wproadie-hq:v1:…'
```

or paste it under **Tools → WPRoadie → Get set up → Use a connection string instead**. You can also define `WPROADIE_HQ_URL`, `WPROADIE_HQ_SITE_ID` and `WPROADIE_HQ_SECRET` in wp-config.php.

**What the site sends:** a signed heartbeat every hour (and a minute after the mode, kill switch or tool switches change): versions, mode, who has access, pending updates (including the ones premium plugins only announce inside wp-admin: WPRoadie remembers what wp-admin last showed until the update is installed), Site Health (refreshed every 6 hours) and the last 24 hours of AI activity.

**What HQ can do on the site:** list access, list the tools the site exposes (so HQ can offer them to Claude as one connector), issue and revoke a person's WPRoadie Application Password, and revoke a person entirely when they leave. HQ can only issue passwords to administrators who already have WPRoadie access on that site, only over HTTPS, and never while the kill switch is on. Revocations still work with the kill switch on. Every HQ command appears in the activity log as `hq/…`, user "HQ"; passwords are never logged.

`wp wproadie hq status` shows the last heartbeat; `wp wproadie hq heartbeat` sends one now; `wp wproadie hq disconnect` stops reporting. The protocol is in [`docs/hq-protocol.md`](docs/hq-protocol.md).

## WP-CLI

```
wp wproadie status              Version, adapter, mode, endpoint, tools, who has access
wp wproadie grant <user>        Let an administrator connect (--hq-email=<email> if their HQ email differs)
wp wproadie hq-email <user> [<email>|--clear]  Show or link the HQ sign-in email of a user
wp wproadie revoke <user>       Remove access
wp wproadie mode [<mode>]       Show or set: staging | production | read_only | auto
wp wproadie disable | enable    Kill switch
wp wproadie log [--limit=20]    Recent AI activity
wp wproadie hq connect <string> Connect to WPRoadie HQ (also: hq status, hq heartbeat, hq disconnect)
```

## Updates and releases

WPRoadie updates itself from GitHub releases, like any plugin on the Plugins screen. For the private repository, add a read-only token to wp-config.php (it is only ever sent to api.github.com):

```php
define( 'WPROADIE_GITHUB_TOKEN', 'github_pat_…' );
```

To release: bump the version in `wproadie.php` (header and `WPROADIE_VERSION`) and merge it to `main`. Then either push a tag such as `v0.2.0`, or run the **Release** workflow by hand on `main` (Actions → Release → Run workflow) with the version; it creates the tag itself. Either way it builds `wproadie.zip` and publishes the release.

## Development

```bash
npm install && npm run build   # React admin screen (admin/src → admin/build)
composer install               # PHP lint tooling
composer lint                  # WordPress coding standards + PHP 7.4 compatibility
npm run lint:js
npm run zip                    # dist/wproadie.zip
```

**A test site without Docker:** `bin/test-site.sh` builds WordPress 6.9 on SQLite with MCP Adapter 0.7.1 and this plugin symlinked in, grants `admin`, and switches every tool group on. CI uses it.

```bash
bash bin/test-site.sh /tmp/roadie
WP="php /tmp/roadie/wp-cli.phar --path=/tmp/roadie/wordpress --allow-root"
$WP eval-file tests/integration/run.php --user=admin   # all integration tests
$WP eval-file tests/integration/run.php files --user=admin
WP="$WP" bash tests/smoke.sh                           # end-to-end over MCP STDIO
```

The `adapter` tests download the MCP Adapter from GitHub (and skip with a warning if they cannot); the `hq` tests compare HQ's tool catalog with a real MCP `tools/list`.

With Docker, `npm run env:start` starts wp-env with the same versions.

### Adding a tool

1. Create a class in `src/Abilities/<Group>/` that extends `AbilityBase`. Implement `slug()`, `label()`, `description()`, `group()`, `risk()` and `execute()`. Override `input_schema()` for arguments, `permission()` for per-object checks such as `edit_post`, and `is_available()` if the host might not support it.
2. Before changing anything, call `$this->add_undo( Undo::… )` so the change can be reversed.
3. Add the class to `Registry::ABILITIES` and write tests in `tests/integration/<group>.php`.

The base class handles registration, access and mode checks, the rate limit, exceptions, undo storage and the audit log entry. Write the description for the AI: when to use the tool, what it returns, and what it cannot do.

### Layout

```
wproadie.php               plugin header, autoloader, boot
uninstall.php              drops the log table, settings, capability and WPRoadie passwords
src/Plugin.php             wires hooks
src/Server/                MCP server registration, who may connect
src/Abilities/             AbilityBase, Registry, and one folder per tool group
src/Prompts/               site briefing, care checklist
src/Safety/                Mode, Settings, Guardrails, RateLimit, Stealth
src/Log/                   AuditLog (custom table), Undo
src/Admin/                 admin screen, REST API, passwords, updater, WP-CLI commands
admin/src/                 React admin app
cli/                       `wproadie` command-line tool (Node)
bin/                       test site, zip build, rollout scripts
tests/                     integration tests and MCP smoke test
```

## Not built yet

- **OAuth** instead of Application Passwords, so HQ never stores raw passwords (the plan's later HQ step).
- **Chat in wp-admin** (phase 3).
- **More page builders** (Beaver Builder, Bricks, WooCommerce product editing) and Elementor page settings.

## Clean-room rule

Nobody working on WPRoadie reads or copies Novamira's source. Work only from WordPress docs, the MCP spec and our own design.
