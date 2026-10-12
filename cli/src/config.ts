/**
 * The site registry: a JSON file listing the WordPress sites this person
 * works on. It never holds passwords, only where to get them.
 */
import { chmodSync, existsSync, mkdirSync, readFileSync, renameSync, writeFileSync } from 'node:fs';
import { homedir } from 'node:os';
import { dirname, join } from 'node:path';

export type PasswordSource = 'keychain' | 'command' | 'hq' | 'none';

export interface Site {
  /** Short slug, e.g. "smith-dental". Unique, used on the command line. */
  name: string;
  /** Human name, e.g. "Smith Dental". */
  displayName?: string;
  /** Other names the AI or a person may use for this site. */
  aliases: string[];
  /** Site home URL, e.g. https://smithdental.com */
  url: string;
  /** The discovered MCP endpoint. Empty until discovered. */
  endpoint: string;
  /** WordPress login the Application Password belongs to. */
  user: string;
  /** Where the Application Password comes from. */
  password: PasswordSource;
  /** Shell command that prints the password, when password === "command". */
  passwordCommand?: string;
  /** HQ's ID for the site, when it was synced from WPRoadie HQ. */
  hqSiteId?: string;
  addedAt?: string;
}

/** Where the HQ API token comes from. Never the token itself. */
export type TokenSource = 'keychain' | 'command' | 'env';

export interface HqConfig {
  /** HQ base URL, e.g. https://hq.rhythmco.com */
  url: string;
  token: TokenSource;
  /** Shell command that prints the token, when token === "command". */
  tokenCommand?: string;
}

export interface Registry {
  version: 1;
  sites: Site[];
  hq?: HqConfig;
}

export function configPath(env: NodeJS.ProcessEnv = process.env): string {
  if (env.WPROADIE_CONFIG) {
    return env.WPROADIE_CONFIG;
  }
  const base = env.XDG_CONFIG_HOME && env.XDG_CONFIG_HOME.trim() !== '' ? env.XDG_CONFIG_HOME : join(homedir(), '.config');
  return join(base, 'wproadie', 'sites.json');
}

export function emptyRegistry(): Registry {
  return { version: 1, sites: [] };
}

export function loadRegistry(path: string = configPath()): Registry {
  if (!existsSync(path)) {
    return emptyRegistry();
  }
  let data: unknown;
  try {
    data = JSON.parse(readFileSync(path, 'utf8'));
  } catch (err) {
    throw new Error(`Could not read the site registry at ${path}: ${(err as Error).message}`);
  }
  const sites = (data as { sites?: unknown }).sites;
  if (!Array.isArray(sites)) {
    throw new Error(`The site registry at ${path} is not valid (missing "sites" array).`);
  }
  const registry: Registry = {
    version: 1,
    sites: sites.map((s) => normalizeSite(s as Partial<Site>)),
  };
  const hq = normalizeHq((data as { hq?: unknown }).hq);
  if (hq) registry.hq = hq;
  return registry;
}

function normalizeHq(raw: unknown): HqConfig | undefined {
  if (!raw || typeof raw !== 'object') return undefined;
  const h = raw as Partial<HqConfig>;
  if (!h.url) return undefined;
  const hq: HqConfig = {
    url: String(h.url),
    token: h.token === 'command' || h.token === 'env' ? h.token : 'keychain',
  };
  if (h.tokenCommand) hq.tokenCommand = String(h.tokenCommand);
  return hq;
}

function normalizeSite(s: Partial<Site>): Site {
  const site: Site = {
    name: String(s.name ?? ''),
    aliases: Array.isArray(s.aliases) ? s.aliases.map(String) : [],
    url: String(s.url ?? ''),
    endpoint: String(s.endpoint ?? ''),
    user: String(s.user ?? ''),
    password: s.password === 'keychain' || s.password === 'command' || s.password === 'hq' ? s.password : 'none',
  };
  if (s.hqSiteId) site.hqSiteId = String(s.hqSiteId);
  if (s.displayName) site.displayName = String(s.displayName);
  if (s.passwordCommand) site.passwordCommand = String(s.passwordCommand);
  if (s.addedAt) site.addedAt = String(s.addedAt);
  return site;
}

/**
 * Writes the registry with mode 0600 (directory 0700), atomically via a temp
 * file so a crash never leaves a half-written file.
 */
export function saveRegistry(registry: Registry, path: string = configPath()): void {
  const dir = dirname(path);
  if (!existsSync(dir)) {
    mkdirSync(dir, { recursive: true, mode: 0o700 });
  }
  // Only known fields are written (normalizeSite), so nothing secret can slip in.
  const clean: Registry = { version: 1, sites: registry.sites.map((s) => normalizeSite(s)) };
  const hq = normalizeHq(registry.hq);
  if (hq) clean.hq = hq;
  const tmp = `${path}.${process.pid}.tmp`;
  writeFileSync(tmp, JSON.stringify(clean, null, 2) + '\n', { mode: 0o600 });
  chmodSync(tmp, 0o600);
  renameSync(tmp, path);
  chmodSync(path, 0o600);
}

export function findSiteByName(registry: Registry, name: string): Site | undefined {
  const wanted = name.toLowerCase();
  return registry.sites.find((s) => s.name.toLowerCase() === wanted);
}

/** "Smith Dental" -> "smith-dental". */
export function slugify(input: string): string {
  return input
    .normalize('NFKD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '');
}

/** Normalizes a site URL: adds https:// if missing, drops trailing slashes. */
export function normalizeUrl(input: string): string {
  let raw = input.trim();
  if (!/^https?:\/\//i.test(raw)) {
    raw = `https://${raw}`;
  }
  const url = new URL(raw);
  if (url.protocol !== 'http:' && url.protocol !== 'https:') {
    throw new Error(`Unsupported URL scheme: ${url.protocol}`);
  }
  url.hash = '';
  url.search = '';
  return url.toString().replace(/\/+$/, '');
}

/** Name of the env var that overrides a site's password: WPROADIE_PASSWORD_SMITH_DENTAL. */
export function passwordEnvVar(siteName: string): string {
  return `WPROADIE_PASSWORD_${siteName.toUpperCase().replace(/[^A-Z0-9]+/g, '_').replace(/^_+|_+$/g, '')}`;
}
