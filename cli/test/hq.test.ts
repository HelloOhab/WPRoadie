import { strict as assert } from 'node:assert';
import { spawn } from 'node:child_process';
import { createServer, type Server } from 'node:http';
import { join } from 'node:path';
import { after, before, describe, it } from 'node:test';
import { loadRegistry, saveRegistry, type Registry } from '../dist/config.js';
import { clearHqCache, fetchHqSites, HqError, mergeHqSites, normalizeHqUrl, type HqSite } from '../dist/hq.js';
import { getPassword } from '../dist/secrets.js';
import { CLI, tempDir } from './helpers.js';

const TOKEN = 'wrhq_testtoken0123456789';

const SMITH: HqSite = {
    id: '11111111-1111-4111-8111-111111111111',
    name: 'Smith Dental',
    client: 'Smith Dental Group',
    url: 'https://smithdental.example',
    mcp_endpoint: 'https://smithdental.example/wp-json/wproadie/mcp',
    environment: 'production',
    mode: 'production',
    disabled: false,
    wp_username: 'abdul',
    credential: { username: 'abdul', password: 'abcd efgh ijkl mnop qrst uvwx' },
};

const BAKERY: HqSite = {
    id: '22222222-2222-4222-8222-222222222222',
    name: 'Green Bakery',
    url: 'https://greenbakery.example/',
    mcp_endpoint: null,
    wp_username: 'abdul',
    credential: null,
};

const SITES: HqSite[] = [SMITH, BAKERY];

/** Runs the CLI without blocking this process, which serves the fake HQ. */
function run(args: string[], env: NodeJS.ProcessEnv): Promise<{ status: number | null; stdout: string; stderr: string }> {
  return new Promise((resolve) => {
    const child = spawn(process.execPath, [CLI, ...args], { env });
    let stdout = '';
    let stderr = '';
    child.stdout.on('data', (d) => (stdout += d));
    child.stderr.on('data', (d) => (stderr += d));
    child.on('close', (status) => resolve({ status, stdout, stderr }));
  });
}

let server: Server;
let hqUrl = '';
let requests = 0;
let wrapInObject = false;

before(async () => {
  server = createServer((req, res) => {
    requests++;
    if (req.url !== '/api/v1/cli/sites') {
      res.writeHead(404).end();
      return;
    }
    if (req.headers.authorization !== `Bearer ${TOKEN}`) {
      res.writeHead(401, { 'Content-Type': 'application/json' }).end('{"error":"unauthorized"}');
      return;
    }
    res.writeHead(200, { 'Content-Type': 'application/json' }).end(JSON.stringify(wrapInObject ? { sites: SITES } : SITES));
  });
  await new Promise<void>((resolve) => server.listen(0, '127.0.0.1', resolve));
  const address = server.address();
  hqUrl = `http://127.0.0.1:${typeof address === 'object' && address ? address.port : 0}`;
});

after(() => {
  server.close();
});

describe('HQ', () => {
  it('normalizes HQ URLs and refuses plain http except on localhost', () => {
    assert.equal(normalizeHqUrl('hq.rhythmco.com/'), 'https://hq.rhythmco.com');
    assert.equal(normalizeHqUrl('http://localhost:3000'), 'http://localhost:3000');
    assert.throws(() => normalizeHqUrl('http://hq.rhythmco.com'), HqError);
  });

  it('fetches the site list as an array or wrapped in {sites}', async () => {
    const config = { url: hqUrl, token: 'env' as const };
    assert.equal((await fetchHqSites(config, TOKEN)).length, 2);
    wrapInObject = true;
    try {
      assert.equal((await fetchHqSites(config, TOKEN)).length, 2);
    } finally {
      wrapInObject = false;
    }
    await assert.rejects(fetchHqSites(config, 'wrong'), /refused the token/);
  });

  it('merges HQ sites into the registry without losing local names', () => {
    const registry: Registry = {
      version: 1,
      sites: [
        { name: 'smithy', aliases: ['the dentist'], url: 'https://smithdental.example', endpoint: '', user: 'old', password: 'keychain' },
        { name: 'manual', aliases: [], url: 'https://manual.example', endpoint: '', user: 'x', password: 'command', passwordCommand: 'echo x' },
      ],
    };
    const first = mergeHqSites(registry, SITES);
    assert.deepEqual(first.added, ['green-bakery']);
    assert.deepEqual(first.updated, ['smithy']);
    assert.deepEqual(first.withoutCredential, ['green-bakery']);

    const smith = registry.sites.find((s) => s.name === 'smithy');
    assert.ok(smith);
    assert.equal(smith.hqSiteId, SMITH.id);
    assert.equal(smith.password, 'hq');
    assert.equal(smith.user, 'abdul');
    assert.equal(smith.endpoint, SMITH.mcp_endpoint);
    assert.ok(smith.aliases.includes('the dentist') && smith.aliases.includes('Smith Dental Group'));
    assert.equal(registry.sites.find((s) => s.name === 'green-bakery')?.url, 'https://greenbakery.example');

    // Pruning removes only HQ-synced sites HQ stopped listing.
    const pruned = mergeHqSites(registry, [SMITH], true);
    assert.deepEqual(pruned.removed, ['green-bakery']);
    assert.ok(registry.sites.some((s) => s.name === 'manual'));
  });

  it('login, sync and password lookup through the CLI, with the token from the environment', async () => {
    const dir = tempDir();
    const env = { ...process.env, WPROADIE_CONFIG: join(dir, 'sites.json'), WPROADIE_HQ_TOKEN: TOKEN };

    const login = await run(['hq', 'login', hqUrl], env);
    assert.equal(login.status, 0, login.stderr);
    assert.match(login.stdout, /2 site\(s\) available/);

    const sync = await run(['hq', 'sync'], env);
    assert.equal(sync.status, 0, sync.stderr);
    assert.match(sync.stdout, /2 added/);
    assert.match(sync.stdout, /No connection password in HQ yet for: green-bakery/);

    const registry = loadRegistry(env.WPROADIE_CONFIG);
    assert.deepEqual(registry.hq, { url: hqUrl, token: 'env' });
    // No password or token ever lands in the registry file.
    const { readFileSync } = await import('node:fs');
    const file = readFileSync(env.WPROADIE_CONFIG, 'utf8');
    assert.ok(!file.includes(TOKEN) && !file.includes('abcd efgh'));

    const status = await run(['hq', 'status'], env);
    assert.equal(status.status, 0, status.stderr);
    assert.match(status.stdout, /1 with your password/);

    // getPassword pulls from HQ, once per cache period.
    const previous = { config: process.env.WPROADIE_CONFIG, token: process.env.WPROADIE_HQ_TOKEN };
    process.env.WPROADIE_CONFIG = env.WPROADIE_CONFIG;
    process.env.WPROADIE_HQ_TOKEN = TOKEN;
    try {
      clearHqCache();
      const before = requests;
      const smith = registry.sites.find((s) => s.name === 'smith-dental');
      assert.ok(smith);
      const got = await getPassword(smith);
      assert.equal(got.from, 'hq');
      assert.equal(got.password, SMITH.credential?.password);
      await getPassword(smith);
      assert.equal(requests - before, 1, 'cached within the process');

      const bakery = registry.sites.find((s) => s.name === 'green-bakery');
      assert.ok(bakery);
      await assert.rejects(getPassword(bakery), /no connection password for green-bakery in HQ/i);
    } finally {
      process.env.WPROADIE_CONFIG = previous.config;
      if (previous.token === undefined) delete process.env.WPROADIE_HQ_TOKEN;
      else process.env.WPROADIE_HQ_TOKEN = previous.token;
    }

    const bad = await run(['hq', 'status'], { ...env, WPROADIE_HQ_TOKEN: 'nope' });
    assert.equal(bad.status, 1);
    assert.match(bad.stdout, /refused the token/);

    const logout = await run(['hq', 'logout'], env);
    assert.equal(logout.status, 0, logout.stderr);
    assert.equal(loadRegistry(env.WPROADIE_CONFIG).hq, undefined);
    saveRegistry(loadRegistry(env.WPROADIE_CONFIG), env.WPROADIE_CONFIG);
  });

  it('refuses login with a bad token and stores nothing', async () => {
    const dir = tempDir();
    const env = { ...process.env, WPROADIE_CONFIG: join(dir, 'sites.json'), WPROADIE_HQ_TOKEN: 'wrong' };
    const login = await run(['hq', 'login', hqUrl], env);
    assert.equal(login.status, 1);
    assert.match(login.stderr, /refused the token/);
    assert.equal(loadRegistry(env.WPROADIE_CONFIG).hq, undefined);
  });
});
