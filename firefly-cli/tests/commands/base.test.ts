import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';

import { ConfigStore } from '../../src/core/config-store.js';
import { runCli } from '../helpers/run-cli.js';

let tempDir: string;
let configPath: string;
const previousConfigEnv = process.env.FIREFLY_CLI_CONFIG;

beforeEach(async () => {
  tempDir = await mkdtemp(join(tmpdir(), 'firefly-cli-base-'));
  configPath = join(tempDir, 'config.json');
  process.env.FIREFLY_CLI_CONFIG = configPath;
  await new ConfigStore(configPath).setToken({
    profile: 'local',
    baseUrl: 'http://127.0.0.1:8000',
    token: 'secret-token',
  });
});

afterEach(async () => {
  if (previousConfigEnv === undefined) {
    delete process.env.FIREFLY_CLI_CONFIG;
  } else {
    process.env.FIREFLY_CLI_CONFIG = previousConfigEnv;
  }
  vi.restoreAllMocks();
  await rm(tempDir, { force: true, recursive: true });
});

function mockJsonFetch(body: unknown) {
  return vi.spyOn(globalThis, 'fetch').mockResolvedValue(
    new Response(JSON.stringify(body), {
      status: 200,
      headers: { 'content-type': 'application/json' },
    }),
  );
}

describe('base commands', () => {
  test('about calls /api/v1/about', async () => {
    const fetchMock = mockJsonFetch({ data: { version: 'test' } });

    const result = await runCli(['about', '--format', 'json']);

    expect(fetchMock).toHaveBeenCalledWith(
      'http://127.0.0.1:8000/api/v1/about',
      expect.objectContaining({ method: 'GET' }),
    );
    expect(result.logs.join('\n')).toContain('"version": "test"');
  });

  test('me calls /api/v1/about/user', async () => {
    const fetchMock = mockJsonFetch({ data: { email: 'user@example.com' } });

    const result = await runCli(['me', '--format', 'json']);

    expect(fetchMock).toHaveBeenCalledWith(
      'http://127.0.0.1:8000/api/v1/about/user',
      expect.objectContaining({ method: 'GET' }),
    );
    expect(result.logs.join('\n')).toContain('user@example.com');
  });

  test('api command passes method path query and JSON body', async () => {
    const fetchMock = mockJsonFetch({ data: { id: '1' } });

    await runCli([
      'api',
      'POST',
      '/api/v1/accounts',
      '--query',
      'page=1',
      '--json',
      '{"name":"Cash"}',
      '--format',
      'json',
    ]);

    expect(fetchMock).toHaveBeenCalledWith(
      'http://127.0.0.1:8000/api/v1/accounts?page=1',
      expect.objectContaining({
        method: 'POST',
        body: '{"name":"Cash"}',
        headers: expect.objectContaining({
          Authorization: 'Bearer secret-token',
          'Content-Type': 'application/json',
        }),
      }),
    );
  });
});
