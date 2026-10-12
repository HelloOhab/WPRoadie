/**
 * Against a real WordPress site with WPRoadie (see helpers.ts for setup).
 */
import { strict as assert } from 'node:assert';
import { execFile } from 'node:child_process';
import { readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { before, describe, it } from 'node:test';
import { promisify } from 'node:util';
import { getPassword } from '../dist/secrets.js';
import { discover, endpointCandidates, endpointFromLinkHeader, listAllPrompts, listAllTools, SiteError } from '../dist/wp.js';
import { CLI, liveSite, SKIP_LIVE, tempDir, type LiveSite } from './helpers.js';

const run = promisify(execFile);
const live = await liveSite();

describe('endpoint discovery (live site)', { skip: live ? false : SKIP_LIVE }, () => {
  const site = live as LiveSite;

  it('lists the standard candidates in order', () => {
    assert.deepEqual(endpointCandidates('https://x.com/'), ['https://x.com/wp-json/wproadie/mcp', 'https://x.com/index.php?rest_route=/wproadie/mcp']);
  });

  it('falls back to ?rest_route= when pretty permalinks are off', async () => {
    const { connection, tried } = await discover(site.url, { user: 'admin', password: site.adminPassword }, 'live');
    try {
      assert.match(connection.endpoint, /rest_route=\/wproadie\/mcp$/);
      assert.equal(tried.length, 2, 'tried /wp-json first, then ?rest_route=');
      assert.equal(connection.protocolVersion, '2025-11-25');
      assert.equal(connection.serverName, 'WPRoadie');
      const tools = await listAllTools(connection.client);
      assert.ok(tools.some((t) => t.name === 'wproadie-site-overview'));
      const prompts = await listAllPrompts(connection.client);
      assert.ok(prompts.some((p) => p.name === 'wproadie-site-briefing'));
    } finally {
      await connection.close();
    }
  });

  it('finds the REST root from the Link header', async () => {
    const endpoint = await endpointFromLinkHeader(site.url);
    assert.match(endpoint ?? '', /rest_route=\/wproadie\/mcp/);
  });

  it('reports a wrong password as an authentication failure', async () => {
    await assert.rejects(
      discover(site.url, { user: 'admin', password: 'aaaa bbbb cccc dddd eeee ffff' }, 'live'),
      (err: unknown) => err instanceof SiteError && err.kind === 'auth' && /authentication failed for live/.test(err.message),
    );
  });

  it('reports a user without WPRoadie access as a clear 403', { skip: site?.bobPasswordFile ? false : 'no WPROADIE_TEST_BOB_PW_FILE' }, async () => {
    const password = readFileSync(site.bobPasswordFile as string, 'utf8').trim();
    await assert.rejects(
      discover(site.url, { user: 'bob', password }, 'live'),
      (err: unknown) => err instanceof SiteError && err.kind === 'forbidden' && err.status === 403 && /WPRoadie access/.test(err.message),
    );
  });

  it('reports an unreachable site as a network error', async () => {
    await assert.rejects(
      discover('http://127.0.0.1:59999', { user: 'admin', password: 'x' }, 'down'),
      (err: unknown) => err instanceof SiteError && err.kind === 'network',
    );
  });
});

describe('passwords', () => {
  const base = { name: 'smith-dental', aliases: [], url: 'https://x', endpoint: '', user: 'u' };

  it('the env var wins over everything', async () => {
    process.env.WPROADIE_PASSWORD_SMITH_DENTAL = 'from-env';
    try {
      const r = await getPassword({ ...base, password: 'command', passwordCommand: 'echo from-command' });
      assert.deepEqual(r, { password: 'from-env', from: 'env' });
    } finally {
      delete process.env.WPROADIE_PASSWORD_SMITH_DENTAL;
    }
  });

  it('runs the password command through the shell', async () => {
    const r = await getPassword({ ...base, password: 'command', passwordCommand: 'printf "%s\\n" "abcd efgh" | cat' });
    assert.deepEqual(r, { password: 'abcd efgh', from: 'command' });
  });

  it('explains a failing password command', async () => {
    await assert.rejects(getPassword({ ...base, password: 'command', passwordCommand: 'exit 3' }, { inheritStderr: false }), /exited with code 3/);
  });

  it('explains a missing password', async () => {
    await assert.rejects(getPassword({ ...base, password: 'none' }), /sites set-password smith-dental/);
  });
});

describe('CLI commands (live site)', { skip: live ? false : SKIP_LIVE }, () => {
  const site = live as LiveSite;
  let env: NodeJS.ProcessEnv;
  let registry: string;

  before(() => {
    registry = join(tempDir(), 'sites.json');
    env = { ...process.env, WPROADIE_CONFIG: registry };
  });

  it('sites add with --password-command discovers, verifies and stores only the command', async () => {
    const { stdout } = await run(
      process.execPath,
      [CLI, 'sites', 'add', 'Live Test', '--url', site.url, '--user', 'admin', '--password-command', `cat ${site.adminPasswordFile}`, '--alias', 'lt'],
      { env },
    );
    assert.match(stdout, /Connected to ".+" at .*rest_route=\/wproadie\/mcp/);
    assert.match(stdout, /\d+ tools, \d+ prompts/);
    const text = readFileSync(registry, 'utf8');
    assert.ok(!text.includes(site.adminPassword), 'password must not be in the registry');
    assert.equal(statSync(registry).mode & 0o777, 0o600);
    const saved = JSON.parse(text).sites[0];
    assert.equal(saved.name, 'live-test');
    assert.equal(saved.displayName, 'Live Test');
    assert.equal(saved.password, 'command');
  });

  it('sites list shows the password source, not the password', async () => {
    const { stdout } = await run(process.execPath, [CLI, 'sites', 'list'], { env });
    assert.match(stdout, /live-test\s+http/);
    assert.match(stdout, /command: cat /);
  });

  it('call accepts a tool name without the wproadie- prefix and prints JSON', async () => {
    const { stdout } = await run(process.execPath, [CLI, 'call', 'lt', 'site-overview'], { env });
    const result = JSON.parse(stdout);
    assert.notEqual(result.isError, true);
    assert.ok(result.structuredContent?.name || result.content?.[0]?.text);
  });

  it('sites add with a password from the env var (keychain not needed)', async () => {
    const { stdout } = await run(process.execPath, [CLI, 'sites', 'add', 'env-site', '--url', site.url, '--user', 'admin'], {
      env: { ...env, WPROADIE_PASSWORD_ENV_SITE: site.adminPassword },
    });
    assert.match(stdout, /Saved env-site \(password: env/);
    const { stdout: tested } = await run(process.execPath, [CLI, 'sites', 'test', 'env-site'], {
      env: { ...env, WPROADIE_PASSWORD_ENV_SITE: site.adminPassword },
    });
    assert.match(tested, /^OK: /);
  });

  it('typed passwords go to the keychain, or fail with a helpful message when there is none', async () => {
    const child = execFile(process.execPath, [CLI, 'sites', 'add', 'kc-site', '--url', site.url, '--user', 'admin', '--password-stdin'], { env });
    let stderr = '';
    child.stderr?.on('data', (d) => (stderr += d));
    child.stdin?.end(site.adminPassword + '\n');
    const code: number = await new Promise((resolve) => child.on('close', (c) => resolve(c ?? 1)));
    const reg = JSON.parse(readFileSync(registry, 'utf8'));
    const saved = reg.sites.find((s: { name: string }) => s.name === 'kc-site');
    if (code === 0) {
      assert.equal(saved.password, 'keychain');
      await run(process.execPath, [CLI, 'sites', 'remove', 'kc-site'], { env });
    } else {
      assert.match(stderr, /keychain is not available.*--password-command.*WPROADIE_PASSWORD_/s);
      assert.equal(saved, undefined, 'not saved when the keychain is unavailable');
    }
  });

  it('a user without WPRoadie access gets a clear error and exit code 1', { skip: site?.bobPasswordFile ? false : 'no bob' }, async () => {
    await assert.rejects(
      run(process.execPath, [CLI, 'sites', 'add', 'bob', '--url', site.url, '--user', 'bob', '--password-command', `cat ${site.bobPasswordFile}`], { env }),
      (err: { code?: number; stderr?: string }) => err.code === 1 && /access denied for bob/.test(err.stderr ?? ''),
    );
  });

  it('sites remove', async () => {
    const { stdout } = await run(process.execPath, [CLI, 'sites', 'remove', 'env-site'], { env });
    assert.match(stdout, /Removed env-site/);
  });
});
