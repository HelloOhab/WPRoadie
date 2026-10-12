#!/usr/bin/env node
/**
 * wproadie: one MCP connection for all your WPRoadie WordPress sites.
 */
import { spawnSync } from 'node:child_process';
import { realpathSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { Command, Option } from 'commander';
import {
  configPath,
  findSiteByName,
  loadRegistry,
  normalizeUrl,
  passwordEnvVar,
  saveRegistry,
  slugify,
  type Registry,
  type Site,
} from './config.js';
import { clearHqCache, fetchHqSites, getHqToken, HQ_TOKEN_ACCOUNT, HQ_TOKEN_ENV, HqError, mergeHqSites, normalizeHqUrl } from './hq.js';
import { readImportFile, rowToSite } from './importer.js';
import { matchSite } from './match.js';
import { readPassword } from './prompt.js';
import { runMcpServer } from './router.js';
import { describePasswordSource, getPassword, keychainDelete, keychainSet, runPasswordCommand, SecretError } from './secrets.js';
import { VERSION } from './version.js';
import { discover, listAllPrompts, listAllTools, resolveToolName, SiteError, type SiteConnection } from './wp.js';

class UserError extends Error {}

function out(line = ''): void {
  process.stdout.write(line + '\n');
}
function info(line = ''): void {
  process.stderr.write(line + '\n');
}

function collect(value: string, previous: string[] = []): string[] {
  return [...previous, value];
}

function registryPath(): string {
  return configPath();
}

function requireSite(reg: Registry, query: string): Site {
  const exact = findSiteByName(reg, query);
  if (exact) return exact;
  const m = matchSite(query, reg.sites);
  if (m.kind === 'match') return m.site;
  if (m.kind === 'ambiguous') {
    throw new UserError(`"${query}" matches more than one site: ${m.candidates.map((s) => s.name).join(', ')}. Use the exact name.`);
  }
  const known = reg.sites.length ? ` Known sites: ${reg.sites.map((s) => s.name).join(', ')}.` : ' No sites registered yet.';
  throw new UserError(`No site matches "${query}".${known}`);
}

/** The WordPress site title, from the server's instructions ('... the WordPress site "Smith Dental" ...'). */
function siteTitle(conn: SiteConnection): string | undefined {
  const m = /site "([^"]+)"/.exec(conn.instructions ?? '');
  return m?.[1];
}

interface PasswordOpts {
  passwordCommand?: string;
  passwordStdin?: boolean;
}

/** Works out the password to verify with, and where it will be stored. */
async function obtainPassword(name: string, opts: PasswordOpts): Promise<{ password: string; store: 'command' | 'keychain' | 'none' }> {
  if (opts.passwordCommand) {
    try {
      return { password: await runPasswordCommand(opts.passwordCommand), store: 'command' };
    } catch (err) {
      throw new UserError(`The password command failed: ${(err as Error).message}`);
    }
  }
  const envName = passwordEnvVar(name);
  const fromEnv = process.env[envName];
  if (fromEnv && fromEnv.trim() && !opts.passwordStdin) {
    info(`Using the password from ${envName} (not stored; set it wherever you run wproadie).`);
    return { password: fromEnv.trim(), store: 'none' };
  }
  const password = await readPassword(`Application Password for ${name}: `, Boolean(opts.passwordStdin));
  return { password, store: 'keychain' };
}

async function verify(site: Site, password: string): Promise<{ site: Site; title?: string; tools: number; prompts: number }> {
  info(`Connecting to ${site.url} as ${site.user}...`);
  const { connection } = await discover(site.url, { user: site.user, password }, site.name, site.endpoint || undefined);
  try {
    const tools = await listAllTools(connection.client);
    const prompts = await listAllPrompts(connection.client);
    return { site: { ...site, endpoint: connection.endpoint }, title: siteTitle(connection), tools: tools.length, prompts: prompts.length };
  } finally {
    await connection.close();
  }
}

async function storePassword(site: Site, store: 'command' | 'keychain' | 'none', password: string, command?: string): Promise<Site> {
  const updated: Site = { ...site };
  delete updated.passwordCommand;
  if (store === 'command') {
    updated.password = 'command';
    updated.passwordCommand = command;
    await keychainDelete(site.name);
  } else if (store === 'keychain') {
    try {
      await keychainSet(site.name, password);
    } catch (err) {
      throw new UserError(
        `${(err as Error).message}\nNothing was saved. Re-run with --password-command, or set ${passwordEnvVar(site.name)}.`,
      );
    }
    updated.password = 'keychain';
  } else {
    updated.password = 'none';
  }
  return updated;
}

function upsert(reg: Registry, site: Site): void {
  const i = reg.sites.findIndex((s) => s.name === site.name);
  if (i === -1) reg.sites.push(site);
  else reg.sites[i] = site;
  reg.sites.sort((a, b) => a.name.localeCompare(b.name));
}

function table(rows: string[][]): string {
  const widths = rows[0]?.map((_, i) => Math.max(...rows.map((r) => (r[i] ?? '').length))) ?? [];
  return rows.map((r) => r.map((c, i) => (i === r.length - 1 ? c : c.padEnd(widths[i] ?? 0))).join('  ')).join('\n');
}

/** How to start this install of wproadie, for `setup --local`. */
function localCommand(): { command: string; args: string[] } {
  const script = realpathSync(fileURLToPath(import.meta.url));
  return { command: process.execPath, args: [script, 'mcp'] };
}

function shellQuote(s: string): string {
  return /^[\w@%+=:,./-]+$/.test(s) ? s : `'${s.replace(/'/g, `'\\''`)}'`;
}

export function buildProgram(): Command {
  const program = new Command();
  program
    .name('wproadie')
    .description('One MCP connection for all your WPRoadie WordPress sites.')
    .version(VERSION)
    .showHelpAfterError()
    .addHelpText(
      'after',
      `
Registry: ${configPath()} (override with WPROADIE_CONFIG)

Examples:
  wproadie sites add smith-dental --url https://smithdental.com --user rhythm \\
      --password-command 'op read "op://Rhythm/Smith Dental/wproadie"'
  wproadie call smith-dental site-overview
  wproadie setup claude-code --run`,
    );

  const sites = program.command('sites').description('Manage the list of WordPress sites');

  sites
    .command('add')
    .description('Add a site, find its MCP endpoint and check the credentials')
    .argument('<name>', 'short name, e.g. smith-dental ("Smith Dental" becomes smith-dental, with "Smith Dental" as display name)')
    .requiredOption('--url <url>', 'site URL, e.g. https://smithdental.com')
    .requiredOption('--user <login>', 'WordPress username the Application Password belongs to')
    .option('--password-command <cmd>', 'shell command that prints the password (e.g. op read "op://Vault/Item/password"); only the command is stored')
    .option('--password-stdin', 'read the password from stdin instead of prompting')
    .option('--alias <alias>', 'another name for the site (repeatable)', collect)
    .option('--display-name <name>', 'human name, e.g. "Smith Dental"')
    .option('--no-verify', 'save without connecting to the site')
    .option('--replace', 'overwrite an existing site with the same name')
    .addHelpText(
      'after',
      `
Password sources, in order of precedence when the password is needed:
  WPROADIE_PASSWORD_<NAME> environment variable (name upper-cased, dashes as underscores)
  --password-command (run through your shell each time)
  the OS keychain (service "wproadie", account <name>), used when you type or pipe the password`,
    )
    .action(async (rawName: string, opts) => {
      const name = slugify(rawName);
      if (!name) throw new UserError(`"${rawName}" is not a usable name. Use letters, digits and dashes.`);
      const reg = loadRegistry();
      if (findSiteByName(reg, name) && !opts.replace) {
        throw new UserError(`A site named ${name} already exists. Use --replace to overwrite it, or "wproadie sites set-password ${name}".`);
      }
      let site: Site = {
        name,
        aliases: (opts.alias as string[] | undefined) ?? [],
        url: normalizeUrl(opts.url as string),
        endpoint: '',
        user: opts.user as string,
        password: 'none',
        addedAt: new Date().toISOString(),
      };
      const displayName = (opts.displayName as string | undefined) ?? (rawName !== name ? rawName : undefined);
      if (displayName) site.displayName = displayName;

      const { password, store } = await obtainPassword(name, opts as PasswordOpts);
      if (opts.verify !== false) {
        const v = await verify(site, password);
        site = v.site;
        if (!site.displayName && v.title) site.displayName = v.title;
        out(`Connected to "${v.title ?? name}" at ${site.endpoint}`);
        out(`${v.tools} tools, ${v.prompts} prompts available.`);
      }
      site = await storePassword(site, store, password, opts.passwordCommand as string | undefined);
      upsert(reg, site);
      saveRegistry(reg);
      out(`Saved ${name} (password: ${describePasswordSource(site)}) to ${registryPath()}`);
    });

  sites
    .command('list')
    .description('List registered sites')
    .option('--json', 'print JSON')
    .action((opts) => {
      const reg = loadRegistry();
      if (opts.json) {
        out(JSON.stringify(reg.sites.map((s) => ({ ...s, passwordSource: describePasswordSource(s) })), null, 2));
        return;
      }
      if (!reg.sites.length) {
        out('No sites yet. Add one with: wproadie sites add <name> --url <url> --user <login>');
        return;
      }
      const rows = [['NAME', 'URL', 'USER', 'PASSWORD', 'ALIASES']];
      for (const s of reg.sites) {
        rows.push([s.name, s.url, s.user, describePasswordSource(s), [s.displayName, ...s.aliases].filter(Boolean).join(', ')]);
      }
      out(table(rows));
    });

  sites
    .command('remove')
    .alias('rm')
    .description('Remove a site and its keychain entry')
    .argument('<name>', 'site name')
    .action(async (name: string) => {
      const reg = loadRegistry();
      const site = findSiteByName(reg, name);
      if (!site) throw new UserError(`No site named ${name}. See: wproadie sites list`);
      reg.sites = reg.sites.filter((s) => s !== site);
      saveRegistry(reg);
      const deleted = await keychainDelete(site.name);
      out(`Removed ${site.name}${deleted ? ' and its keychain entry' : ''}.`);
      out('The Application Password still exists on the site; revoke it in Tools → WPRoadie if it is no longer needed.');
    });

  sites
    .command('test')
    .description('Connect to a site and list its tools')
    .argument('<name>', 'site name (or a unique part of it)')
    .action(async (name: string) => {
      const reg = loadRegistry();
      const site = requireSite(reg, name);
      const { password, from } = await getPassword(site);
      const v = await verify(site, password);
      if (v.site.endpoint !== site.endpoint) {
        upsert(reg, { ...site, endpoint: v.site.endpoint });
        saveRegistry(reg);
      }
      out(`OK: "${v.title ?? site.name}" at ${v.site.endpoint}`);
      out(`User ${site.user}, password from ${from}. ${v.tools} tools, ${v.prompts} prompts.`);
    });

  sites
    .command('set-password')
    .description('Set or change where a site\'s Application Password comes from')
    .argument('<name>', 'site name')
    .option('--password-command <cmd>', 'shell command that prints the password')
    .option('--password-stdin', 'read the password from stdin instead of prompting')
    .option('--no-verify', 'do not connect to check it')
    .action(async (name: string, opts) => {
      const reg = loadRegistry();
      const site = findSiteByName(reg, name);
      if (!site) throw new UserError(`No site named ${name}. See: wproadie sites list`);
      const { password, store } = await obtainPassword(site.name, opts as PasswordOpts);
      let updated = site;
      if (opts.verify !== false) {
        const v = await verify(site, password);
        updated = v.site;
        out(`Connected to "${v.title ?? site.name}": ${v.tools} tools.`);
      }
      updated = await storePassword(updated, store, password, opts.passwordCommand as string | undefined);
      upsert(reg, updated);
      saveRegistry(reg);
      out(`Password for ${site.name}: ${describePasswordSource(updated)}`);
    });

  sites
    .command('import')
    .description('Add many sites from CSV or JSON (columns: name,url,user,password_command[,display_name,aliases])')
    .argument('<file>', 'a .csv or .json file')
    .option('--replace', 'overwrite sites that already exist')
    .option('--verify', 'connect to each site that has a password command')
    .addHelpText(
      'after',
      `
CSV example (aliases separated by ";"):
  name,url,user,password_command,display_name,aliases
  smith-dental,https://smithdental.com,rhythm,"op read ""op://Rhythm/Smith Dental/wproadie""",Smith Dental,smith;sd

Never put passwords in the file. Sites without a password_command get one later with:
  wproadie sites set-password <name>`,
    )
    .action(async (file: string, opts) => {
      const rows = readImportFile(file);
      const reg = loadRegistry();
      let added = 0;
      let skipped = 0;
      let failed = 0;
      for (const row of rows) {
        let site: Site;
        try {
          site = rowToSite(row);
        } catch (err) {
          info(`skip ${row.name}: ${(err as Error).message}`);
          failed++;
          continue;
        }
        if (findSiteByName(reg, site.name) && !opts.replace) {
          info(`skip ${site.name}: already exists (use --replace)`);
          skipped++;
          continue;
        }
        if (opts.verify && site.password === 'command') {
          try {
            const { password } = await getPassword(site);
            const v = await verify(site, password);
            site = v.site;
            if (!site.displayName && v.title) site.displayName = v.title;
            info(`ok   ${site.name}: ${v.tools} tools`);
          } catch (err) {
            info(`FAIL ${site.name}: ${(err as Error).message}`);
            failed++;
          }
        }
        upsert(reg, site);
        added++;
      }
      saveRegistry(reg);
      out(`Imported ${added} site(s), skipped ${skipped}, ${failed} problem(s). Registry: ${registryPath()}`);
      const noPassword = reg.sites.filter((s) => s.password === 'none' && !process.env[passwordEnvVar(s.name)]);
      if (noPassword.length) {
        out(`${noPassword.length} site(s) have no password yet. Set each with: wproadie sites set-password <name>`);
      }
      if (failed) process.exitCode = 1;
    });

  program
    .command('tools')
    .description("List a site's tools and prompts")
    .argument('<site>', 'site name')
    .action(async (query: string) => {
      const reg = loadRegistry();
      const site = requireSite(reg, query);
      const { password } = await getPassword(site);
      const { connection } = await discover(site.url, { user: site.user, password }, site.name, site.endpoint || undefined);
      try {
        const tools = await listAllTools(connection.client);
        const prompts = await listAllPrompts(connection.client);
        out(table([['TOOL', 'DESCRIPTION'], ...tools.map((t) => [t.name, (t.description ?? '').split('\n')[0]?.slice(0, 90) ?? ''])]));
        if (prompts.length) {
          out('');
          out(table([['PROMPT', 'DESCRIPTION'], ...prompts.map((p) => [p.name, (p.description ?? '').split('\n')[0]?.slice(0, 90) ?? ''])]));
        }
      } finally {
        await connection.close();
      }
    });

  program
    .command('call')
    .description('Call one tool on one site and print the JSON result')
    .argument('<site>', 'site name')
    .argument('<tool>', 'tool name, with or without the "wproadie-" prefix (e.g. site-overview)')
    .argument('[json-args]', 'tool arguments as a JSON object', '{}')
    .action(async (query: string, tool: string, jsonArgs: string) => {
      let args: Record<string, unknown>;
      try {
        args = JSON.parse(jsonArgs) as Record<string, unknown>;
      } catch (err) {
        throw new UserError(`Tool arguments must be JSON, e.g. '{"limit": 5}': ${(err as Error).message}`);
      }
      if (!args || typeof args !== 'object' || Array.isArray(args)) throw new UserError('Tool arguments must be a JSON object.');
      const reg = loadRegistry();
      const site = requireSite(reg, query);
      const { password } = await getPassword(site);
      const { connection } = await discover(site.url, { user: site.user, password }, site.name, site.endpoint || undefined);
      try {
        const tools = await listAllTools(connection.client);
        const name = resolveToolName(tool, tools);
        if (!tools.some((t) => t.name === name)) {
          throw new UserError(`${site.name} has no tool "${tool}". Available: ${tools.map((t) => t.name).join(', ')}`);
        }
        const result = await connection.client.callTool({ name, arguments: args });
        out(JSON.stringify(result, null, 2));
        if (result.isError) process.exitCode = 1;
      } finally {
        await connection.close();
      }
    });

  program
    .command('mcp')
    .description('Run the routing MCP server on stdio (for Claude Code, Claude Desktop, Cursor)')
    .option('--site <name>', 'start with this site selected')
    .action(async (opts) => {
      await runMcpServer({ site: opts.site as string | undefined });
    });

  const hq = program.command('hq').description('Get your sites and connection passwords from WPRoadie HQ');

  hq
    .command('login')
    .description('Connect to HQ with a personal API token (create one under "Me" in HQ)')
    .argument('<url>', 'HQ address, e.g. https://hq.rhythmco.com')
    .option('--token-command <cmd>', 'shell command that prints the token (e.g. a password manager); stored instead of the token')
    .option('--token-stdin', 'read the token from stdin')
    .action(async (url: string, opts) => {
      const registry = loadRegistry();
      let hqUrl: string;
      try {
        hqUrl = normalizeHqUrl(url);
      } catch (err) {
        throw new UserError((err as Error).message);
      }
      let config: NonNullable<Registry['hq']>;
      let token: string;
      if (opts.tokenCommand) {
        config = { url: hqUrl, token: 'command', tokenCommand: opts.tokenCommand as string };
        token = await getHqToken(config);
      } else if (process.env[HQ_TOKEN_ENV]) {
        config = { url: hqUrl, token: 'env' };
        token = await getHqToken(config);
      } else {
        token = await readPassword('HQ API token: ', Boolean(opts.tokenStdin));
        if (!token) throw new UserError('No token given.');
        config = { url: hqUrl, token: 'keychain' };
      }
      // Check the token before storing anything.
      const sites = await fetchHqSites(config, token);
      if (config.token === 'keychain') {
        await keychainSet(HQ_TOKEN_ACCOUNT, token);
      }
      registry.hq = config;
      saveRegistry(registry);
      out(`Connected to HQ at ${hqUrl}: ${sites.length} site(s) available. Run "wproadie hq sync" to add them.`);
    });

  hq
    .command('sync')
    .description('Add or update the sites HQ lists; their passwords come from HQ when needed')
    .option('--prune', 'remove sites that came from HQ but HQ no longer lists')
    .action(async (opts) => {
      const registry = loadRegistry();
      if (!registry.hq) throw new UserError('HQ is not set up. Run "wproadie hq login <url>" first.');
      const sites = await fetchHqSites(registry.hq, await getHqToken(registry.hq));
      const result = mergeHqSites(registry, sites, Boolean(opts.prune));
      saveRegistry(registry);
      clearHqCache();
      out(`Synced from HQ: ${result.added.length} added, ${result.updated.length} updated, ${result.removed.length} removed.`);
      if (result.withoutCredential.length) {
        out(`No connection password in HQ yet for: ${result.withoutCredential.join(', ')}. Provision one on each site's page in HQ.`);
      }
    });

  hq
    .command('status')
    .description('Show the HQ connection')
    .action(async () => {
      const registry = loadRegistry();
      if (!registry.hq) {
        out('Not connected to HQ.');
        return;
      }
      const fromHq = registry.sites.filter((s) => s.password === 'hq').length;
      out(`HQ:     ${registry.hq.url}`);
      out(`Token:  ${process.env[HQ_TOKEN_ENV] ? `env (${HQ_TOKEN_ENV})` : registry.hq.token === 'command' ? `command: ${registry.hq.tokenCommand ?? ''}` : registry.hq.token}`);
      out(`Sites:  ${fromHq} synced from HQ`);
      try {
        const sites = await fetchHqSites(registry.hq, await getHqToken(registry.hq));
        out(`Check:  OK, HQ lists ${sites.length} site(s), ${sites.filter((s) => s.credential).length} with your password`);
      } catch (err) {
        out(`Check:  FAILED, ${(err as Error).message}`);
        process.exitCode = 1;
      }
    });

  hq
    .command('logout')
    .description('Forget HQ and its token (synced sites stay, but need another password source)')
    .action(async () => {
      const registry = loadRegistry();
      if (registry.hq?.token === 'keychain') {
        await keychainDelete(HQ_TOKEN_ACCOUNT).catch(() => false);
      }
      delete registry.hq;
      saveRegistry(registry);
      out('Logged out of HQ.');
    });

  const setup = program.command('setup').description('Connect wproadie to an AI client');

  setup
    .command('claude-code')
    .description('Print (or run) the "claude mcp add" command')
    .option('--run', 'run the command now')
    .addOption(new Option('--scope <scope>', 'Claude Code config scope').choices(['local', 'user', 'project']))
    .option('--local', 'use this installed copy of wproadie instead of npx')
    .action((opts) => {
      const launch = opts.local ? localCommand() : { command: 'npx', args: ['-y', 'wproadie', 'mcp'] };
      const args = ['mcp', 'add', ...(opts.scope ? ['--scope', opts.scope as string] : []), 'wproadie', '--', launch.command, ...launch.args];
      const printable = ['claude', ...args].map(shellQuote).join(' ');
      out(printable);
      if (opts.run) {
        const r = spawnSync('claude', args, { stdio: 'inherit' });
        if (r.error) throw new UserError(`Could not run claude: ${r.error.message}. Is Claude Code installed?`);
        process.exitCode = r.status ?? 1;
      }
    });

  setup
    .command('claude-desktop')
    .description('Print the claude_desktop_config.json snippet (also works for Cursor\'s mcp.json)')
    .option('--local', 'use this installed copy of wproadie instead of npx')
    .action((opts) => {
      const launch = opts.local ? localCommand() : { command: 'npx', args: ['-y', 'wproadie', 'mcp'] };
      const entry: Record<string, unknown> = { command: launch.command, args: launch.args };
      if (process.env.WPROADIE_CONFIG) entry.env = { WPROADIE_CONFIG: process.env.WPROADIE_CONFIG };
      out(JSON.stringify({ mcpServers: { wproadie: entry } }, null, 2));
      info('');
      info('Add this to claude_desktop_config.json (Claude Desktop → Settings → Developer → Edit Config):');
      info('  macOS:   ~/Library/Application Support/Claude/claude_desktop_config.json');
      info('  Windows: %APPDATA%\\Claude\\claude_desktop_config.json');
      info('Cursor: the same block goes in ~/.cursor/mcp.json. Restart the app afterwards.');
    });

  return program;
}

async function main(): Promise<void> {
  const program = buildProgram();
  try {
    await program.parseAsync(process.argv);
  } catch (err) {
    if (err instanceof UserError || err instanceof SiteError || err instanceof SecretError || err instanceof HqError) {
      info(`wproadie: ${err.message}`);
    } else {
      const e = err as Error;
      info(`wproadie: ${process.env.WPROADIE_DEBUG ? (e?.stack ?? String(err)) : (e?.message ?? String(err))}`);
    }
    process.exitCode = 1;
  }
}

void main();
