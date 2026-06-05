import { mkdtemp, symlink, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';

import { describe, expect, test } from 'vitest';

import { createProgram, isCliEntrypoint } from '../src/cli.js';

describe('createProgram', () => {
  test('names the CLI ffc', () => {
    const program = createProgram();

    expect(program.name()).toBe('ffc');
  });

  test('treats a symlinked bin path as the CLI entrypoint', async () => {
    const tempDir = await mkdtemp(join(tmpdir(), 'firefly-cli-bin-'));
    const realPath = join(tempDir, 'cli.js');
    const linkedPath = join(tempDir, 'ffc');

    try {
      await writeFile(realPath, '');
      await symlink(realPath, linkedPath);

      expect(isCliEntrypoint(pathToFileURL(realPath).href, linkedPath)).toBe(true);
    } finally {
      await rm(tempDir, { force: true, recursive: true });
    }
  });
});
