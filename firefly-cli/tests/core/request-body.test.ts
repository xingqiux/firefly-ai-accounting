import { mkdtemp, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

import { afterEach, beforeEach, describe, expect, test } from 'vitest';

import { parseBodyOptions } from '../../src/core/request-body.js';

let tempDir: string;

beforeEach(async () => {
  tempDir = await mkdtemp(join(tmpdir(), 'firefly-cli-body-'));
});

afterEach(async () => {
  await rm(tempDir, { force: true, recursive: true });
});

describe('parseBodyOptions', () => {
  test('parses inline JSON', async () => {
    await expect(parseBodyOptions({ json: '{"name":"Cash"}' })).resolves.toEqual({
      json: { name: 'Cash' },
    });
  });

  test('parses JSON body files', async () => {
    const file = join(tempDir, 'body.json');
    await writeFile(file, '{"amount":"12.34"}', 'utf8');

    await expect(parseBodyOptions({ body: file })).resolves.toEqual({
      json: { amount: '12.34' },
    });
  });

  test('rejects conflicting body options', async () => {
    await expect(parseBodyOptions({ json: '{}', body: 'body.json' })).rejects.toThrow(
      'Use either --json or --body, not both.',
    );
  });
});
