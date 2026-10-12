/**
 * Talking to one WordPress site's WPRoadie MCP server over Streamable HTTP.
 */
import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { StreamableHTTPClientTransport, StreamableHTTPError } from '@modelcontextprotocol/sdk/client/streamableHttp.js';
import { McpError, type Prompt, type Tool } from '@modelcontextprotocol/sdk/types.js';
import type { Site } from './config.js';
import { VERSION } from './version.js';

export type SiteErrorKind = 'auth' | 'forbidden' | 'not_found' | 'not_mcp' | 'network' | 'session' | 'server' | 'password';

export class SiteError extends Error {
  constructor(
    public readonly kind: SiteErrorKind,
    message: string,
    public readonly status?: number,
  ) {
    super(message);
  }
}

export interface Credentials {
  user: string;
  password: string;
}

export interface SiteConnection {
  endpoint: string;
  client: Client;
  transport: StreamableHTTPClientTransport;
  serverName: string;
  serverVersion: string;
  instructions?: string;
  protocolVersion?: string;
  close(): Promise<void>;
}

export function basicAuth({ user, password }: Credentials): string {
  // WordPress ignores the spaces in "abcd efgh ijkl ..." Application Passwords.
  const token = Buffer.from(`${user}:${password.replace(/\s+/g, '')}`, 'utf8').toString('base64');
  return `Basic ${token}`;
}

/** The two standard places the endpoint lives: pretty permalinks first, then ?rest_route=. */
export function endpointCandidates(siteUrl: string): string[] {
  const base = siteUrl.replace(/\/+$/, '');
  return [`${base}/wp-json/wproadie/mcp`, `${base}/index.php?rest_route=/wproadie/mcp`];
}

/**
 * Builds the endpoint from the REST API root WordPress advertises in its
 * `Link: <...>; rel="https://api.w.org/"` header. Catches sites with a
 * custom REST prefix.
 */
export async function endpointFromLinkHeader(siteUrl: string): Promise<string | undefined> {
  try {
    const res = await fetch(siteUrl, { method: 'HEAD', redirect: 'follow', signal: AbortSignal.timeout(15_000) });
    const link = res.headers.get('link') ?? '';
    const m = /<([^>]+)>\s*;\s*rel="https:\/\/api\.w\.org\/"/.exec(link);
    if (!m || !m[1]) return undefined;
    const root = m[1];
    if (root.includes('rest_route=')) {
      return root.replace(/rest_route=[^&]*/, 'rest_route=/wproadie/mcp');
    }
    return `${root.replace(/\/+$/, '')}/wproadie/mcp`;
  } catch {
    return undefined;
  }
}

/** Pulls WordPress's JSON error ({code, message}) out of an SDK error message. */
function wpErrorBody(message: string): { code?: string; message?: string; jsonrpc?: { code?: number; message?: string } } {
  const start = message.indexOf('{');
  if (start === -1) return {};
  try {
    const body = JSON.parse(message.slice(start)) as Record<string, unknown>;
    const error = body.error as { code?: number; message?: string } | undefined;
    return {
      code: typeof body.code === 'string' ? body.code : undefined,
      message: typeof body.message === 'string' ? body.message : undefined,
      jsonrpc: error,
    };
  } catch {
    return {};
  }
}

function causeText(err: unknown): string {
  const e = err as { message?: string; cause?: { code?: string; message?: string } };
  const cause = e?.cause;
  if (cause && (cause.code || cause.message)) {
    return `${cause.code ?? ''} ${cause.message ?? ''}`.trim();
  }
  return e?.message ?? String(err);
}

/** Turns any failure talking to a site into a SiteError with a message a person (or the AI) can act on. */
export function explainError(err: unknown, siteName: string, user: string, endpoint: string): SiteError {
  if (err instanceof SiteError) return err;

  if (err instanceof StreamableHTTPError) {
    const status = err.code ?? 0;
    const body = wpErrorBody(err.message);
    const said = body.message ?? body.jsonrpc?.message;
    const wpSaid = said ? ` (WordPress said: ${said})` : '';
    if (status === 401) {
      return new SiteError(
        'auth',
        `authentication failed for ${siteName}: check the Application Password for user "${user}" and that the user has WPRoadie access${wpSaid}. ` +
          'Note: WordPress only accepts Application Passwords over HTTPS unless the site is a local environment.',
        401,
      );
    }
    if (status === 403) {
      return new SiteError(
        'forbidden',
        `access denied for ${siteName}: user "${user}" signed in but is not allowed to use WPRoadie${wpSaid}. ` +
          `Check the Application Password belongs to the right user and that the user has WPRoadie access (an administrator can run "wp wproadie grant ${user}" or use Tools → WPRoadie).`,
        403,
      );
    }
    if (status === 404 && /session/i.test(said ?? err.message)) {
      return new SiteError('session', `the MCP session for ${siteName} expired`, 404);
    }
    if (status === 404) {
      return new SiteError(
        'not_found',
        `no WPRoadie MCP endpoint at ${endpoint}${wpSaid}. Check that the WPRoadie and MCP Adapter plugins are active and WPRoadie is not switched off.`,
        404,
      );
    }
    if (status === -1 || /Unexpected content type/i.test(err.message)) {
      return new SiteError('not_mcp', `${endpoint} did not answer as an MCP server (got a web page instead of JSON)`);
    }
    if (status === 400 && /session/i.test(said ?? '')) {
      return new SiteError('session', `the MCP session for ${siteName} is no longer valid`, 400);
    }
    if (status >= 500) {
      return new SiteError('server', `${siteName} returned HTTP ${status}${wpSaid}: the site may be down or have a PHP error`, status);
    }
    return new SiteError('server', `${siteName} returned HTTP ${status}${wpSaid}`, status);
  }

  if (err instanceof McpError) {
    if (/session/i.test(err.message) && /not found|expired|invalid/i.test(err.message)) {
      return new SiteError('session', `the MCP session for ${siteName} expired`);
    }
    return new SiteError('server', `${siteName}: ${err.message}`);
  }

  const text = causeText(err);
  if (/ECONNREFUSED|ENOTFOUND|EAI_AGAIN|bad port|ETIMEDOUT|ECONNRESET|fetch failed|certificate|CERT_|UND_ERR/i.test(text)) {
    return new SiteError('network', `could not reach ${siteName} at ${endpoint}: ${text}`);
  }
  if (err instanceof SyntaxError || /JSON|ZodError|invalid_union|Invalid input/i.test(text)) {
    return new SiteError('not_mcp', `${endpoint} did not answer as an MCP server: ${text.slice(0, 200)}`);
  }
  return new SiteError('server', `${siteName}: ${text}`);
}

/** Opens an MCP session (initialize + initialized) against one endpoint. */
export async function connectEndpoint(endpoint: string, creds: Credentials, siteName: string): Promise<SiteConnection> {
  const transport = new StreamableHTTPClientTransport(new URL(endpoint), {
    requestInit: {
      headers: {
        Authorization: basicAuth(creds),
        'User-Agent': `wproadie-cli/${VERSION}`,
      },
    },
  });
  const client = new Client({ name: 'wproadie-cli', version: VERSION }, { capabilities: {} });
  // Background errors (e.g. the optional GET stream) must not crash anything;
  // request failures are reported through the awaited calls instead.
  client.onerror = () => {};
  try {
    await client.connect(transport, { timeout: 30_000 });
  } catch (err) {
    await transport.close().catch(() => {});
    throw explainError(err, siteName, creds.user, endpoint);
  }
  const info = client.getServerVersion();
  return {
    endpoint,
    client,
    transport,
    serverName: info?.name ?? 'unknown',
    serverVersion: info?.version ?? '',
    instructions: client.getInstructions(),
    protocolVersion: transport.protocolVersion,
    async close() {
      // Free the server-side session slot (the adapter caps sessions per user).
      await transport.terminateSession().catch(() => {});
      await client.close().catch(() => {});
    },
  };
}

export interface DiscoveryResult {
  connection: SiteConnection;
  tried: string[];
}

/**
 * Finds a site's endpoint: the stored one (if any), /wp-json/..., then
 * ?rest_route=..., then whatever the Link header advertises. Stops at the
 * first endpoint that speaks MCP, and also stops on an auth failure, because
 * that means the endpoint is right and the credentials are wrong.
 */
export async function discover(siteUrl: string, creds: Credentials, siteName: string, preferred?: string): Promise<DiscoveryResult> {
  const tried: string[] = [];
  const candidates = [...(preferred ? [preferred] : []), ...endpointCandidates(siteUrl)];
  let lastError: SiteError | undefined;
  let linkChecked = false;

  for (let i = 0; i < candidates.length; i++) {
    const endpoint = candidates[i] as string;
    if (tried.includes(endpoint)) continue;
    tried.push(endpoint);
    try {
      return { connection: await connectEndpoint(endpoint, creds, siteName), tried };
    } catch (err) {
      const e = err instanceof SiteError ? err : explainError(err, siteName, creds.user, endpoint);
      if (e.kind === 'auth' || e.kind === 'forbidden' || e.kind === 'network') throw e;
      lastError = e;
    }
    if (i === candidates.length - 1 && !linkChecked) {
      linkChecked = true;
      const fromLink = await endpointFromLinkHeader(siteUrl);
      if (fromLink && !tried.includes(fromLink)) candidates.push(fromLink);
    }
  }
  throw new SiteError(
    lastError?.kind ?? 'not_found',
    `could not find the WPRoadie MCP endpoint for ${siteName}. Tried: ${tried.join(', ')}. ` +
      `Last error: ${lastError?.message ?? 'none'}. Check the URL and that the WPRoadie and MCP Adapter plugins are active.`,
  );
}

export async function listAllTools(client: Client): Promise<Tool[]> {
  const tools: Tool[] = [];
  let cursor: string | undefined;
  do {
    const page = await client.listTools(cursor ? { cursor } : undefined);
    tools.push(...page.tools);
    cursor = page.nextCursor;
  } while (cursor);
  return tools;
}

export async function listAllPrompts(client: Client): Promise<Prompt[]> {
  if (!client.getServerCapabilities()?.prompts) return [];
  const prompts: Prompt[] = [];
  let cursor: string | undefined;
  do {
    const page = await client.listPrompts(cursor ? { cursor } : undefined);
    prompts.push(...page.prompts);
    cursor = page.nextCursor;
  } while (cursor);
  return prompts;
}

/** "site-overview" -> "wproadie-site-overview" when that is what the site calls it. */
export function resolveToolName(name: string, tools: Pick<Tool, 'name'>[]): string {
  if (tools.some((t) => t.name === name)) return name;
  const prefixed = `wproadie-${name}`;
  if (tools.some((t) => t.name === prefixed)) return prefixed;
  return name;
}

export type { Site };
