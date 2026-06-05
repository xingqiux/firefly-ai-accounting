import { readFile } from 'node:fs/promises';

import { describe, expect, test } from 'vitest';

describe('package bin', () => {
  test('exposes the global command as ffc', async () => {
    const packageJson = JSON.parse(await readFile('package.json', 'utf8')) as {
      bin?: Record<string, string>;
      scripts?: Record<string, string>;
    };

    expect(packageJson.bin).toEqual({ ffc: './dist/cli.js' });
    expect(packageJson.scripts?.['build:watch']).toBe('tsup --watch');
  });
});
