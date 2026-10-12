/**
 * Bulk import of sites from CSV or JSON. Never prompts and never reads
 * passwords from the file: each row names a password_command, or the
 * password is set later with `wproadie sites set-password`.
 */
import { readFileSync } from 'node:fs';
import { normalizeUrl, slugify, type Site } from './config.js';

export interface ImportRow {
  name: string;
  url: string;
  user: string;
  passwordCommand?: string;
  displayName?: string;
  aliases: string[];
}

/** RFC 4180-ish CSV: quoted fields, doubled quotes, commas and newlines inside quotes. */
export function parseCsv(input: string): string[][] {
  const rows: string[][] = [];
  let row: string[] = [];
  let field = '';
  let quoted = false;
  const text = input.replace(/^﻿/, '');
  for (let i = 0; i < text.length; i++) {
    const ch = text[i] as string;
    if (quoted) {
      if (ch === '"') {
        if (text[i + 1] === '"') {
          field += '"';
          i++;
        } else {
          quoted = false;
        }
      } else {
        field += ch;
      }
      continue;
    }
    if (ch === '"' && field === '') {
      quoted = true;
    } else if (ch === ',') {
      row.push(field);
      field = '';
    } else if (ch === '\n' || ch === '\r') {
      if (ch === '\r' && text[i + 1] === '\n') i++;
      row.push(field);
      rows.push(row);
      row = [];
      field = '';
    } else {
      field += ch;
    }
  }
  if (field !== '' || row.length) {
    row.push(field);
    rows.push(row);
  }
  return rows.filter((r) => r.some((c) => c.trim() !== '') && !(r[0] ?? '').trim().startsWith('#'));
}

const KEY_ALIASES: Record<string, keyof ImportRow> = {
  name: 'name',
  slug: 'name',
  url: 'url',
  site_url: 'url',
  user: 'user',
  username: 'user',
  login: 'user',
  password_command: 'passwordCommand',
  passwordcommand: 'passwordCommand',
  display_name: 'displayName',
  displayname: 'displayName',
  aliases: 'aliases',
  alias: 'aliases',
};

function normalizeKey(key: string): keyof ImportRow | undefined {
  return KEY_ALIASES[key.trim().toLowerCase().replace(/[\s-]+/g, '_')] ?? KEY_ALIASES[key.trim().toLowerCase()];
}

function toRow(record: Record<string, unknown>, where: string): ImportRow {
  const out: Partial<ImportRow> & { aliases: string[] } = { aliases: [] };
  for (const [k, v] of Object.entries(record)) {
    const key = normalizeKey(k);
    if (!key || v === undefined || v === null || v === '') continue;
    if (key === 'aliases') {
      out.aliases = (Array.isArray(v) ? v.map(String) : String(v).split(/[;|]/)).map((a) => a.trim()).filter(Boolean);
    } else {
      out[key] = String(v).trim();
    }
  }
  const hasPassword = Object.entries(record).some(
    ([k, v]) => /^(app(lication)?[_ ]?)?password$/i.test(k.trim()) && v !== undefined && v !== null && v !== '',
  );
  if (hasPassword) {
    throw new Error(`${where}: has a "password" column. Do not put passwords in import files; use password_command instead.`);
  }
  const missing = (['name', 'url', 'user'] as const).filter((k) => !out[k]);
  if (missing.length) {
    throw new Error(`${where}: missing ${missing.join(', ')}`);
  }
  return out as ImportRow;
}

export function parseImport(content: string, format: 'csv' | 'json'): ImportRow[] {
  if (format === 'json') {
    const data = JSON.parse(content) as unknown;
    const list = Array.isArray(data) ? data : (data as { sites?: unknown }).sites;
    if (!Array.isArray(list)) throw new Error('JSON import must be an array of sites or {"sites": [...]}.');
    return list.map((r, i) => toRow(r as Record<string, unknown>, `entry ${i + 1}`));
  }
  const rows = parseCsv(content);
  const header = rows.shift();
  if (!header) return [];
  return rows.map((cells, i) => {
    const record: Record<string, string> = {};
    header.forEach((h, j) => {
      record[h] = cells[j] ?? '';
    });
    return toRow(record, `line ${i + 2}`);
  });
}

export function readImportFile(path: string): ImportRow[] {
  const content = readFileSync(path, 'utf8');
  const format = /\.json$/i.test(path) || /^\s*[[{]/.test(content) ? 'json' : 'csv';
  return parseImport(content, format);
}

/** Turns an import row into a registry entry (no network). */
export function rowToSite(row: ImportRow): Site {
  const name = slugify(row.name);
  if (!name) throw new Error(`"${row.name}" is not a usable site name`);
  const site: Site = {
    name,
    aliases: row.aliases,
    url: normalizeUrl(row.url),
    endpoint: '',
    user: row.user,
    password: row.passwordCommand ? 'command' : 'none',
    addedAt: new Date().toISOString(),
  };
  const displayName = row.displayName ?? (name !== row.name ? row.name : undefined);
  if (displayName) site.displayName = displayName;
  if (row.passwordCommand) site.passwordCommand = row.passwordCommand;
  return site;
}
