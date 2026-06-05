import { readFile } from 'node:fs/promises';

import { FireflyInputError } from './errors.js';

export interface BodyOptionInput {
  json?: string;
  body?: string;
}

export interface ParsedBodyOptions {
  json?: unknown;
}

export async function parseBodyOptions(options: BodyOptionInput): Promise<ParsedBodyOptions> {
  if (options.json && options.body) {
    throw new FireflyInputError('Use either --json or --body, not both.');
  }
  if (options.json) {
    return { json: parseJson(options.json, '--json') };
  }
  if (options.body) {
    const raw = await readFile(options.body, 'utf8');
    return { json: parseJson(raw, options.body) };
  }
  return {};
}

function parseJson(raw: string, source: string): unknown {
  try {
    return JSON.parse(raw);
  } catch {
    throw new FireflyInputError(`Invalid JSON in ${source}.`);
  }
}
