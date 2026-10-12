/**
 * Where Application Passwords come from. In order:
 *   1. WPROADIE_PASSWORD_<NAME> environment variable (CI, scripts)
 *   2. a password-manager command, run through the user's shell
 *   3. the OS keychain (service "wproadie", account = site name)
 * Passwords are never written to the registry file.
 */
import { spawn } from 'node:child_process';
import { passwordEnvVar, type Site } from './config.js';

export const KEYCHAIN_SERVICE = 'wproadie';

export class SecretError extends Error {}

export class KeychainUnavailableError extends SecretError {
  constructor(detail: string) {
    super(
      `The OS keychain is not available (${detail}). ` +
        'Use a password manager instead (--password-command "op read op://Vault/Item/password"), ' +
        'or set the password in an environment variable (WPROADIE_PASSWORD_<SITE_NAME>).',
    );
  }
}

interface KeyringEntry {
  setPassword(password: string): void;
  getPassword(): string | null;
  deletePassword(): boolean;
}
type EntryCtor = new (service: string, account: string) => KeyringEntry;

let entryCtor: Promise<EntryCtor> | undefined;

async function loadKeyring(): Promise<EntryCtor> {
  entryCtor ??= (async () => {
    try {
      const mod = (await import('@napi-rs/keyring')) as unknown as { Entry?: EntryCtor; default?: { Entry?: EntryCtor } };
      const ctor = mod.Entry ?? mod.default?.Entry;
      if (!ctor) throw new Error('module has no Entry export');
      return ctor;
    } catch (err) {
      throw new KeychainUnavailableError(`could not load @napi-rs/keyring: ${(err as Error).message}`);
    }
  })();
  return entryCtor;
}

function keychainFailure(err: unknown): never {
  if (err instanceof SecretError) throw err;
  throw new KeychainUnavailableError((err as Error)?.message ?? String(err));
}

export async function keychainSet(account: string, password: string): Promise<void> {
  const Entry = await loadKeyring();
  try {
    new Entry(KEYCHAIN_SERVICE, account).setPassword(password);
  } catch (err) {
    keychainFailure(err);
  }
}

export async function keychainGet(account: string): Promise<string | null> {
  const Entry = await loadKeyring();
  try {
    return new Entry(KEYCHAIN_SERVICE, account).getPassword();
  } catch (err) {
    const message = (err as Error)?.message ?? '';
    if (/no (matching )?entry|not found/i.test(message)) return null;
    keychainFailure(err);
  }
}

/** Deletes a keychain entry. Returns false when there was none or no keychain. */
export async function keychainDelete(account: string): Promise<boolean> {
  try {
    const Entry = await loadKeyring();
    return new Entry(KEYCHAIN_SERVICE, account).deletePassword();
  } catch {
    return false;
  }
}

export interface RunOptions {
  /** Show the command's stderr (e.g. a password-manager unlock prompt). Default true. */
  inheritStderr?: boolean;
  timeoutMs?: number;
}

/**
 * Runs a password command through the user's shell and returns its output,
 * trimmed of the trailing newline. stdin is never shared, so this is safe
 * inside `wproadie mcp`, where stdin carries the protocol.
 */
export function runPasswordCommand(command: string, opts: RunOptions = {}): Promise<string> {
  const shell = process.platform === 'win32' ? true : process.env.SHELL || '/bin/sh';
  return new Promise((resolve, reject) => {
    const child = spawn(command, {
      shell,
      stdio: ['ignore', 'pipe', opts.inheritStderr === false ? 'pipe' : 'inherit'],
      timeout: opts.timeoutMs ?? 120_000,
    });
    const out: Buffer[] = [];
    const err: Buffer[] = [];
    child.stdout?.on('data', (d: Buffer) => out.push(d));
    child.stderr?.on('data', (d: Buffer) => err.push(d));
    child.on('error', (e) => reject(new SecretError(`password command failed to start: ${e.message}`)));
    child.on('close', (code, signal) => {
      if (code !== 0) {
        const detail = Buffer.concat(err).toString('utf8').trim();
        reject(
          new SecretError(
            `password command exited with ${signal ? `signal ${signal}` : `code ${code}`}` + (detail ? `: ${detail.slice(0, 300)}` : ''),
          ),
        );
        return;
      }
      const value = Buffer.concat(out).toString('utf8').replace(/\r?\n$/, '');
      if (value.trim() === '') {
        reject(new SecretError('password command printed nothing'));
        return;
      }
      resolve(value.trim());
    });
  });
}

export type ResolvedFrom = 'env' | 'command' | 'keychain';

/** Gets a site's Application Password, or throws a SecretError saying how to set one. */
export async function getPassword(site: Site, opts: RunOptions = {}): Promise<{ password: string; from: ResolvedFrom }> {
  const envName = passwordEnvVar(site.name);
  const fromEnv = process.env[envName];
  if (fromEnv && fromEnv.trim() !== '') {
    return { password: fromEnv.trim(), from: 'env' };
  }
  if (site.password === 'command' && site.passwordCommand) {
    try {
      return { password: await runPasswordCommand(site.passwordCommand, opts), from: 'command' };
    } catch (err) {
      throw new SecretError(`Could not get the password for ${site.name}: ${(err as Error).message}`);
    }
  }
  if (site.password === 'keychain') {
    let value: string | null;
    try {
      value = await keychainGet(site.name);
    } catch (err) {
      throw new SecretError(`Could not get the password for ${site.name}: ${(err as Error).message}`);
    }
    if (value) return { password: value, from: 'keychain' };
    throw new SecretError(
      `No password for ${site.name} in the OS keychain. Run: wproadie sites set-password ${site.name}`,
    );
  }
  throw new SecretError(
    `No password configured for ${site.name}. Run "wproadie sites set-password ${site.name}" ` +
      `(add --password-command "<cmd>" to use a password manager) or set ${envName}.`,
  );
}

/** For `sites list`: where the password will come from right now. */
export function describePasswordSource(site: Site): string {
  if (process.env[passwordEnvVar(site.name)]) return `env (${passwordEnvVar(site.name)})`;
  if (site.password === 'command') return `command: ${site.passwordCommand ?? ''}`;
  if (site.password === 'keychain') return 'keychain';
  return 'not set';
}
