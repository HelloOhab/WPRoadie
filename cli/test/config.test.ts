import { strict as assert } from 'node:assert';
import { existsSync, readFileSync, statSync, writeFileSync } from 'node:fs';
import { homedir } from 'node:os';
import { join } from 'node:path';
import { describe, it } from 'node:test';
import {
  configPath,
  loadRegistry,
  normalizeUrl,
  passwordEnvVar,
  saveRegistry,
  slugify,
  type Registry,
} from '../dist/config.js';
import { parseCsv, parseImport, rowToSite } from '../dist/importer.js';
import { tempDir } from './helpers.js';

describe('registry', () => {
  it('resolves its path from WPROADIE_CONFIG, then XDG_CONFIG_HOME, then ~/.config', () => {
    assert.equal(configPath({ WPROADIE_CONFIG: '/x/sites.json' }), '/x/sites.json');
    assert.equal(configPath({ XDG_CONFIG_HOME: '/xdg' }), '/xdg/wproadie/sites.json');
    assert.equal(configPath({}), join(homedir(), '.config', 'wproadie', 'sites.json'));
  });

  it('reads an empty registry when the file does not exist', () => {
    const path = join(tempDir(), 'none', 'sites.json');
    assert.deepEqual(loadRegistry(path), { version: 1, sites: [] });
  });

  it('writes with mode 0600 in a 0700 directory and reads back', () => {
    const path = join(tempDir(), 'nested', 'wproadie', 'sites.json');
    const reg: Registry = {
      version: 1,
      sites: [
        {
          name: 'smith-dental',
          displayName: 'Smith Dental',
          aliases: ['sd'],
          url: 'https://smithdental.com',
          endpoint: 'https://smithdental.com/wp-json/wproadie/mcp',
          user: 'rhythm',
          password: 'command',
          passwordCommand: 'op read "op://Rhythm/Smith Dental/wproadie"',
        },
      ],
    };
    saveRegistry(reg, path);
    assert.equal(statSync(path).mode & 0o777, 0o600);
    assert.equal(statSync(join(path, '..')).mode & 0o777, 0o700);
    assert.deepEqual(loadRegistry(path), reg);

    // Re-saving an existing, looser file tightens it again.
    writeFileSync(path, readFileSync(path), { mode: 0o644 });
    saveRegistry(loadRegistry(path), path);
    assert.equal(statSync(path).mode & 0o777, 0o600);
    assert.equal(existsSync(`${path}.${process.pid}.tmp`), false);
  });

  it('never writes unknown fields such as a stray password', () => {
    const path = join(tempDir(), 'sites.json');
    const reg = {
      version: 1 as const,
      sites: [{ name: 'a', aliases: [], url: 'https://a.com', endpoint: '', user: 'u', password: 'none' as const, secret: 'hunter2', appPassword: 'x' }],
    };
    saveRegistry(reg, path);
    const text = readFileSync(path, 'utf8');
    assert.ok(!text.includes('hunter2'));
    assert.ok(!text.includes('appPassword'));
  });

  it('rejects a corrupt registry with a clear error', () => {
    const path = join(tempDir(), 'sites.json');
    writeFileSync(path, '{not json');
    assert.throws(() => loadRegistry(path), /Could not read the site registry/);
  });
});

describe('helpers', () => {
  it('slugify', () => {
    assert.equal(slugify('Smith Dental'), 'smith-dental');
    assert.equal(slugify('  Café Olé!! '), 'cafe-ole');
  });
  it('normalizeUrl', () => {
    assert.equal(normalizeUrl('smithdental.com/'), 'https://smithdental.com');
    assert.equal(normalizeUrl('http://127.0.0.1:8899//'), 'http://127.0.0.1:8899');
    assert.equal(normalizeUrl('https://x.com/blog/?a=1#b'), 'https://x.com/blog');
  });
  it('passwordEnvVar', () => {
    assert.equal(passwordEnvVar('smith-dental'), 'WPROADIE_PASSWORD_SMITH_DENTAL');
    assert.equal(passwordEnvVar('a.b-c'), 'WPROADIE_PASSWORD_A_B_C');
  });
});

describe('import', () => {
  it('parses CSV with quoted password commands', () => {
    const csv =
      'name,url,user,password_command,display_name,aliases\n' +
      'smith-dental,https://smithdental.com,rhythm,"op read ""op://Rhythm/Smith Dental/wproadie""",Smith Dental,smith;sd\n' +
      '# a comment line\n' +
      'Jones Bakery,jonesbakery.co.uk,rhythm,,,\n';
    const rows = parseImport(csv, 'csv');
    assert.equal(rows.length, 2);
    assert.equal(rows[0]?.passwordCommand, 'op read "op://Rhythm/Smith Dental/wproadie"');
    assert.deepEqual(rows[0]?.aliases, ['smith', 'sd']);
    const jones = rowToSite(rows[1]!);
    assert.equal(jones.name, 'jones-bakery');
    assert.equal(jones.displayName, 'Jones Bakery');
    assert.equal(jones.url, 'https://jonesbakery.co.uk');
    assert.equal(jones.password, 'none');
  });

  it('parses JSON (array or {sites})', () => {
    const rows = parseImport(JSON.stringify({ sites: [{ name: 'a', url: 'https://a.com', user: 'u', passwordCommand: 'pass a' }] }), 'json');
    assert.equal(rowToSite(rows[0]!).password, 'command');
  });

  it('refuses files that contain passwords, and rows missing fields', () => {
    assert.throws(() => parseImport('name,url,user,password\na,https://a.com,u,secret\n', 'csv'), /Do not put passwords/);
    assert.throws(() => parseImport('[{"name":"a","url":"https://a.com"}]', 'json'), /missing user/);
  });

  it('CSV parser handles CRLF and embedded commas', () => {
    assert.deepEqual(parseCsv('a,"b,c"\r\n1,2\r\n'), [
      ['a', 'b,c'],
      ['1', '2'],
    ]);
  });
});
