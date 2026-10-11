/**
 * Drives `wproadie mcp` over stdio with the official SDK client, the way
 * Claude Code or Claude Desktop would.
 */
import { strict as assert } from 'node:assert';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { after, before, describe, it } from 'node:test';
import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { StdioClientTransport } from '@modelcontextprotocol/sdk/client/stdio.js';
import { ToolListChangedNotificationSchema, PromptListChangedNotificationSchema, type CallToolResult } from '@modelcontextprotocol/sdk/types.js';
import { saveRegistry, type Site } from '../dist/config.js';
import { CLI, liveSite, SKIP_LIVE, tempDir, type LiveSite } from './helpers.js';

const live = await liveSite();

function firstText(result: unknown): string {
  const content = (result as CallToolResult).content;
  const first = content[0];
  return first && first.type === 'text' ? first.text : '';
}

function waitFor(predicate: () => boolean, ms = 5000): Promise<void> {
  return new Promise((resolve, reject) => {
    const start = Date.now();
    const tick = () => {
      if (predicate()) return resolve();
      if (Date.now() - start > ms) return reject(new Error('timed out waiting'));
      setTimeout(tick, 20);
    };
    tick();
  });
}

function writeRegistry(site: LiveSite): string {
  const path = join(tempDir(), 'sites.json');
  const base = { url: site.url, endpoint: '', user: 'admin', password: 'command' as const, passwordCommand: `cat ${site.adminPasswordFile}` };
  const sites: Site[] = [
    { ...base, name: 'smith-dental', displayName: 'Smith Dental', aliases: ['sd'] },
    { ...base, name: 'smith-law', displayName: 'Smith Law Group', aliases: [] },
  ];
  if (site.bobPasswordFile) {
    sites.push({ ...base, name: 'no-access', aliases: [], user: 'bob', passwordCommand: `cat ${site.bobPasswordFile}` });
  }
  saveRegistry({ version: 1, sites }, path);
  return path;
}

async function startClient(registry: string, extraArgs: string[] = []) {
  const stderr: string[] = [];
  const transport = new StdioClientTransport({
    command: process.execPath,
    args: [CLI, 'mcp', ...extraArgs],
    env: { PATH: process.env.PATH ?? '', HOME: process.env.HOME ?? '', WPROADIE_CONFIG: registry },
    stderr: 'pipe',
  });
  transport.stderr?.on('data', (d: Buffer) => stderr.push(d.toString()));
  const client = new Client({ name: 'wproadie-test', version: '1.0.0' });
  const events = { tools: 0, prompts: 0 };
  client.setNotificationHandler(ToolListChangedNotificationSchema, () => {
    events.tools++;
  });
  client.setNotificationHandler(PromptListChangedNotificationSchema, () => {
    events.prompts++;
  });
  await client.connect(transport);
  return { client, events, stderr };
}

describe('wproadie mcp over stdio (live site)', { skip: live ? false : SKIP_LIVE }, () => {
  let registry: string;
  let session: Awaited<ReturnType<typeof startClient>>;

  before(async () => {
    registry = writeRegistry(live as LiveSite);
    session = await startClient(registry);
  });

  after(async () => {
    await session?.client.close();
  });

  it('declares tools and prompts listChanged', () => {
    const caps = session.client.getServerCapabilities();
    assert.equal(caps?.tools?.listChanged, true);
    assert.equal(caps?.prompts?.listChanged, true);
    assert.match(session.client.getInstructions() ?? '', /use_site/);
  });

  it('starts with only the 3 routing tools and no prompts', async () => {
    const { tools } = await session.client.listTools();
    assert.deepEqual(tools.map((t) => t.name).sort(), ['current_site', 'list_sites', 'use_site']);
    const { prompts } = await session.client.listPrompts();
    assert.equal(prompts.length, 0);
  });

  it('list_sites returns names, URLs and aliases', async () => {
    const result = await session.client.callTool({ name: 'list_sites', arguments: {} });
    const data = JSON.parse(firstText(result));
    assert.ok(data.sites.some((s: { name: string; aliases: string[] }) => s.name === 'smith-dental' && s.aliases.includes('sd')));
  });

  it('a site tool before use_site is an error that says to call use_site', async () => {
    const result = await session.client.callTool({ name: 'wproadie-site-overview', arguments: {} });
    assert.equal(result.isError, true);
    assert.match(firstText(result), /use_site first/);
  });

  it('use_site with an ambiguous name returns the candidates and selects nothing', async () => {
    const result = await session.client.callTool({ name: 'use_site', arguments: { site: 'smith' } });
    assert.match(firstText(result), /more than one site/);
    assert.match(firstText(result), /smith-dental/);
    assert.match(firstText(result), /smith-law/);
    const current = await session.client.callTool({ name: 'current_site', arguments: {} });
    assert.match(firstText(current), /No site selected/);
  });

  it('use_site "Smith Dental" connects and sends list_changed', async () => {
    const before = { ...session.events };
    const result = await session.client.callTool({ name: 'use_site', arguments: { site: 'Smith Dental' } });
    assert.notEqual(result.isError, true, firstText(result));
    assert.match(firstText(result), /Now working on Smith Dental \(smith-dental\)/);
    await waitFor(() => session.events.tools > before.tools && session.events.prompts > before.prompts);
  });

  it('then lists the site tools alongside the routing tools', async () => {
    const { tools } = await session.client.listTools();
    const names = tools.map((t) => t.name);
    for (const n of ['list_sites', 'use_site', 'current_site', 'wproadie-site-overview']) assert.ok(names.includes(n), n);
    assert.ok(names.length > 3);
    const overview = tools.find((t) => t.name === 'wproadie-site-overview');
    assert.match(overview?.description ?? '', /^\[Smith Dental\]/);
  });

  it('forwards a site tool call and passes the result through', async () => {
    const result = await session.client.callTool({ name: 'wproadie-site-overview', arguments: {} });
    assert.notEqual(result.isError, true, firstText(result));
    const overview = (result.structuredContent ?? JSON.parse(firstText(result))) as { name?: string; wproadie_mode?: string };
    assert.ok(overview.name);
    assert.ok(overview.wproadie_mode);
  });

  it('proxies prompts', async () => {
    const { prompts } = await session.client.listPrompts();
    const names = prompts.map((p) => p.name);
    assert.ok(names.includes('wproadie-site-briefing'), names.join(','));
    const prompt = await session.client.getPrompt({ name: 'wproadie-site-briefing', arguments: {} });
    assert.ok(prompt.messages.length > 0);
    const first = prompt.messages[0];
    assert.equal(first?.role, 'user');
  });

  it('current_site reports the active site', async () => {
    const result = await session.client.callTool({ name: 'current_site', arguments: {} });
    const data = JSON.parse(firstText(result));
    assert.equal(data.name, 'smith-dental');
    assert.match(data.endpoint, /rest_route=\/wproadie\/mcp/);
  });

  it('switches sites by hostname-free alias and keeps working', async () => {
    const result = await session.client.callTool({ name: 'use_site', arguments: { site: 'smith law group' } });
    assert.match(firstText(result), /Now working on Smith Law Group/);
    const call = await session.client.callTool({ name: 'site-overview', arguments: {} });
    assert.notEqual(call.isError, true, firstText(call));
  });

  it('a user without WPRoadie access gets a clear tool error', { skip: live?.bobPasswordFile ? false : 'no bob' }, async () => {
    const result = await session.client.callTool({ name: 'use_site', arguments: { site: 'no-access' } });
    assert.equal(result.isError, true);
    assert.match(firstText(result), /access denied for no-access: user "bob"/);
    // The previous site stays active.
    const current = JSON.parse(firstText(await session.client.callTool({ name: 'current_site', arguments: {} })));
    assert.equal(current.name, 'smith-law');
  });

  it('unknown site names get a helpful error', async () => {
    const result = await session.client.callTool({ name: 'use_site', arguments: { site: 'Pizza Palace' } });
    assert.equal(result.isError, true);
    assert.match(firstText(result), /No site matches/);
  });

  it('logs only to stderr', () => {
    assert.match(session.stderr.join(''), /\[wproadie\] MCP server ready/);
  });
});

describe('wproadie mcp --site (live site)', { skip: live ? false : SKIP_LIVE }, () => {
  it('starts with the site preselected', async () => {
    const registry = writeRegistry(live as LiveSite);
    const { client } = await startClient(registry, ['--site', 'sd']);
    try {
      const { tools } = await client.listTools();
      assert.ok(tools.some((t) => t.name === 'wproadie-site-overview'));
      const saved = JSON.parse(readFileSync(registry, 'utf8'));
      assert.match(saved.sites.find((s: Site) => s.name === 'smith-dental').endpoint, /rest_route=/, 'discovered endpoint remembered');
    } finally {
      await client.close();
    }
  });
});

describe('wproadie mcp with no sites', () => {
  it('still starts and explains how to add one', async () => {
    const registry = join(tempDir(), 'sites.json');
    const { client } = await startClient(registry);
    try {
      const { tools } = await client.listTools();
      assert.equal(tools.length, 3);
      const result = await client.callTool({ name: 'list_sites', arguments: {} });
      assert.match(firstText(result), /wproadie sites add/);
    } finally {
      await client.close();
    }
  });
});
