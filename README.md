# WPRoadie

Rhythm Collective's WordPress plugin for AI agents. It lets Claude, Cursor or any MCP client read and change a client site safely: environment modes, per-user access, guardrails inside every tool, undo, rate limits, a kill switch and an audit log of every call.

WPRoadie is built on WordPress's official AI building blocks: the **Abilities API** (core since 6.9) and the **MCP Adapter** plugin. It is GPL-2.0-or-later.

## Contents

- [What it can do](#what-it-can-do)
- [Requirements](#requirements)
- [Install](#install-on-a-site) and [roll out to many sites](#rolling-out-to-many-sites)
- [Connect an AI client](#connect-an-ai-client)
- [Safety model](#safety-model)
- [Undo](#undo)
- [WP-CLI commands](#wp-cli)
- [Updates and releases](#updates-and-releases)
- [Development](#development)

## What it can do

52 tools and 2 prompts, in groups an admin can switch on and off. A group's **risk** decides which modes allow it.

| Group | Tools | Risk | Default |
| --- | --- | --- | --- |
| Site info | `site-overview`, `active-theme`, `list-plugins`, `versions`, `health-check`, `activity-log` | read | on |
| Prompts | `site-briefing`, `care-checklist` | read | on |
| Content | `list-posts`, `get-post`, `create-post`, `update-post`, `trash-post`, `get-post-meta`, `update-post-meta`, `list-terms`, `create-term`, `set-post-terms`, `undo-change` | write | on |
| Media | `list-media`, `upload-media-from-url`, `set-featured-image`, `update-media-details` | write | on |
| Navigation | `list-menus`, `get-menu`, `create-menu`, `add-menu-item`, `update-menu-item`, `delete-menu-item`, `assign-menu-location`, `list-widgets`, `add-block-widget`, `remove-widget` | write | on |
| Settings | `get-options`, `update-options` (allow-listed options only) | write | on |
| Plugins & themes | `install-plugin`, `activate-plugin`, `deactivate-plugin`, `update-plugin`, `install-theme`, `activate-theme`, `update-theme` | admin | on |
| Users | `list-users`, `create-user`, `change-user-role` | admin | **off** |
| Files | `list-files`, `read-file`, `write-file`, `delete-file` | dangerous | on |
| Database (read) | `list-tables`, `run-query` | dangerous | on |
| Database (write) | `run-write-query` | dangerous | **off** |
| WP-CLI | `run-wp-cli` (allow-listed commands) | dangerous | on |
| PHP | `run-php` | dangerous | **off** |

All tools are named `wproadie-<name>` in MCP clients. Every change returns an `undo_id`.

## Requirements

- WordPress 6.9+, PHP 7.4+
- The [MCP Adapter](https://github.com/WordPress/mcp-adapter) plugin, **v0.7.0**, installed and active
- HTTPS, so WordPress allows Application Passwords (the `local` environment type works without it)

> **Why MCP Adapter is a separate plugin, not bundled.** The plan called for bundling a pinned copy via Composer. MCP Adapter 0.7.0 deprecated that: a bundled copy logs a deprecation notice and support will be removed. It cannot be declared with `Requires Plugins` yet either, because it is not on WordPress.org. So WPRoadie checks for it at runtime and shows an admin notice if it is missing. We still pin it: install the v0.7.0 zip, not "latest", and test each upgrade first.

## Install on a site

Over SSH (Kinsta gives every site WP-CLI):

```bash
wp plugin install https://github.com/WordPress/mcp-adapter/releases/download/v0.7.0/mcp-adapter.zip --activate
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

1. **Who can connect.** Only users with `manage_options` **and** the `wproadie_connect` capability. The capability is granted per user, never per role, so client admins do not get AI access by default. It is checked on the HTTP transport and again inside every tool, because STDIO has no transport check. The admin screen and its REST API need the same.
2. **The adapter's default server is off.** It admits any logged-in user, including Subscribers. Turn it back on with `add_filter( 'wproadie_allow_default_server', '__return_true' );`.
3. **Modes.** Every tool declares a risk level:

   | Mode | Allows |
   | --- | --- |
   | Staging | everything enabled |
   | Production | read + write (content, media, menus, settings) |
   | Read-only | read |

   The mode comes from `WPROADIE_MODE` in wp-config.php, then the admin setting, then `wp_get_environment_type()` (`staging`, `development` and `local` map to Staging; anything else, including unset, maps to Production). It is checked again on every call.
4. **Tool switches.** A tool whose group is off, whose risk the mode forbids, or which this host cannot run (e.g. no `proc_open` for WP-CLI, no direct filesystem for installs, `DISALLOW_FILE_EDIT`/`DISALLOW_FILE_MODS` for file writes) is never registered, so the AI never sees it.
5. **Guardrails inside tools** (all in `src/Safety/Guardrails.php`, each list filterable):
   - **Options:** a write allow-list that excludes anything about logins or the site's address (`admin_email`, `siteurl`, `home`, `users_can_register`, `default_role`…), and a slightly wider read list. Salts and API keys are never readable.
   - **Files:** reads only in themes, plugins, must-use plugins and `wp-content/wproadie`; writes only in the **active child theme** and `wp-content/wproadie` (no PHP there). Symlinks are resolved first; `wp-config*.php`, `.env`, keys and SQL dumps are off limits. PHP is syntax-checked before writing, and after a PHP change the home page is loaded; if it breaks, the change is rolled back automatically.
   - **Database:** reads are one `SELECT`/`SHOW`/`DESCRIBE`/`EXPLAIN`, checked by a quote- and comment-aware lexer and run inside a read-only transaction that is always rolled back; password hashes and session tokens are masked. Writes (separate switch, off by default) are one `INSERT`/`UPDATE`/`DELETE`/`REPLACE`, never schema changes, `UPDATE`/`DELETE` need a `WHERE`, run in a transaction, with `dry_run` and `expected_rows` to check before committing.
   - **WP-CLI:** an allow-list of commands, run without a shell as the connected user; `--exec`, `--require`, `--path`, `--user` and similar flags are refused; `search-replace` only with `--dry-run`; `option get` only for readable options.
   - **Plugins:** WPRoadie and MCP Adapter cannot be deactivated or updated by the AI. Downloads (media, zip installs) only from public addresses: private, loopback, link-local (cloud metadata) and carrier-grade NAT addresses are refused, redirects included.
   - **Users:** no creating or promoting to administrator-like roles, no changing your own role or another admin's.
6. **Audit log.** Every call is written to `{prefix}wproadie_log`: user, tool, input, result (capped at 8 KB), status, duration, transport, IP and undo data. Entries older than 365 days are pruned daily (`wproadie_log_retention_days` filter), with their file backups.
7. **Rate limit.** 120 calls per minute per user by default; change it on the admin screen (0 turns it off).
8. **Kill switch.** `define( 'WPROADIE_DISABLED', true );` in wp-config.php always wins. The admin button and `wp wproadie disable` do the same from the UI or CLI. The admin screen keeps working so WPRoadie can be turned back on.
9. **Stealth mode.** Hides WPRoadie and MCP Adapter from the Plugins screen and Site Health for everyone except WPRoadie users. The menu item is already only visible to them. The connection, log and kill switch keep working.

Known limits: DNS can change between the URL check and the download (rebinding). `run-php` runs in the same PHP process, so a true fatal error (memory, redeclared function) ends that request; over STDIO that ends the session, and the fatal is logged. Over STDIO, PHP files changed by the AI may be served from the web server's OPcache for up to its `revalidate_freq`.

## Undo

Changes made through WPRoadie record how to reverse them. The AI can call `undo-change` with the `undo_id` a tool returned, and people can click **Undo** in **Tools → WPRoadie → Activity**.

Covered: posts and pages (a WordPress revision is also saved), creating and trashing posts, post meta, terms, menus and widgets, options, theme mods, files (backed up to `wp-content/wproadie/backups`), user roles and creation, plugin activation and theme switches.

Not covered: raw SQL writes, PHP snippets, and plugin or theme installs and updates. Their tool descriptions say so. The AI may only undo a change if the current mode allows the original tool; a person can undo from the admin screen in any mode.

## WP-CLI

```
wp wproadie status              Version, adapter, mode, endpoint, tools, who has access
wp wproadie grant <user>        Let an administrator connect
wp wproadie revoke <user>       Remove access
wp wproadie mode [<mode>]       Show or set: staging | production | read_only | auto
wp wproadie disable | enable    Kill switch
wp wproadie log [--limit=20]    Recent AI activity
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

**A test site without Docker:** `bin/test-site.sh` builds WordPress 6.9 on SQLite with MCP Adapter 0.7.0 and this plugin symlinked in, grants `admin`, and switches every tool group on. CI uses it.

```bash
bash bin/test-site.sh /tmp/roadie
WP="php /tmp/roadie/wp-cli.phar --path=/tmp/roadie/wordpress --allow-root"
$WP eval-file tests/integration/run.php --user=admin   # all integration tests
$WP eval-file tests/integration/run.php files --user=admin
WP="$WP" bash tests/smoke.sh                           # end-to-end over MCP STDIO
```

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

- **WPRoadie HQ** (phase 2): the hosted dashboard for all sites and central credentials, with OAuth instead of Application Passwords.
- **Chat in wp-admin** (phase 3).
- **Page-builder integrations** (phase 3).

## Clean-room rule

Nobody working on WPRoadie reads or copies Novamira's source. Work only from WordPress docs, the MCP spec and our own design.
