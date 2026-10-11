# wproadie CLI

One MCP connection for all your WPRoadie WordPress sites.

Adding 100 client sites as 100 MCP servers in every person's Claude setup does not work. `wproadie` keeps the site list on your machine and gives the AI **one** MCP server. Say "work on the Smith Dental site" and it switches to that site; the site's `wproadie-*` tools and prompts then appear and act on that site only.

```
Claude Code / Desktop / Cursor ──stdio──▶ wproadie mcp ──HTTPS + Application Password──▶ smithdental.com/wp-json/wproadie/mcp
                                                       └─────────────────────────────────▶ jonesbakery.co.uk/…
```

Requires Node.js 20 or newer. GPL-2.0-or-later.

## Install

```bash
npm install -g wproadie        # or run any command with: npx -y wproadie <command>
```

From this repository (before it is published to npm):

```bash
cd cli
npm install
npm run build
npm link                       # puts `wproadie` on your PATH
```

Each site needs the WPRoadie plugin and the MCP Adapter plugin, and your WordPress user needs WPRoadie access (`wp wproadie grant <user>` or **Tools → WPRoadie**). Create an Application Password for yourself in **Tools → WPRoadie → Connect an AI client** and save it in the team password manager.

## Add sites

```bash
# Recommended: the password stays in 1Password; wproadie stores only the command
wproadie sites add smith-dental --url https://smithdental.com --user rhythm \
  --password-command 'op read "op://Rhythm/Smith Dental/wproadie"' \
  --alias "smith" --display-name "Smith Dental"

# Or type it once (hidden input); it is stored in the OS keychain
wproadie sites add jones-bakery --url https://jonesbakery.co.uk --user rhythm
```

`sites add`:

1. Finds the endpoint: `<url>/wp-json/wproadie/mcp`, then `<url>/index.php?rest_route=/wproadie/mcp` (sites without pretty permalinks), then whatever REST root the site advertises in its `Link` header.
2. Checks the credentials with an MCP `initialize` and `tools/list`, and prints the site's name and tool count.
3. Saves the site. If you gave `"Smith Dental"` as the name, the slug becomes `smith-dental` and "Smith Dental" the display name; if you gave no display name, the WordPress site title is used.

Options: `--alias` (repeatable), `--display-name`, `--password-stdin` (read the password from a pipe), `--no-verify` (save without connecting), `--replace`.

Other commands:

```bash
wproadie sites list                     # name, URL, user, where the password comes from
wproadie sites test smith-dental        # connect and count tools
wproadie sites set-password smith-dental [--password-command "<cmd>"]
wproadie sites remove smith-dental      # also deletes its keychain entry
wproadie tools smith-dental             # list the site's tools and prompts
wproadie call smith-dental site-overview            # one-off tool call, prints JSON
wproadie call smith-dental list-plugins '{"status":"active"}'
```

Tool names work with or without the `wproadie-` prefix. Site names on the command line can be the exact name or any unambiguous part of a name, alias or hostname.

### Bulk import

```bash
wproadie sites import sites.csv            # or sites.json
wproadie sites import sites.csv --verify   # also connect to each site that has a password command
```

CSV columns: `name,url,user,password_command` plus optional `display_name,aliases` (aliases separated by `;`):

```csv
name,url,user,password_command,display_name,aliases
smith-dental,https://smithdental.com,rhythm,"op read ""op://Rhythm/Smith Dental/wproadie""",Smith Dental,smith;sd
jones-bakery,https://jonesbakery.co.uk,rhythm,,Jones Bakery,
```

JSON: an array (or `{"sites": [...]}`) of objects with the same keys. Import never prompts. Rows without a `password_command` get one later with `wproadie sites set-password <name>`. A file with a `password` column is refused: do not put passwords in import files. Existing sites are skipped unless you pass `--replace`.

## Connect your AI client

### Claude Code

```bash
wproadie setup claude-code          # prints: claude mcp add wproadie -- npx -y wproadie mcp
wproadie setup claude-code --run    # runs it
wproadie setup claude-code --run --scope user   # available in every project
```

### Claude Desktop and Cursor

```bash
wproadie setup claude-desktop
```

prints:

```json
{
  "mcpServers": {
    "wproadie": { "command": "npx", "args": ["-y", "wproadie", "mcp"] }
  }
}
```

Paste it into `claude_desktop_config.json` (Claude Desktop → Settings → Developer → Edit Config) or `~/.cursor/mcp.json`, then restart the app. Add `--local` to either `setup` command to point at this installed copy (absolute `node` and script paths) instead of `npx`, which is also the fix when a desktop app cannot find `npx` on its PATH.

### Using it

Talk naturally: "Work on the Smith Dental site and check for plugin updates." The AI calls:

| Tool | What it does |
| --- | --- |
| `list_sites` | Names, display names, URLs, aliases, and which site is active. Optional `filter`. |
| `use_site` | Picks a site by name, alias, display name or hostname (`"Smith Dental"`, `"smithdental.com"`, `"sd"`). Matching ignores case and tolerates small typos. If more than one site fits, it returns the candidates and the AI asks you. |
| `current_site` | The active site, its endpoint, tools and prompts. |

After `use_site`, the site's own tools (`wproadie-site-overview`, `wproadie-list-plugins`, …) and prompts (`wproadie-site-briefing`, `wproadie-care-checklist`) appear, and the server sends `notifications/tools/list_changed` and `notifications/prompts/list_changed`. Their descriptions start with `[Smith Dental]` so the AI always sees which site they act on. Calling a site tool before choosing a site returns an error telling the AI to call `use_site`.

Connections are opened on first use and kept per site, so switching back and forth is fast. If a site drops the MCP session (it expires after a day idle, or WordPress evicts it), wproadie reconnects once and retries. Sites added with `wproadie sites add` while the server is running are picked up without a restart.

`wproadie mcp --site smith-dental` starts with a site already selected. Logs go to stderr; stdout carries only the protocol.

## Passwords

When a site's password is needed, wproadie looks in this order:

1. **`WPROADIE_PASSWORD_<NAME>`** environment variable: the site name upper-cased with dashes as underscores, e.g. `WPROADIE_PASSWORD_SMITH_DENTAL`. Useful in CI.
2. **Password command** (`--password-command`), run through your shell (`$SHELL`) each time a connection is opened. stdin is not passed to it; its stderr is shown, so a password-manager unlock prompt still appears. Examples:
   - 1Password: `op read "op://Rhythm/Smith Dental/wproadie"`
   - Bitwarden: `bw get password "Smith Dental WPRoadie"`
   - pass: `pass show clients/smith-dental/wproadie`
   - macOS Keychain: `security find-generic-password -s smith-dental-wproadie -w`
3. **OS keychain** (service `wproadie`, account = site name), when you type or pipe the password to `sites add` / `sites set-password`. Uses macOS Keychain, Windows Credential Manager or the Linux Secret Service.

Where there is no keychain (headless Linux, containers), saving a typed password fails with a message pointing to `--password-command` or the environment variable, and nothing is saved.

## Files

The site registry is JSON at `$XDG_CONFIG_HOME/wproadie/sites.json` (default `~/.config/wproadie/sites.json`, macOS included). Set `WPROADIE_CONFIG` to use another file, e.g. a shared one. It is written with mode `0600` in a `0700` directory and holds names, URLs, usernames, the discovered endpoint and the password *command*, never a password.

## Security notes

- Passwords never go into the registry, the AI's context, or command-line arguments that wproadie runs. They stay in your password manager or keychain and are only held in memory while a connection is open.
- Do not paste Application Passwords into chat. If one leaks, revoke it in Tools → WPRoadie.
- The AI acts as the WordPress user you configured, and every call is in the site's WPRoadie audit log under that name. Use a personal user per team member rather than one shared login, so the log says who did what (and so the MCP Adapter's limit of 32 sessions per user is not shared by the whole team).
- WPRoadie's environment modes still apply: on a Production or Read-only site the riskier tools are not offered, whatever the CLI does.
- A password command runs with your shell and your permissions. Only import CSV/JSON files you trust, since their `password_command` values are run.
- `sites remove` deletes the local entry and keychain item, not the Application Password on the site: revoke it there when someone leaves.
- Use HTTPS site URLs. WordPress refuses Application Passwords over plain HTTP except for local environments.

## Development

```bash
npm install
npm run build        # tsc → dist/
npm run lint         # tsc --noEmit (strict) for src and tests
npm test             # builds, then runs node:test
```

Unit tests (fuzzy matching, registry, import parsing, passwords) always run. The integration tests need a WordPress site with WPRoadie and the MCP Adapter, and are skipped without one:

```bash
WPROADIE_TEST_URL=http://127.0.0.1:8899 \
WPROADIE_TEST_ADMIN_PW_FILE=/path/admin.pw \  # Application Password of a user with WPRoadie access
WPROADIE_TEST_BOB_PW_FILE=/path/bob.pw \      # optional: an admin WITHOUT WPRoadie access, to check the 403
npm test
```

They cover endpoint discovery (including the `?rest_route=` fallback), 401/403/network errors, the `sites`/`call` commands, and a full `wproadie mcp` session driven over stdio with the MCP SDK client.

Set `WPROADIE_DEBUG=1` to print stack traces on errors.
