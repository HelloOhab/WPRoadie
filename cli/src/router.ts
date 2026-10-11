/**
 * `wproadie mcp`: one stdio MCP server that routes to whichever WordPress
 * site the person names. It always offers list_sites, use_site and
 * current_site; after use_site it also offers that site's own tools and
 * prompts, forwarding calls over HTTP.
 */
import { Server } from '@modelcontextprotocol/sdk/server/index.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import {
  CallToolRequestSchema,
  ErrorCode,
  GetPromptRequestSchema,
  ListPromptsRequestSchema,
  ListToolsRequestSchema,
  McpError,
  type CallToolResult,
  type Prompt,
  type Tool,
} from '@modelcontextprotocol/sdk/types.js';
import { configPath, loadRegistry, saveRegistry, type Site } from './config.js';
import { matchSite } from './match.js';
import { getPassword, SecretError } from './secrets.js';
import { VERSION } from './version.js';
import { discover, explainError, listAllPrompts, listAllTools, resolveToolName, SiteError, type SiteConnection } from './wp.js';

export type Logger = (message: string) => void;

export const stderrLogger: Logger = (message) => {
  process.stderr.write(`[wproadie] ${message}\n`);
};

interface Connected {
  site: Site;
  conn: SiteConnection;
  tools: Tool[];
  prompts: Prompt[];
}

const ROUTING_TOOLS: Tool[] = [
  {
    name: 'list_sites',
    title: 'List WordPress sites',
    description:
      'Lists the WordPress sites you can work on (name, display name, URL, aliases) and which one is active. ' +
      'Use it when the person mentions a site and you are not sure of its name, or to show the options.',
    inputSchema: {
      type: 'object',
      properties: {
        filter: { type: 'string', description: 'Optional text to narrow the list (matches names, aliases and URLs).' },
      },
    },
    annotations: { readOnlyHint: true, idempotentHint: true, openWorldHint: false },
  },
  {
    name: 'use_site',
    title: 'Switch to a WordPress site',
    description:
      'Selects the WordPress site to work on, e.g. "Smith Dental", "smith-dental" or "smithdental.com". ' +
      "After it succeeds, that site's tools (named wproadie-*) and prompts become available and act on that site only. " +
      'If the name is ambiguous it returns the candidates instead of choosing: ask the person which one they mean.',
    inputSchema: {
      type: 'object',
      properties: {
        site: { type: 'string', description: 'Site name, alias, display name or URL/hostname.' },
      },
      required: ['site'],
    },
    annotations: { readOnlyHint: true, idempotentHint: true, openWorldHint: true },
  },
  {
    name: 'current_site',
    title: 'Current WordPress site',
    description: 'Shows which WordPress site is active (the one the wproadie-* tools act on), or that none is selected yet.',
    inputSchema: { type: 'object', properties: {} },
    annotations: { readOnlyHint: true, idempotentHint: true, openWorldHint: false },
  },
];

const INSTRUCTIONS =
  'WPRoadie gives you access to many client WordPress sites through one connection. ' +
  'Call list_sites to see them and use_site to pick one by the name the person uses (for example "Smith Dental"). ' +
  "Then that site's wproadie-* tools appear and act on that site only. " +
  'Before changing anything, say which site you are working on; call current_site if unsure. ' +
  'Switch sites with use_site again.';

function text(message: string, isError = false): CallToolResult {
  return { content: [{ type: 'text', text: message }], ...(isError ? { isError: true } : {}) };
}

function siteLabel(site: Site): string {
  return site.displayName ? `${site.displayName} (${site.name})` : site.name;
}

function siteSummary(site: Site) {
  return {
    name: site.name,
    ...(site.displayName ? { displayName: site.displayName } : {}),
    url: site.url,
    aliases: site.aliases,
  };
}

export interface RouterOptions {
  registryPath?: string;
  log?: Logger;
}

export class Router {
  readonly server: Server;
  private readonly registryPath: string;
  private readonly log: Logger;
  private readonly connections = new Map<string, Connected>();
  private readonly pending = new Map<string, Promise<Connected>>();
  private activeName: string | undefined;

  constructor(opts: RouterOptions = {}) {
    this.registryPath = opts.registryPath ?? configPath();
    this.log = opts.log ?? stderrLogger;
    this.server = new Server(
      { name: 'wproadie', title: 'WPRoadie', version: VERSION },
      {
        capabilities: { tools: { listChanged: true }, prompts: { listChanged: true } },
        instructions: INSTRUCTIONS,
      },
    );
    this.registerHandlers();
  }

  /** Re-read on demand so sites added while the server runs show up. */
  private sites(): Site[] {
    return loadRegistry(this.registryPath).sites;
  }

  get active(): Connected | undefined {
    return this.activeName ? this.connections.get(this.activeName) : undefined;
  }

  private registerHandlers(): void {
    this.server.setRequestHandler(ListToolsRequestSchema, () => {
      const active = this.active;
      const siteTools = active
        ? active.tools.map((t) => ({
            ...t,
            description: `[${active.site.displayName ?? active.site.name}] ${t.description ?? ''}`.trim(),
          }))
        : [];
      return { tools: [...ROUTING_TOOLS, ...siteTools] };
    });

    this.server.setRequestHandler(CallToolRequestSchema, async (request) => {
      const { name } = request.params;
      const args = (request.params.arguments ?? {}) as Record<string, unknown>;
      try {
        switch (name) {
          case 'list_sites':
            return this.listSites(typeof args.filter === 'string' ? args.filter : undefined);
          case 'use_site':
            return await this.useSite(typeof args.site === 'string' ? args.site : '');
          case 'current_site':
            return this.currentSite();
          default:
            return await this.forwardTool(name, args);
        }
      } catch (err) {
        const message = err instanceof Error ? err.message : String(err);
        this.log(`tool ${name} failed: ${message}`);
        return text(message, true);
      }
    });

    this.server.setRequestHandler(ListPromptsRequestSchema, () => {
      return { prompts: this.active?.prompts ?? [] };
    });

    this.server.setRequestHandler(GetPromptRequestSchema, async (request) => {
      const active = this.active;
      if (!active) {
        throw new McpError(ErrorCode.InvalidRequest, 'No WordPress site selected. Call the use_site tool first.');
      }
      try {
        return await this.withSession(active.site.name, (c) =>
          c.conn.client.getPrompt({ name: request.params.name, arguments: request.params.arguments }),
        );
      } catch (err) {
        if (err instanceof McpError) throw err;
        throw new McpError(ErrorCode.InternalError, (err as Error).message);
      }
    });
  }

  listSites(filter?: string): CallToolResult {
    let sites = this.sites();
    if (sites.length === 0) {
      return text('No sites are registered yet. The person can add one with: wproadie sites add <name> --url <url> --user <login>');
    }
    if (filter && filter.trim()) {
      const m = matchSite(filter, sites);
      const f = filter.toLowerCase();
      sites = sites.filter(
        (s) =>
          (m.kind === 'match' && m.site.name === s.name) ||
          (m.kind === 'ambiguous' && m.candidates.some((c) => c.name === s.name)) ||
          [s.name, s.displayName ?? '', s.url, ...s.aliases].some((k) => k.toLowerCase().includes(f)),
      );
    }
    const list = sites.map((s) => ({ ...siteSummary(s), active: s.name === this.activeName }));
    return {
      content: [{ type: 'text', text: JSON.stringify({ count: list.length, active: this.activeName ?? null, sites: list }, null, 2) }],
    };
  }

  currentSite(): CallToolResult {
    const active = this.active;
    if (!active) {
      return text('No site selected yet. Call use_site with the site the person wants to work on.');
    }
    return text(
      JSON.stringify(
        {
          ...siteSummary(active.site),
          endpoint: active.conn.endpoint,
          server: `${active.conn.serverName} ${active.conn.serverVersion}`.trim(),
          tools: active.tools.map((t) => t.name),
          prompts: active.prompts.map((p) => p.name),
        },
        null,
        2,
      ),
    );
  }

  /** Resolves a name to a site and makes it active. Exposed for --site. */
  async select(query: string): Promise<{ result: CallToolResult; changed: boolean }> {
    const sites = this.sites();
    const m = matchSite(query, sites);
    if (m.kind === 'ambiguous') {
      return {
        changed: false,
        result: text(
          `"${query}" matches more than one site. Ask the person which one they mean, then call use_site with its name:\n` +
            JSON.stringify(m.candidates.map(siteSummary), null, 2),
        ),
      };
    }
    if (m.kind === 'none') {
      const hint = m.suggestions.length
        ? ` Closest: ${m.suggestions.map((s) => s.name).join(', ')}.`
        : sites.length
          ? ` Known sites: ${sites.map((s) => s.name).join(', ')}.`
          : ' No sites are registered yet.';
      return { changed: false, result: text(`No site matches "${query}".${hint} Call list_sites to see them all.`, true) };
    }

    const site = m.site;
    // Refresh the tool list each time, so changes on the site (tool switches, mode) show up.
    const connected = await this.connect(site, true);
    const changed = this.activeName !== site.name;
    this.activeName = site.name;
    this.log(`active site: ${site.name} (${connected.tools.length} tools, ${connected.prompts.length} prompts)`);

    const lines = [
      `Now working on ${siteLabel(site)} — ${site.url}`,
      `Server: ${connected.conn.serverName} ${connected.conn.serverVersion}`.trim(),
    ];
    if (connected.conn.instructions) lines.push(`Site instructions: ${connected.conn.instructions}`);
    lines.push(`Tools (${connected.tools.length}): ${connected.tools.map((t) => t.name).join(', ') || 'none'}`);
    if (connected.prompts.length) lines.push(`Prompts: ${connected.prompts.map((p) => p.name).join(', ')}`);
    return { changed, result: text(lines.join('\n')) };
  }

  private async useSite(query: string): Promise<CallToolResult> {
    if (!query.trim()) {
      return text('use_site needs a "site" argument, e.g. {"site": "Smith Dental"}. Call list_sites to see the options.', true);
    }
    const { result } = await this.select(query);
    if (!result.isError && this.active) {
      await this.notifyListsChanged();
    }
    return result;
  }

  private async notifyListsChanged(): Promise<void> {
    if (!this.server.transport) return;
    try {
      await this.server.sendToolListChanged();
      await this.server.sendPromptListChanged();
    } catch (err) {
      this.log(`could not send list_changed: ${(err as Error).message}`);
    }
  }

  private async forwardTool(name: string, args: Record<string, unknown>): Promise<CallToolResult> {
    const active = this.active;
    if (!active) {
      return text(
        `No WordPress site selected, so "${name}" cannot run. Call use_site first (list_sites shows the options), then retry.`,
        true,
      );
    }
    const toolName = resolveToolName(name, active.tools);
    return (await this.withSession(active.site.name, (c) =>
      c.conn.client.callTool({ name: toolName, arguments: args }),
    )) as CallToolResult;
  }

  /**
   * Runs a call on a site's cached connection. If the site dropped the MCP
   * session (expired, or evicted by the adapter's per-user cap), reconnects
   * once and retries.
   */
  private async withSession<T>(siteName: string, fn: (c: Connected) => Promise<T>): Promise<T> {
    let connected = this.connections.get(siteName);
    if (!connected) {
      const site = this.sites().find((s) => s.name === siteName);
      if (!site) throw new Error(`Site ${siteName} is no longer in the registry.`);
      connected = await this.connect(site, false);
    }
    try {
      return await fn(connected);
    } catch (err) {
      const e = explainError(err, siteName, connected.site.user, connected.conn.endpoint);
      if (e.kind !== 'session') throw e;
      this.log(`${siteName}: session expired, reconnecting`);
      await this.drop(siteName);
      const fresh = await this.connect(connected.site, false);
      try {
        return await fn(fresh);
      } catch (err2) {
        throw explainError(err2, siteName, fresh.site.user, fresh.conn.endpoint);
      }
    }
  }

  private async drop(siteName: string): Promise<void> {
    const c = this.connections.get(siteName);
    this.connections.delete(siteName);
    if (c) await c.conn.close().catch(() => {});
  }

  /** Lazily connects to a site, caching the connection. */
  private async connect(site: Site, refresh: boolean): Promise<Connected> {
    const existing = this.connections.get(site.name);
    if (existing && !refresh) return existing;
    if (existing && refresh) {
      try {
        existing.tools = await listAllTools(existing.conn.client);
        existing.prompts = await listAllPrompts(existing.conn.client);
        existing.site = site;
        return existing;
      } catch (err) {
        const e = explainError(err, site.name, site.user, existing.conn.endpoint);
        if (e.kind !== 'session') throw e;
        await this.drop(site.name);
      }
    }
    const inFlight = this.pending.get(site.name);
    if (inFlight) return inFlight;
    const p = this.openConnection(site).finally(() => this.pending.delete(site.name));
    this.pending.set(site.name, p);
    return p;
  }

  private async openConnection(site: Site): Promise<Connected> {
    let password: string;
    try {
      ({ password } = await getPassword(site));
    } catch (err) {
      if (err instanceof SecretError) throw new SiteError('password', err.message);
      throw err;
    }
    this.log(`connecting to ${site.name} (${site.url})`);
    const { connection } = await discover(site.url, { user: site.user, password }, site.name, site.endpoint || undefined);
    if (connection.endpoint !== site.endpoint) {
      this.rememberEndpoint(site.name, connection.endpoint);
      site = { ...site, endpoint: connection.endpoint };
    }
    try {
      const tools = await listAllTools(connection.client);
      const prompts = await listAllPrompts(connection.client);
      const connected: Connected = { site, conn: connection, tools, prompts };
      this.connections.set(site.name, connected);
      return connected;
    } catch (err) {
      await connection.close();
      throw explainError(err, site.name, site.user, connection.endpoint);
    }
  }

  private rememberEndpoint(siteName: string, endpoint: string): void {
    try {
      const reg = loadRegistry(this.registryPath);
      const s = reg.sites.find((x) => x.name === siteName);
      if (s) {
        s.endpoint = endpoint;
        saveRegistry(reg, this.registryPath);
      }
    } catch (err) {
      this.log(`could not save the endpoint for ${siteName}: ${(err as Error).message}`);
    }
  }

  async closeAll(): Promise<void> {
    const names = [...this.connections.keys()];
    await Promise.all(names.map((n) => this.drop(n)));
  }
}

/** Entry point for `wproadie mcp`. */
export async function runMcpServer(opts: { site?: string; registryPath?: string }): Promise<void> {
  const router = new Router({ registryPath: opts.registryPath });
  const log = stderrLogger;

  if (opts.site) {
    try {
      const { result } = await router.select(opts.site);
      const first = result.content[0];
      const msg = first && first.type === 'text' ? first.text : '';
      if (result.isError || !router.active) log(`--site ${opts.site}: ${msg}`);
    } catch (err) {
      log(`--site ${opts.site}: ${(err as Error).message} (starting with no site selected)`);
    }
  }

  const transport = new StdioServerTransport();
  let closing = false;
  const shutdown = async (code = 0) => {
    if (closing) return;
    closing = true;
    await router.closeAll().catch(() => {});
    process.exit(code);
  };
  router.server.onclose = () => void shutdown(0);
  process.stdin.on('end', () => void shutdown(0));
  process.on('SIGINT', () => void shutdown(0));
  process.on('SIGTERM', () => void shutdown(0));

  await router.server.connect(transport);
  log(`MCP server ready (registry: ${opts.registryPath ?? configPath()}${router.active ? `, site: ${router.active.site.name}` : ''})`);
}
