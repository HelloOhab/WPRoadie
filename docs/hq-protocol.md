# WPRoadie ⇄ HQ protocol (v1)

How a WordPress site running WPRoadie talks to WPRoadie HQ. HQ never carries AI traffic: AI clients still connect to each site directly. HQ only receives status and, on request, creates or revokes the Application Passwords people use to connect.

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
  "versions": { "wordpress": "6.9", "php": "8.3.6", "wproadie": "0.3.0", "mcp_adapter": "0.7.0", "database": "8.0.38" },
  "wproadie": {
    "mode": "production",
    "mode_source": "environment",
    "disabled": false,
    "stealth": true,
    "rate_limit": 120,
    "tools_exposed": 31,
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
{ "command": "provision_credential", "args": { "wp_user": "abdul", "label": "Abdul (HQ)" } }
```

The site answers `200` with `{"ok":true,"result":{…}}`, or `4xx` with `{"ok":false,"error":"<code>","message":"…"}`. Every command is written to the site's WPRoadie audit log as `hq/<command>` (never including a password).

| Command | Args | Result | Notes |
| --- | --- | --- | --- |
| `ping` | – | `{ "version": "0.3.0" }` | Allowed with the kill switch on. |
| `heartbeat` | – | the heartbeat body | Lets HQ refresh a site on demand. Allowed with the kill switch on. |
| `list_access` | – | `{ "users": [ { "login", "display_name", "email", "is_admin", "has_access", "passwords": [ { "uuid", "name", "created", "last_used" } ] } ] }` | Users with WPRoadie access, plus their WPRoadie Application Passwords. |
| `provision_credential` | `wp_user` (login or email), `label` | `{ "username", "uuid", "password" }` | The user must exist, be an administrator and already have WPRoadie access (`wp wproadie grant`): HQ cannot grant access. Refused with the kill switch on, and unless the site is HTTPS (or the environment type is `local`). The password is returned once and never logged. The Application Password is named `WPRoadie HQ – <label>`. |
| `revoke_credential` | `wp_user`, `uuid` | `{ "revoked": true }` | Only passwords whose name starts with `WPRoadie` can be revoked this way. |
| `revoke_user` | `wp_user` | `{ "passwords_revoked": 2, "access_removed": true }` | Deletes all of the user's WPRoadie Application Passwords and removes their WPRoadie access. This is the "someone left" action. Allowed with the kill switch on. |

Error codes: `unknown_command`, `bad_args`, `user_not_found`, `no_access` (user has no WPRoadie access), `disabled` (kill switch on), `insecure` (not HTTPS), `not_found` (no such password), `failed`.

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
