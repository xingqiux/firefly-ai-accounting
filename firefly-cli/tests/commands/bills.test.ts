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
  tempDir = await mkdtemp(join(tmpdir(), 'firefly-bill-tasks-'));
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

function mockJsonFetch(body: unknown = { data: [] }) {
  return vi.spyOn(globalThis, 'fetch').mockImplementation(
    async () =>
      new Response(JSON.stringify(body), {
        status: 200,
        headers: { 'content-type': 'application/json' },
      }),
  );
}

function requestBody(call: unknown[]): unknown {
  const init = call[1] as RequestInit;
  return JSON.parse(String(init.body));
}

describe('bill inbox commands', () => {
  test('lists bill tasks from the Firefly API', async () => {
    const fetchMock = mockJsonFetch({
      data: [
        {
          id: '1',
          attributes: {
            source: 'cmb',
            profile_id: 'cmb-credit-card',
            status: 'needs_secret',
            received_at: '2026-06-10T09:30:00+08:00',
            summary: '招商银行信用卡电子账单',
          },
        },
      ],
    });

    const result = await runCli([
      'bill-inbox',
      'list',
      '--source',
      'alipay',
      '--status',
      'parsed',
      '--format',
      'json',
    ]);

    expect(fetchMock).toHaveBeenCalledWith(
      'http://127.0.0.1:8000/api/v1/bill-tasks?source=alipay&status=parsed',
      expect.objectContaining({
        method: 'GET',
        headers: expect.objectContaining({ Authorization: 'Bearer secret-token' }),
      }),
    );
    expect(JSON.parse(result.logs.join('\n'))).toEqual({
      data: [
        {
          id: '1',
          attributes: expect.objectContaining({
            source: 'cmb',
            status: 'needs_secret',
          }),
        },
      ],
    });
  });

  test('shows a bill task and related details from the Firefly API', async () => {
    const fetchMock = mockJsonFetch({
      data: {
        id: '1',
        type: 'bill-tasks',
        attributes: {
          source: 'cmb',
          status: 'needs_secret',
          summary: '招商银行信用卡电子账单',
        },
        relationships: {
          mail_message: { data: { id: '1', type: 'bill-mail-messages' } },
          current_challenge: { data: { id: '1', type: 'bill-secret-challenges' } },
        },
      },
      included: [],
    });

    await runCli(['bill-inbox', 'show', '1', '--format', 'json']);

    expect(fetchMock).toHaveBeenCalledWith(
      'http://127.0.0.1:8000/api/v1/bill-tasks/1',
      expect.objectContaining({ method: 'GET' }),
    );
  });

  test('lists bill task artifacts and events through the Firefly API', async () => {
    const fetchMock = mockJsonFetch({ data: [] });

    await runCli(['bill-inbox', 'artifacts', '1', '--format', 'json']);
    expect(fetchMock).toHaveBeenLastCalledWith(
      'http://127.0.0.1:8000/api/v1/bill-tasks/1/artifacts',
      expect.objectContaining({ method: 'GET' }),
    );

    await runCli(['bill-inbox', 'events', '1', '--format', 'json']);
    expect(fetchMock).toHaveBeenLastCalledWith(
      'http://127.0.0.1:8000/api/v1/bill-tasks/1/events',
      expect.objectContaining({ method: 'GET' }),
    );
  });

  test('lists statement rows with filters through the Firefly API', async () => {
    const fetchMock = mockJsonFetch({ data: [] });

    await runCli([
      'bill-inbox',
      'rows',
      '1',
      '--status',
      'pending',
      '--from',
      '2026-05-15',
      '--to',
      '2026-06-15',
      '--format',
      'json',
    ]);

    expect(fetchMock).toHaveBeenCalledWith(
      'http://127.0.0.1:8000/api/v1/bill-tasks/1/rows?status=pending&from=2026-05-15&to=2026-06-15',
      expect.objectContaining({ method: 'GET' }),
    );
  });

  test('shows and updates a statement row through the Firefly API', async () => {
    const fetchMock = mockJsonFetch({
      data: {
        id: '9',
        type: 'bill-statement-rows',
        attributes: { counterparty: '中国联通' },
      },
    });

    await runCli(['bill-inbox', 'row', 'show', '9', '--format', 'json']);
    expect(fetchMock).toHaveBeenLastCalledWith(
      'http://127.0.0.1:8000/api/v1/bill-statement-rows/9',
      expect.objectContaining({ method: 'GET' }),
    );

    await runCli([
      'bill-inbox',
      'row',
      'update',
      '9',
      '--set',
      'counterparty=中国联通线上营业厅',
      '--set',
      'firefly_amount=20.00',
      '--format',
      'json',
    ]);

    expect(fetchMock).toHaveBeenLastCalledWith(
      'http://127.0.0.1:8000/api/v1/bill-statement-rows/9',
      expect.objectContaining({
        method: 'PATCH',
        headers: expect.objectContaining({ 'Content-Type': 'application/json' }),
      }),
    );
    expect(requestBody(fetchMock.mock.calls[1])).toEqual({
      counterparty: '中国联通线上营业厅',
      firefly_amount: '20.00',
    });
  });

  test('imports statement rows with dry run and confirmation flags', async () => {
    const fetchMock = mockJsonFetch({
      summary: { total: 3, imported: 3, skipped: 0, failed: 0 },
      rows: [],
    });

    await runCli(['bill-inbox', 'import', '1', '--rows', '5,6,7', '--format', 'json']);
    expect(fetchMock).toHaveBeenLastCalledWith(
      'http://127.0.0.1:8000/api/v1/bill-tasks/1/import',
      expect.objectContaining({ method: 'POST' }),
    );
    expect(requestBody(fetchMock.mock.calls[0])).toEqual({
      row_ids: [5, 6, 7],
      confirm: false,
    });

    await runCli(['bill-inbox', 'import', '1', '--all', '--confirm', '--format', 'json']);
    expect(requestBody(fetchMock.mock.calls[1])).toEqual({
      all: true,
      confirm: true,
    });
  });

  test('archives one or many bill tasks through the Firefly API', async () => {
    const fetchMock = mockJsonFetch({
      data: { id: '1', type: 'bill-tasks', attributes: { status: 'cleaned' } },
    });

    await runCli(['bill-inbox', 'archive', '1', '--format', 'json']);
    expect(fetchMock).toHaveBeenLastCalledWith(
      'http://127.0.0.1:8000/api/v1/bill-tasks/1/archive',
      expect.objectContaining({ method: 'POST' }),
    );

    await runCli(['bill-inbox', 'archive', '--ids', '5,6,7', '--format', 'json']);
    expect(fetchMock).toHaveBeenLastCalledWith(
      'http://127.0.0.1:8000/api/v1/bill-tasks/archive',
      expect.objectContaining({ method: 'POST' }),
    );
    expect(requestBody(fetchMock.mock.calls[1])).toEqual({ ids: [5, 6, 7] });
  });

  test('submits a secret through the Firefly API', async () => {
    const fetchMock = mockJsonFetch({
      data: {
        id: '1',
        type: 'bill-tasks',
        attributes: { status: 'ready' },
      },
    });

    await runCli(['bill-inbox', 'secret', 'submit', '1', '--value', '123456', '--format', 'json']);

    expect(fetchMock).toHaveBeenCalledWith(
      'http://127.0.0.1:8000/api/v1/bill-tasks/1/secret',
      expect.objectContaining({
        method: 'POST',
        headers: expect.objectContaining({ 'Content-Type': 'application/json' }),
      }),
    );
    expect(requestBody(fetchMock.mock.calls[0])).toEqual({ value: '123456' });
  });

  test('ignores and retries tasks through the Firefly API', async () => {
    const fetchMock = mockJsonFetch({
      data: {
        id: '1',
        type: 'bill-tasks',
        attributes: { status: 'ignored' },
      },
    });

    await runCli(['bill-inbox', 'ignore', '1', '--format', 'json']);
    expect(fetchMock).toHaveBeenLastCalledWith(
      'http://127.0.0.1:8000/api/v1/bill-tasks/1/ignore',
      expect.objectContaining({ method: 'POST' }),
    );

    await runCli(['bill-inbox', 'retry', '1', '--format', 'json']);
    expect(fetchMock).toHaveBeenLastCalledWith(
      'http://127.0.0.1:8000/api/v1/bill-tasks/1/retry',
      expect.objectContaining({ method: 'POST' }),
    );
  });
});
