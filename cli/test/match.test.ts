import { strict as assert } from 'node:assert';
import { describe, it } from 'node:test';
import type { Site } from '../dist/config.js';
import { levenshtein, matchSite } from '../dist/match.js';

function site(name: string, url: string, extra: Partial<Site> = {}): Site {
  return { name, url, aliases: [], endpoint: '', user: 'rhythm', password: 'none', ...extra };
}

const SITES: Site[] = [
  site('smith-dental', 'https://www.smithdental.com', { displayName: 'Smith Dental', aliases: ['sd'] }),
  site('smith-law', 'https://smithlawgroup.com', { displayName: 'Smith Law Group' }),
  site('jones-bakery', 'https://jonesbakery.co.uk', { aliases: ['bakery'] }),
  site('acme', 'https://acme.example.org'),
];

function name(query: string): string {
  const m = matchSite(query, SITES);
  assert.equal(m.kind, 'match', `expected "${query}" to match one site, got ${m.kind}`);
  return m.kind === 'match' ? m.site.name : '';
}

describe('matchSite', () => {
  it('matches the exact slug, case-insensitively', () => {
    assert.equal(name('smith-dental'), 'smith-dental');
    assert.equal(name('SMITH-DENTAL'), 'smith-dental');
  });

  it('matches display names and phrases people use', () => {
    assert.equal(name('Smith Dental'), 'smith-dental');
    assert.equal(name('the Smith Dental site'), 'smith-dental');
    assert.equal(name('smith law group'), 'smith-law');
    assert.equal(name('Jones Bakery'), 'jones-bakery');
  });

  it('matches aliases', () => {
    assert.equal(name('sd'), 'smith-dental');
    assert.equal(name('bakery'), 'jones-bakery');
  });

  it('matches URLs and hostnames', () => {
    assert.equal(name('smithdental.com'), 'smith-dental');
    assert.equal(name('https://www.smithdental.com/contact/'), 'smith-dental');
    assert.equal(name('jonesbakery.co.uk'), 'jones-bakery');
    assert.equal(name('acme.example.org'), 'acme');
  });

  it('tolerates small typos', () => {
    assert.equal(name('smth dental'), 'smith-dental');
    assert.equal(name('jones bakry'), 'jones-bakery');
  });

  it('returns candidates instead of guessing when ambiguous', () => {
    const m = matchSite('smith', SITES);
    assert.equal(m.kind, 'ambiguous');
    if (m.kind === 'ambiguous') {
      assert.deepEqual(m.candidates.map((s) => s.name).sort(), ['smith-dental', 'smith-law']);
    }
  });

  it('treats two sites sharing an alias as ambiguous', () => {
    const sites = [site('a', 'https://a.com', { aliases: ['main'] }), site('b', 'https://b.com', { aliases: ['main'] })];
    assert.equal(matchSite('main', sites).kind, 'ambiguous');
  });

  it('returns none for unrelated names', () => {
    assert.equal(matchSite('Pizza Palace', SITES).kind, 'none');
    assert.equal(matchSite('', SITES).kind, 'none');
    assert.equal(matchSite('anything', []).kind, 'none');
  });

  it('levenshtein distance', () => {
    assert.equal(levenshtein('kitten', 'sitting'), 3);
    assert.equal(levenshtein('', 'abc'), 3);
    assert.equal(levenshtein('same', 'same'), 0);
  });
});
