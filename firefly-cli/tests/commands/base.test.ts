import { mkdir, mkdtemp, rm, writeFile } from 'node:fs/promises';
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

  test('doctor local reports missing assets and APP_URL port mismatch', async () => {
    const rootPath = join(tempDir, 'firefly-iii');
    await mkdir(join(rootPath, 'public', 'v1', 'js'), { recursive: true });
    await mkdir(join(rootPath, 'storage', 'database'), { recursive: true });
    await writeFile(join(rootPath, 'artisan'), '');
    await writeFile(
      join(rootPath, '.env'),
      [
        'APP_URL=http://127.0.0.1:8000',
        'TZ=Europe/Amsterdam',
        `DB_DATABASE=${join(rootPath, 'storage', 'database', 'database.sqlite')}`,
      ].join('\n'),
    );
    await writeFile(join(rootPath, 'storage', 'database', 'database.sqlite'), '');
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(
      new Response('', {
        status: 302,
        headers: { location: 'http://127.0.0.1:8001/login' },
      }),
    );

    const result = await runCli([
      'doctor',
      'local',
      '--root',
      rootPath,
      '--url',
      'http://127.0.0.1:8001',
      '--format',
      'json',
    ]);

    expect(fetchMock).toHaveBeenCalledWith(
      'http://127.0.0.1:8001/',
      expect.objectContaining({ method: 'GET' }),
    );
    const report = JSON.parse(result.logs.join('\n'));
    expect(report.ok).toBe(false);
    expect(report.checks).toEqual(
      expect.arrayContaining([
        {
          name: 'root',
          status: 'ok',
          message: `Firefly III root found at ${rootPath}.`,
        },
        {
          name: 'database',
          status: 'ok',
          message: 'SQLite database exists.',
          path: join(rootPath, 'storage', 'database', 'database.sqlite'),
        },
        {
          name: 'app-url',
          status: 'warn',
          message:
            'APP_URL points to http://127.0.0.1:8000 but checked URL is http://127.0.0.1:8001.',
          expected: 'http://127.0.0.1:8001',
          actual: 'http://127.0.0.1:8000',
        },
        {
          name: 'timezone',
          status: 'warn',
          message:
            'TZ is Europe/Amsterdam but local accounting imports expect Asia/Shanghai. Update firefly-iii/.env or pass --timezone when importing.',
          expected: 'Asia/Shanghai',
          actual: 'Europe/Amsterdam',
        },
        {
          name: 'v2-assets',
          status: 'fail',
          message:
            'Missing public/build/manifest.json. Run npm install and npm run build --workspace resources/assets/v2 from firefly-iii.',
          path: join(rootPath, 'public', 'build', 'manifest.json'),
        },
        {
          name: 'v1-assets',
          status: 'fail',
          message:
            'Missing public/v1/js/app.js. Run npm run production --workspace resources/assets/v1 from firefly-iii and hard refresh the browser.',
          path: join(rootPath, 'public', 'v1', 'js', 'app.js'),
        },
        {
          name: 'http',
          status: 'ok',
          message: 'http://127.0.0.1:8001/ responded with HTTP 302.',
        },
      ]),
    );
  });
});
