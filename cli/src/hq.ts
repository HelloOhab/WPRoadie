/**
 * WPRoadie HQ: pull the site list and your own Application Passwords from
 * HQ with a personal API token, instead of adding sites one by one.
 *
 * The token comes from WPROADIE_HQ_TOKEN, a password-manager command, or
 * the OS keychain (service "wproadie", account "hq-token"). Passwords
 * fetched from HQ are kept in memory only, never written to disk.
 */
import { loadRegistry, slugify, type HqConfig, type Registry, type Site } from './config.js';
import { keychainGet, runPasswordCommand, SecretError } from './secrets.js';

export const HQ_TOKEN_ACCOUNT = 'hq-token';
export const HQ_TOKEN_ENV = 'WPROADIE_HQ_TOKEN';

/** One site as HQ's CLI API returns it. */
export interface HqSite {
  id: string;
  name: string;
  client?: string | null;
  url: string;
  mcp_endpoint?: string | null;
  environment?: string | null;
  mode?: string | null;
  disabled?: boolean | null;
  wp_username?: string | null;
  credential?: { username: string; password: string } | null;
}

export class HqError extends Error {}

/** Normalizes an HQ base URL: https only (http for localhost), no trailing slash. */
export function normalizeHqUrl(input: string): string {
  let raw = input.trim();
  if (!/^https?:\/\//i.test(raw)) raw = `https://${raw}`;
  const url = new URL(raw);
  const local = url.hostname === 'localhost' || url.hostname === '127.0.0.1' || url.hostname === '::1';
  if (url.protocol !== 'https:' && !(url.protocol === 'http:' && local)) {
    throw new HqError('HQ must be reached over https:// (http:// is only allowed for localhost).');
  }
  url.hash = '';
  url.search = '';
  return url.toString().replace(/\/+$/, '');
}

export async function getHqToken(hq: HqConfig): Promise<string> {
  const fromEnv = process.env[HQ_TOKEN_ENV];
  if (fromEnv && fromEnv.trim() !== '') return fromEnv.trim();
  if (hq.token === 'command' && hq.tokenCommand) {
    try {
      return await runPasswordCommand(hq.tokenCommand);
    } catch (err) {
      throw new HqError(`Could not get the HQ token: ${(err as Error).message}`);
    }
  }
  if (hq.token === 'keychain') {
    try {
      const value = await keychainGet(HQ_TOKEN_ACCOUNT);
      if (value) return value;
    } catch (err) {
      throw new HqError(`Could not get the HQ token: ${(err as SecretError).message}`);
    }
  }
  throw new HqError(`No HQ token. Run "wproadie hq login ${hq.url}" or set ${HQ_TOKEN_ENV}.`);
}

/** Fetches the sites HQ shows this token's owner. */
export async function fetchHqSites(hq: HqConfig, token: string, fetchImpl: typeof fetch = fetch): Promise<HqSite[]> {
  let response: Response;
  try {
    response = await fetchImpl(`${hq.url}/api/v1/cli/sites`, {
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
      redirect: 'error',
      signal: AbortSignal.timeout(20000),
    });
  } catch (err) {
    throw new HqError(`Could not reach HQ at ${hq.url}: ${(err as Error).message}`);
  }
  if (response.status === 401 || response.status === 403) {
    throw new HqError('HQ refused the token. It may have been revoked, or your HQ account deactivated: create a new one under "Me" in HQ and run "wproadie hq login" again.');
  }
  if (!response.ok) {
    throw new HqError(`HQ answered HTTP ${response.status}.`);
  }
  const data = (await response.json()) as unknown;
  const list = Array.isArray(data) ? data : (data as { sites?: unknown })?.sites;
  if (!Array.isArray(list)) {
    throw new HqError('HQ sent an unexpected response (no site list).');
  }
  return list.filter((s): s is HqSite => !!s && typeof s === 'object' && typeof (s as HqSite).id === 'string' && typeof (s as HqSite).url === 'string');
}

export interface SyncResult {
  added: string[];
  updated: string[];
  removed: string[];
  withoutCredential: string[];
}

/**
 * Merges HQ's sites into the registry. Sites are matched by HQ ID, then by
 * URL; local names and aliases are kept. With prune, HQ-synced sites that
 * HQ no longer lists are removed (sites added by hand never are).
 */
export function mergeHqSites(registry: Registry, hqSites: HqSite[], prune = false): SyncResult {
  const result: SyncResult = { added: [], updated: [], removed: [], withoutCredential: [] };
  const seen = new Set<string>();
  const taken = new Set(registry.sites.map((s) => s.name));

  for (const remote of hqSites) {
    seen.add(remote.id);
    const url = remote.url.replace(/\/+$/, '');
    let site = registry.sites.find((s) => s.hqSiteId === remote.id) ?? registry.sites.find((s) => s.url === url);
    const user = remote.credential?.username ?? remote.wp_username ?? '';

    if (!site) {
      let name = slugify(remote.name) || slugify(new URL(url).hostname);
      for (let i = 2; taken.has(name); i++) name = `${slugify(remote.name)}-${i}`;
      taken.add(name);
      site = { name, aliases: [], url, endpoint: '', user, password: 'hq' };
      registry.sites.push(site);
      result.added.push(name);
    } else {
      result.updated.push(site.name);
    }

    site.hqSiteId = remote.id;
    site.url = url;
    site.displayName = remote.name;
    if (remote.mcp_endpoint) site.endpoint = remote.mcp_endpoint;
    if (user) site.user = user;
    site.password = 'hq';
    delete site.passwordCommand;
    if (remote.client && !site.aliases.includes(remote.client)) site.aliases.push(remote.client);
    if (!remote.credential) result.withoutCredential.push(site.name);
  }

  if (prune) {
    registry.sites = registry.sites.filter((s) => {
      if (s.hqSiteId && !seen.has(s.hqSiteId)) {
        result.removed.push(s.name);
        return false;
      }
      return true;
    });
  }
  return result;
}

// HQ's answer is cached for the life of the process (e.g. one `wproadie
// mcp` session) so each site switch does not call HQ again.
let cache: { at: number; sites: HqSite[] } | undefined;
const CACHE_MS = 5 * 60 * 1000;

export function clearHqCache(): void {
  cache = undefined;
}

/** The Application Password HQ holds for this person on this site. */
export async function hqPassword(site: Site, registry?: Registry): Promise<{ username: string; password: string }> {
  const reg = registry ?? loadRegistry();
  if (!reg.hq) {
    throw new HqError(`${site.name} gets its password from HQ, but HQ is not set up. Run "wproadie hq login <url>".`);
  }
  if (!cache || Date.now() - cache.at > CACHE_MS) {
    const token = await getHqToken(reg.hq);
    cache = { at: Date.now(), sites: await fetchHqSites(reg.hq, token) };
  }
  const remote = cache.sites.find((s) => s.id === site.hqSiteId) ?? cache.sites.find((s) => s.url.replace(/\/+$/, '') === site.url);
  if (!remote) {
    throw new HqError(`HQ no longer lists ${site.name}. Run "wproadie hq sync --prune".`);
  }
  if (!remote.credential) {
    throw new HqError(`You have no active connection password for ${site.name} in HQ (none issued yet, or it was revoked). Open the site in HQ and click "Provision credential".`);
  }
  return remote.credential;
}
