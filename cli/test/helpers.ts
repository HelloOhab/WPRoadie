import { mkdtempSync, readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

export interface LiveSite {
  url: string;
  adminPasswordFile: string;
  adminPassword: string;
  bobPasswordFile?: string;
}

/**
 * The live WordPress site used by the integration tests. Set:
 *   WPROADIE_TEST_URL            e.g. http://127.0.0.1:8899
 *   WPROADIE_TEST_ADMIN_PW_FILE  file holding an Application Password for "admin" (has WPRoadie access)
 *   WPROADIE_TEST_BOB_PW_FILE    optional: one for "bob" (an admin WITHOUT WPRoadie access)
 * Without them the live tests are skipped.
 */
export async function liveSite(): Promise<LiveSite | undefined> {
  const url = process.env.WPROADIE_TEST_URL;
  const adminPasswordFile = process.env.WPROADIE_TEST_ADMIN_PW_FILE;
  if (!url || !adminPasswordFile) return undefined;
  try {
    await fetch(url, { method: 'HEAD', signal: AbortSignal.timeout(5000) });
  } catch {
    return undefined;
  }
  return {
    url,
    adminPasswordFile,
    adminPassword: readFileSync(adminPasswordFile, 'utf8').trim(),
    bobPasswordFile: process.env.WPROADIE_TEST_BOB_PW_FILE,
  };
}

export const SKIP_LIVE = 'live site not configured (set WPROADIE_TEST_URL and WPROADIE_TEST_ADMIN_PW_FILE)';

export function tempDir(prefix = 'wproadie-test-'): string {
  return mkdtempSync(join(tmpdir(), prefix));
}

/** Absolute path of the built CLI. */
export const CLI = new URL('../dist/cli.js', import.meta.url).pathname;
