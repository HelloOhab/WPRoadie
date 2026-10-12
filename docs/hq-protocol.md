# WPRoadie ⇄ HQ protocol (v1)

How a WordPress site running WPRoadie talks to WPRoadie HQ. HQ receives status and, on request, creates or revokes the Application Passwords people use to connect. AI clients can connect to each site directly, or to HQ as a single connector that forwards to the sites (see the end of this document).

Both sides implement this document: the plugin in `src/Hq/`, HQ in the `WPRoadie-HQ` repository. Change both together and bump the version.

## Connecting a site

1. An HQ admin adds the site in HQ. HQ creates a `site_id` (UUID) and a 32-byte random `secret`, and shows a **connection string** once:

   ```
   wproadie-hq:v1:<base64url(JSON)>
   ```

   where the JSON is `{"url":"https://hq.example.com","site_id":"…","secret":"<base64url, 43 chars>"}`.

2. On the site: `wp wproadie hq connect '<connection string>'`, or paste it on the WPRoadie admin screen. The plugin stores it in the `wproadie_hq` option (not autoloaded). Alternatively define `WPROADIE_HQ_URL`, `WPROADIE_HQ_SITE_ID` and `WPROADIE_HQ_SECRET` in wp-config.php, which take precedence.

3. The plugin sends a heartbeat straight away. HQ shows the site as connected once the first signed heartbeat arrives.

Disconnecting (`wp wproadie hq disconnect`) deletes the option. HQ keeps the site record until an admin removes it.

### Connecting with the button (pairing, plugin 0.4.0+)

The usual way: no connection string is copied by hand. The secret never appears in a URL; only a one-time code does, and that code is useless without a verifier the site keeps on its own server (the same idea as OAuth's PKCE).

1. In wp-admin (Tools → WPRoadie) an administrator clicks **Connect to HQ** and confirms HQ's address. The address is prefilled from the `WPROADIE_HQ_DEFAULT_URL` constant, the `wproadie_hq_default_url` filter, or the address last used on this site.

2. The plugin (REST `POST wproadie/v1/hq/pair`, same permission as connecting by string: an administrator with WPRoadie access, or, while the site is not connected yet, any administrator allowed to set WPRoadie up; refused with the kill switch on or when wp-config.php constants define the connection) creates a random `state` (32 lowercase hex) and `verifier` (43 base64url characters from 32 random bytes), keeps `{hq_url, verifier, user_id}` for 15 minutes keyed by `state`, and sends the browser to:

   ```
   <hq>/connect?v=1&site_url=…&site_name=…&return_url=…&state=…&challenge=…&wp_user=…&environment=…
   ```

   | Parameter | Value |
   | --- | --- |
   | `site_url` | `home_url()` |
   | `site_name` | the site title |
   | `return_url` | the WPRoadie admin screen, `admin_url( 'tools.php?page=wproadie' )` |
   | `state` | as above |
   | `challenge` | base64url (no padding) of SHA-256 of the verifier's ASCII bytes |
   | `wp_user` | the clicking administrator's login |
   | `environment` | `wp_get_environment_type()` |

3. HQ shows the request to a signed-in HQ **admin** (others are told to ask one), with the site's name and address. It refuses a `site_url` or `return_url` that is not `https://` (unless `HQ_ALLOW_HTTP_SITES=1`). If a site with the same address is already in HQ, approving reconnects that site and replaces its secret. On **Approve** HQ creates (or reconnects) the site, stores a one-time code (valid 10 minutes, kept as a SHA-256 hash, bound to the site, the new secret and the challenge), records `wp_user` as the approving person's WordPress username for that site, and redirects to `return_url` with `wproadie_hq=<code>&state=<state>` added. On **Cancel** it redirects with `wproadie_hq_error=access_denied&state=<state>`.

   The code is 43 base64url characters.

4. The WPRoadie screen sees the parameters and calls REST `POST wproadie/v1/hq/pair/finish` with `{code, state}`. The plugin checks that the `state` exists and belongs to the current user, deletes it, and calls:

   ```
   POST <hq>/api/v1/pair/exchange
   Content-Type: application/json

   {"code":"…","code_verifier":"…","site_url":"<home_url()>"}
   ```

   HQ answers `200 {"connection_string":"wproadie-hq:v1:…","hq_email":"<approving admin's email>"}` and marks the code used, or `400 {"error":"invalid_code"}` (unknown, used, expired, or the verifier does not match; no other detail). The plugin checks that the connection string's `url` has the same origin as the HQ address it started with, stores it exactly as a manual connect would, sends a heartbeat, and gives the clicking administrator WPRoadie access if they did not have it (audit-logged as `hq/pair`). Plugin 0.5.0+: if that administrator's WordPress email differs from `hq_email` and they have no linked HQ email yet, `hq_email` becomes their linked HQ email (see `provision_credential`).

The manual connection string stays available on both sides (HQ: Add site; plugin: "Use a connection string instead").

## Signing

Every request in either direction is signed with the site's secret. There is no other authentication: no WordPress login, no HQ session.

Headers:

| Header | Value |
| --- | --- |
| `X-WPRoadie-Site` | the site ID |
| `X-WPRoadie-Timestamp` | Unix time in seconds |
| `X-WPRoadie-Nonce` | 32 lowercase hex characters, random per request |
| `X-WPRoadie-Signature` | `v1=` + lowercase hex HMAC-SHA256 |

The HMAC key is the raw 32 bytes of the secret (base64url-decoded). The signed message is these lines joined with `\n`:

```
v1
<purpose>
<site_id>
<timestamp>
<nonce>
<lowercase hex SHA-256 of the exact request body bytes>
```

`purpose` is `heartbeat` for site → HQ heartbeats and `command` for HQ → site commands, so a captured request cannot be replayed in the other direction.

The receiver:

- rejects a timestamp more than **300 seconds** away from its own clock;
- rejects a nonce it has accepted in the last **600 seconds** (site: a transient per nonce; HQ: a table with an expiry);
- compares signatures in constant time;
- answers `401` with `{"error":"bad_signature"}` (or `stale`, `replayed`, `unknown_site`) and no other detail.

Bodies are JSON (`Content-Type: application/json`). Responses are not signed: they come back over the same HTTPS connection the signed request opened.

## Heartbeat (site → HQ)

`POST <hq url>/api/v1/heartbeat`, purpose `heartbeat`. Sent hourly by WP-Cron, on connect, and about a minute after the mode, kill switch or tool switches change.

```json
{
  "schema": 1,
  "site_id": "…",
  "sent_at": "2026-10-12T10:00:00Z",
  "site": {
    "name": "Smith Dental",
    "home_url": "https://smithdental.com",
    "admin_url": "https://smithdental.com/wp-admin/",
    "environment_type": "production",
    "multisite": false,
    "mcp_endpoint": "https://smithdental.com/wp-json/wproadie/mcp",
    "hq_endpoint": "https://smithdental.com/wp-json/wproadie/v1/hq"
  },
  "versions": { "wordpress": "6.9", "php": "8.3.6", "wproadie": "0.4.0", "mcp_adapter": "0.7.0", "database": "8.0.38" },
  "wproadie": {
    "mode": "production",
    "mode_source": "environment",
    "disabled": false,
    "stealth": true,
    "rate_limit": 120,
    "tools_exposed": 31,
    "tools_hash": "9f2c…",
    "users_with_access": ["abdul", "maria"]
  },
  "updates": {
    "core": "6.9.1",
    "plugins": [ { "file": "akismet/akismet.php", "name": "Akismet", "version": "5.3", "new_version": "5.4" } ],
    "themes":  [ { "stylesheet": "twentytwentyfive", "name": "Twenty Twenty-Five", "version": "1.3", "new_version": "1.4" } ]
  },
  "health": { "checked_at": "2026-10-12T06:00:00Z", "critical": 1, "recommended": 4, "critical_items": ["…"] },
  "activity": {
    "last_call_at": "2026-10-12T09:41:12Z",
    "last_call_user": "abdul",
    "last_call_tool": "wproadie/update-post",
    "calls_24h": 37,
    "errors_24h": 2,
    "users_24h": ["abdul"]
  }
}
```

Any field may be `null` when unknown. `health` is recomputed at most every 6 hours (Site Health's checks are slow). `updates` uses WordPress's cached update data; the heartbeat never triggers an update check itself.

HQ answers `200 {"ok":true}`. The `site.hq_endpoint` from the latest valid heartbeat is where HQ sends commands.

## Commands (HQ → site)

`POST <site.hq_endpoint>` (the REST route `wproadie/v1/hq`), purpose `command`. Body:

```json
{ "command": "provision_credential", "args": { "wp_user": "abdul", "label": "Abdul (HQ)", "hq_email": "abdul@rhythmco.com" } }
```

The site answers `200` with `{"ok":true,"result":{…}}`, or `4xx` with `{"ok":false,"error":"<code>","message":"…"}`. Every command is written to the site's WPRoadie audit log as `hq/<command>` (never including a password).

| Command | Args | Result | Notes |
| --- | --- | --- | --- |
| `ping` | – | `{ "version": "0.4.0" }` | Allowed with the kill switch on. |
| `heartbeat` | – | the heartbeat body | Lets HQ refresh a site on demand. Allowed with the kill switch on. |
| `list_access` | – | `{ "users": [ { "login", "display_name", "email", "hq_email", "is_admin", "has_access", "passwords": [ { "uuid", "name", "created", "last_used" } ] } ] }` | Users with WPRoadie access, plus their WPRoadie Application Passwords. `hq_email` (0.5.0+) is the linked HQ email, or empty. |
| `provision_credential` | `wp_user` (login, email, or linked HQ email), `label`, `hq_email` | `{ "username", "uuid", "password" }` | The user must exist, be an administrator and already have WPRoadie access (`wp wproadie grant`): HQ cannot grant access. Plugin 0.5.0+: `hq_email` is the verified email of the HQ person asking, and the password is only issued if it equals (ignoring case) the WordPress user's email or the HQ email a site administrator linked to that user (Tools → WPRoadie → Who has AI access, or `wp wproadie hq-email <user> <email>`); otherwise `email_mismatch`. So an HQ account cannot get a password for someone else's login by naming it. Sites can turn the check off with the `wproadie_hq_require_email_match` filter. Refused with the kill switch on, and unless the site is HTTPS (or the environment type is `local`). The password is returned once and never logged. The Application Password is named `WPRoadie HQ – <label>`. |
| `revoke_credential` | `wp_user`, `uuid` | `{ "revoked": true }` | Only passwords whose name starts with `WPRoadie` can be revoked this way. |
| `revoke_user` | `wp_user` | `{ "passwords_revoked": 2, "access_removed": true }` | Deletes all of the user's WPRoadie Application Passwords and removes their WPRoadie access. This is the "someone left" action. Allowed with the kill switch on. |
| `list_tools` | – | `{ "tools_hash": "…", "disabled": false, "tools": [ { "name", "title", "description", "inputSchema", "annotations", "risk" } ] }` | Plugin 0.4.0+. The MCP tools the site exposes right now (respecting mode and tool switches), named and shaped exactly as the site's own MCP `tools/list` returns them, plus WPRoadie's `risk` (`read`, `write`, `admin`, `dangerous`). `tools_hash` is the lowercase hex SHA-256 of the JSON-encoded `tools` array and matches the heartbeat's `wproadie.tools_hash`, so HQ refetches only when it changes. Allowed with the kill switch on (`disabled` is then `true`). |

Error codes: `unknown_command`, `bad_args`, `user_not_found`, `no_access` (user has no WPRoadie access), `email_mismatch` (the HQ person is not that WordPress user), `disabled` (kill switch on), `insecure` (not HTTPS), `not_found` (no such password), `failed`.

## Details both sides follow

These were settled while building both sides; they are part of the contract.

- **Encoding.** Connection strings are base64url without padding (decoders accept padding). The site ID is a lowercase UUID. Nonces and signatures are lowercase hex; uppercase is rejected.
- **Header checks.** A missing or malformed timestamp, nonce or signature gets `bad_signature`; a site header that is not a UUID gets `unknown_site`. Checks run in this order: `unknown_site`, `stale`, `bad_signature`, `replayed`. The ±300 s window is inclusive. HQ keeps one nonce store for all sites.
- **After a valid signature (HQ).** Invalid JSON, a body that fails validation, or a body `site_id` different from the header gets `400 {"error":"bad_request"}`; a body over 512 KB gets `413 {"error":"too_large"}`. The nonce is spent either way.
- **Commands.** Commands without arguments send `"args":{}`. The `heartbeat` command's result must carry the right `site_id`, and HQ stores it as an ordinary heartbeat. HQ follows no redirects and only sends commands to `https://` endpoints (`HQ_ALLOW_HTTP_SITES=1` for local testing).
- **Provisioning.** HQ sends the person's WordPress username for that site (a per-site override, else their default) and the label `"<name> (HQ)"`. It stores the `username` the site returns. If `revoke_credential` answers `not_found`, HQ still marks its copy revoked: the password is gone either way.
- **Tool catalog.** `list_tools` lists the tools in the order the site's `tools/list` does. `tools_hash` is computed over the exact bytes of PHP's `json_encode( $tools )` with no flags (what `wp_json_encode` writes by default): `/` escaped as `\/`, non-ASCII characters as `\uXXXX`, an empty `properties` or `annotations` as `{}`. HQ treats the hash as opaque: it compares it with the last one it stored and does not recompute it from a re-encoded copy. With the kill switch on, `tools` is `[]` (nothing is exposed) and `tools_hash` is the hash of `[]`. The heartbeat's `tools_hash` is the same value.
- **Revoke everywhere.** HQ sends `revoke_user` to every connected site, five at a time; `user_not_found` counts as done. It marks all of that person's stored passwords revoked whatever the sites answer, and it is safe to run again for sites that were unreachable.

## CLI API (HQ ↔ `wproadie` command)

`GET <hq url>/api/v1/cli/sites` with `Authorization: Bearer wrhq_…` (a personal token created under **Me** in HQ). It answers `{"sites":[…]}`, each site being:

```json
{ "id": "…", "name": "Smith Dental", "client": "…", "url": "https://…", "mcp_endpoint": "https://…/wp-json/wproadie/mcp",
  "environment": "production", "mode": "production", "disabled": false, "wp_username": "abdul",
  "credential": { "username": "abdul", "password": "abcd efgh …" } }
```

`credential` is the caller's own active password for that site, or `null`. A revoked token or deactivated HQ account gets `401`.

## HQ as a Claude connector (HQ 1.1+)

People can add HQ itself to Claude (Settings → Connectors → Add custom connector → `<hq>/mcp`) and sign in with Google. HQ is then an MCP server (Streamable HTTP, OAuth 2.1 with PKCE and dynamic client registration) that offers `list_sites` plus every WPRoadie tool from the sites' `list_tools` catalogs, each with an extra required `site` argument. A call is forwarded to that site's MCP endpoint with **the caller's own** Application Password (HQ provisions one on first use when the person has WPRoadie access there), so the site's modes, guardrails, rate limit and audit log apply exactly as for a direct connection, and the site's log shows the real person. Nothing in this protocol changes for that: HQ uses the site's normal MCP endpoint and the commands above.

## Security notes

- Whoever holds a site's secret can mint Application Passwords for that site's WPRoadie users. HQ stores secrets and passwords encrypted at rest, and only HQ admins can add sites or revoke people.
- The plugin will only provision passwords for users who already have WPRoadie access on that site, so HQ cannot widen access on its own.
- Rotating a secret: generate a new connection string in HQ and run `wp wproadie hq connect` again; the old secret stops working immediately.

## Test vector

Both implementations must reproduce this exactly.

| | |
| --- | --- |
| secret (base64url) | `AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8` (bytes 0x00 … 0x1f) |
| purpose | `command` |
| site_id | `6f1c2a3e-1b2c-4d5e-8f90-123456789abc` |
| timestamp | `1760263200` |
| nonce | `0123456789abcdef0123456789abcdef` |
| body | `{"command":"ping","args":{}}` |
| body SHA-256 | `3daba29c772cebe5bfcc4428ab2ecec1f5d9586ffeba06f8b26752d369212ae4` |
| signature | `v1=1d0d93429950e4120cf5d9edca5275746aa674cb4cd8ea520aae73cbfd901bdf` |
