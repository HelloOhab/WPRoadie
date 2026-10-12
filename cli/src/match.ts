/**
 * Matches what a person or the AI calls a site ("Smith Dental",
 * "smithdental.com", "the smith dental site") to a registry entry.
 * When more than one site fits equally well it returns them all, so the
 * caller can ask instead of guessing.
 */
import type { Site } from './config.js';

export type MatchResult =
  | { kind: 'match'; site: Site }
  | { kind: 'ambiguous'; candidates: Site[] }
  | { kind: 'none'; suggestions: Site[] };

const FILLER = new Set(['the', 'site', 'website', 'wp', 'wordpress', 'client', 'on', 'for', 'our', 'work']);

/** Lowercase, letters and digits only. */
export function compact(s: string): string {
  return s
    .normalize('NFKD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]/g, '');
}

function tokens(s: string): string[] {
  return s
    .normalize('NFKD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .split(/[^a-z0-9]+/)
    .filter(Boolean);
}

function hostOf(input: string): string | undefined {
  try {
    const url = new URL(/^https?:\/\//i.test(input) ? input : `https://${input}`);
    return url.hostname.toLowerCase().replace(/^www\./, '');
  } catch {
    return undefined;
  }
}

/** All the strings a site can be known by. */
export function siteKeys(site: Site): string[] {
  const keys = [site.name, ...(site.displayName ? [site.displayName] : []), ...site.aliases];
  const host = hostOf(site.url);
  if (host) {
    keys.push(host);
    // "smithdental.com" -> "smithdental"; "smith.dental.co.uk" -> "smith.dental"
    const parts = host.split('.');
    if (parts.length > 1) {
      keys.push(parts.slice(0, -1).join('.'));
      keys.push(parts[0] as string);
    }
  }
  return keys.filter((k) => compact(k) !== '');
}

export function levenshtein(a: string, b: string): number {
  if (a === b) return 0;
  if (!a.length) return b.length;
  if (!b.length) return a.length;
  let prev = Array.from({ length: b.length + 1 }, (_, i) => i);
  for (let i = 1; i <= a.length; i++) {
    const cur = [i];
    for (let j = 1; j <= b.length; j++) {
      const cost = a[i - 1] === b[j - 1] ? 0 : 1;
      cur[j] = Math.min((cur[j - 1] as number) + 1, (prev[j] as number) + 1, (prev[j - 1] as number) + cost);
    }
    prev = cur;
  }
  return prev[b.length] as number;
}

/** Scores how well a query fits one key. 0 = not at all, 100 = exact. */
function scoreKey(query: string, key: string): number {
  const q = compact(query);
  const k = compact(key);
  if (!q || !k) return 0;
  if (q === k) return 100;

  let score = 0;
  // Query is part of the key ("smith" in "smithdental") or the reverse.
  if (q.length >= 3 && k.includes(q)) {
    score = Math.max(score, 70 + Math.round(20 * (q.length / k.length)));
  } else if (k.length >= 4 && q.includes(k)) {
    score = Math.max(score, 60 + Math.round(20 * (k.length / q.length)));
  }
  // Every word of the query appears in the key's words, in any order.
  const qt = tokens(query);
  const kt = tokens(key);
  if (qt.length && kt.length && qt.every((t) => kt.some((w) => w.startsWith(t)))) {
    score = Math.max(score, 75);
  }
  // Typos: "smth dental" vs "smithdental".
  const dist = levenshtein(q, k);
  const similarity = 1 - dist / Math.max(q.length, k.length);
  if (similarity >= 0.75 && Math.max(q.length, k.length) >= 4) {
    score = Math.max(score, Math.round(50 + 40 * similarity) - 5);
  }
  return score;
}

export function scoreSite(query: string, site: Site): number {
  const queries = [query];
  const host = /[./]/.test(query) ? hostOf(query.trim()) : undefined;
  if (host) queries.push(host);
  const stripped = tokens(query).filter((t) => !FILLER.has(t)).join(' ');
  if (stripped && stripped !== query.toLowerCase()) queries.push(stripped);

  let best = 0;
  for (const q of queries) {
    for (const key of siteKeys(site)) {
      best = Math.max(best, scoreKey(q, key));
    }
  }
  return best;
}

export const MIN_SCORE = 60;

export function matchSite(query: string, sites: Site[]): MatchResult {
  const q = query.trim();
  if (!q) {
    return { kind: 'none', suggestions: [] };
  }
  // An exact slug always wins outright.
  const exactName = sites.find((s) => s.name.toLowerCase() === q.toLowerCase());
  if (exactName) {
    return { kind: 'match', site: exactName };
  }

  const scored = sites
    .map((site) => ({ site, score: scoreSite(q, site) }))
    .filter((x) => x.score > 0)
    .sort((a, b) => b.score - a.score);

  const top = scored[0];
  if (!top || top.score < MIN_SCORE) {
    return { kind: 'none', suggestions: scored.slice(0, 5).map((x) => x.site) };
  }
  // Anything within a few points of the best is a real contender.
  const contenders = scored.filter((x) => x.score >= MIN_SCORE && top.score - x.score <= 5);
  if (contenders.length === 1) {
    return { kind: 'match', site: top.site };
  }
  return { kind: 'ambiguous', candidates: contenders.map((x) => x.site) };
}
