# WPRoadie

Rhythm Collective's WordPress plugin for AI agents. It lets Claude, Cursor or any MCP client read and change a client site safely: environment modes, per-user access, a kill switch and an audit log of every call.

WPRoadie is built on WordPress's official AI building blocks: the **Abilities API** (core since 6.9) and the **MCP Adapter** plugin. It is GPL-2.0-or-later.

## Status

Phase 1 foundation. What works today:

| Area | State |
| --- | --- |
| MCP server at `/wp-json/wproadie/mcp` (HTTP) and over WP-CLI (STDIO) | Done |
| Site info tools: `site-overview`, `active-theme`, `list-plugins`, `versions`, `health-check` | Done (read-only) |
| Access: administrators with the `wproadie_connect` capability only | Done |
| Modes: Staging / Production / Read-only, from the environment type or overridden | Done |
| Per-group tool switches | Done (only the Site group has tools so far) |
| Kill switch: admin button, `wp wproadie disable`, or `WPROADIE_DISABLED` | Done |
| Audit log of every call (user, tool, input, result, time, IP, duration) | Done |
| Admin screen (Tools → WPRoadie): connect, access, mode, tools, activity | Done (server-rendered; React later) |
| `wp wproadie` commands for bulk installs over SSH | Done |
| Write tools (content, media, menus, settings…), undo, rate limits, stealth mode, prompts | Not started |

## Requirements

- WordPress 6.9+, PHP 7.4+
- The [MCP Adapter](https://github.com/WordPress/mcp-adapter) plugin, **v0.7.0**, installed and active
- HTTPS, so WordPress allows Application Passwords (the `local` environment type works without it)

> **Why MCP Adapter is a separate plugin, not bundled.** The plan called for bundling a pinned copy via Composer. MCP Adapter 0.7.0 deprecated that: a bundled copy logs a deprecation notice and support will be removed. It cannot be declared with `Requires Plugins` yet either, because it is not on WordPress.org. So WPRoadie checks for it at runtime and shows an admin notice if it is missing. We still pin the version: install the v0.7.0 zip, not "latest", and test each upgrade in wp-env first.

## Install on a site

Over SSH (Kinsta gives every site WP-CLI):

```bash
wp plugin install https://github.com/WordPress/mcp-adapter/releases/download/v0.7.0/mcp-adapter.zip --activate
wp plugin install /path/to/wproadie.zip --activate
wp wproadie grant <your-username>
wp wproadie status
```

Activating from wp-admin grants access to the person who activated it. Activating over WP-CLI grants nobody, so run `wp wproadie grant`.

## Connect an AI client

In wp-admin go to **Tools → WPRoadie → Connect an AI client** and create a connection password. The screen shows it once, with ready-to-paste config:

- **Claude Code:** a `claude mcp add --transport http …` command. Connects over HTTP directly.
- **Claude Desktop / Cursor:** a JSON block using `@automattic/mcp-wordpress-remote`, which needs Node.js.

The AI acts as you, so everything it does is logged under your name. Keep the password in the team password manager.

For a local site you can skip passwords and use STDIO:

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

1. **Who can connect.** Only users with `manage_options` **and** the `wproadie_connect` capability. The capability is granted per user, never per role, so client admins do not get AI access by default. This is checked on the HTTP transport and again inside every tool, because STDIO has no transport check.
2. **The adapter's default server is off.** It admits any logged-in user, including Subscribers. Sites can turn it back on with `add_filter( 'wproadie_allow_default_server', '__return_true' );`.
3. **Modes.** Every tool declares a risk level: `read`, `write`, `admin` or `dangerous`.

   | Mode | Allows |
   | --- | --- |
   | Staging | everything enabled |
   | Production | read + write (content and settings edits) |
   | Read-only | read |

   The mode comes from `WPROADIE_MODE` in wp-config.php, then the admin setting, then `wp_get_environment_type()` (`staging`, `development` and `local` map to Staging; anything else, including unset, maps to Production).
4. **Tool switches.** Admins turn tool groups on or off. A tool that is off, or not allowed in the current mode, is never registered, so the AI never sees it. The mode is checked again on every call.
5. **Audit log.** Every call is written to `{prefix}wproadie_log` with user, tool, input, result (capped at 8 KB), status, duration, transport and IP.
6. **Kill switch.** `define( 'WPROADIE_DISABLED', true );` in wp-config.php always wins. The admin button and `wp wproadie disable` do the same from the UI or CLI.

## WP-CLI

```
wp wproadie status              Version, adapter, mode, endpoint, tools, who has access
wp wproadie grant <user>        Let an administrator connect
wp wproadie revoke <user>       Remove access
wp wproadie mode [<mode>]       Show or set: staging | production | read_only | auto
wp wproadie disable | enable    Kill switch
wp wproadie log [--limit=20]    Recent AI activity
```

## Development

```bash
npm install
npm run env:start        # wp-env: WordPress 6.9 + MCP Adapter 0.7.0 + WPRoadie, grants `admin`
composer install
composer lint            # WordPress coding standards + PHP 7.4 compatibility
```

The smoke test drives the real MCP server over STDIO: it lists tools, calls each one, checks bad input is rejected and checks the audit log.

```bash
WP="wp --path=/path/to/site" WP_USER=admin bash tests/smoke.sh
# or, inside wp-env:
npx wp-env run cli bash wp-content/plugins/<this-folder>/tests/smoke.sh
```

### Adding a tool

1. Create a class in `src/Abilities/<Group>/` that extends `AbilityBase`. Implement `slug()`, `label()`, `description()`, `group()`, `risk()` and `execute()`. Override `input_schema()` for arguments and `permission()` for per-object checks such as `edit_post`.
2. Add it to `Registry::ABILITIES`.

The base class handles registration, the access and mode checks, exceptions and the audit log entry. Write the description for the AI: say when to use the tool and what it returns.

### Layout

```
wproadie.php               plugin header, autoloader, boot
uninstall.php              drops the log table, settings, capability and WPRoadie passwords
src/Plugin.php             wires hooks
src/Server/                MCP server registration, who may connect
src/Abilities/             AbilityBase, Registry, and one folder per tool group
src/Safety/                Mode, Settings (groups, kill switch)
src/Log/AuditLog.php       custom table and queries
src/Admin/                 admin screen, Application Passwords, WP-CLI commands
tests/smoke.sh             end-to-end MCP check
```

## Clean-room rule

Nobody working on WPRoadie reads or copies Novamira's source. Work only from WordPress docs, the MCP spec and our own design.
