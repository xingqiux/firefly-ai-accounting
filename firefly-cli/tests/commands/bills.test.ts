import { mkdir, mkdtemp, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

import { afterEach, beforeEach, describe, expect, test } from 'vitest';

import { runCli } from '../helpers/run-cli.js';

let tempDir: string;
let dataDir: string;
const previousDataDir = process.env.FIREFLY_BILLS_DATA_DIR;

beforeEach(async () => {
  tempDir = await mkdtemp(join(tmpdir(), 'firefly-bills-'));
  dataDir = join(tempDir, 'data');
  process.env.FIREFLY_BILLS_DATA_DIR = dataDir;
  await mkdir(dataDir, { recursive: true });
  await writeFile(
    join(dataDir, 'inbox.json'),
    JSON.stringify(
      {
        tasks: [
          {
            id: 'task-1',
            mailMessageId: 'mail-1',
            source: 'cmb',
            profileId: 'cmb-credit-card',
            status: 'needs_secret',
            receivedAt: '2026-06-10T09:30:00+08:00',
            summary: '招商银行信用卡电子账单',
            currentChallengeId: 'challenge-1',
          },
          {
            id: 'task-2',
            mailMessageId: 'mail-2',
            source: 'unknown',
            status: 'unknown',
            receivedAt: '2026-06-10T10:00:00+08:00',
            summary: '未识别账单邮件',
          },
        ],
        mailMessages: [
          {
            id: 'mail-1',
            messageId: '<mail-1@example.com>',
            mailbox: 'bills@example.com',
            from: 'bank@example.com',
            to: 'bills@example.com',
            subject: '招商银行信用卡电子账单',
            receivedAt: '2026-06-10T09:30:00+08:00',
            rawPath: 'mail/raw/mail-1.eml',
            checksum: 'mail-checksum',
          },
        ],
        artifacts: [
          {
            id: 'artifact-1',
            taskId: 'task-1',
            kind: 'zip',
            filename: 'statement.zip',
            path: 'artifacts/original/task-1/statement.zip',
            checksum: 'artifact-checksum',
            encrypted: true,
          },
        ],
        challenges: [
          {
            id: 'challenge-1',
            taskId: 'task-1',
            kind: 'password',
            prompt: '请输入账单解压密码',
            status: 'open',
            attempts: 0,
            createdAt: '2026-06-10T09:31:00+08:00',
          },
        ],
        events: [
          {
            id: 'event-1',
            taskId: 'task-1',
            type: 'task.created',
            at: '2026-06-10T09:30:01+08:00',
            message: '任务已创建',
          },
          {
            id: 'event-2',
            taskId: 'task-1',
            type: 'challenge.created',
            at: '2026-06-10T09:31:00+08:00',
            message: '需要密码',
          },
        ],
      },
      null,
      2,
    ),
  );
});

afterEach(async () => {
  if (previousDataDir === undefined) {
    delete process.env.FIREFLY_BILLS_DATA_DIR;
  } else {
    process.env.FIREFLY_BILLS_DATA_DIR = previousDataDir;
  }
  await rm(tempDir, { force: true, recursive: true });
});

describe('bills commands', () => {
  test('lists bill tasks from the local store', async () => {
    const result = await runCli(['bill-inbox', 'list', '--format', 'json']);

    expect(JSON.parse(result.logs.join('\n'))).toEqual([
      {
        id: 'task-1',
        source: 'cmb',
        profileId: 'cmb-credit-card',
        status: 'needs_secret',
        receivedAt: '2026-06-10T09:30:00+08:00',
        summary: '招商银行信用卡电子账单',
      },
      {
        id: 'task-2',
        source: 'unknown',
        status: 'unknown',
        receivedAt: '2026-06-10T10:00:00+08:00',
        summary: '未识别账单邮件',
      },
    ]);
  });

  test('shows a task with mail artifacts challenge and events', async () => {
    const result = await runCli(['bill-inbox', 'show', 'task-1', '--format', 'json']);

    expect(JSON.parse(result.logs.join('\n'))).toEqual({
      task: expect.objectContaining({
        id: 'task-1',
        status: 'needs_secret',
        currentChallengeId: 'challenge-1',
      }),
      mailMessage: expect.objectContaining({
        id: 'mail-1',
        subject: '招商银行信用卡电子账单',
      }),
      artifacts: [expect.objectContaining({ id: 'artifact-1', encrypted: true })],
      currentChallenge: expect.objectContaining({ id: 'challenge-1', status: 'open' }),
      events: [
        expect.objectContaining({ id: 'event-1' }),
        expect.objectContaining({ id: 'event-2' }),
      ],
    });
  });

  test('lists artifacts and events for a task', async () => {
    const artifacts = await runCli(['bill-inbox', 'artifacts', 'task-1', '--format', 'json']);
    const events = await runCli(['bill-inbox', 'events', 'task-1', '--format', 'json']);

    expect(JSON.parse(artifacts.logs.join('\n'))).toEqual([
      expect.objectContaining({ id: 'artifact-1', filename: 'statement.zip' }),
    ]);
    expect(JSON.parse(events.logs.join('\n'))).toEqual([
      expect.objectContaining({ type: 'task.created' }),
      expect.objectContaining({ type: 'challenge.created' }),
    ]);
  });

  test('secret submit consumes the current challenge and marks task ready', async () => {
    const result = await runCli([
      'bill-inbox',
      'secret',
      'submit',
      'task-1',
      '--value',
      '123456',
      '--format',
      'json',
    ]);

    expect(JSON.parse(result.logs.join('\n'))).toEqual({
      taskId: 'task-1',
      status: 'ready',
      challengeId: 'challenge-1',
      challengeStatus: 'consumed',
    });

    const show = await runCli(['bill-inbox', 'show', 'task-1', '--format', 'json']);
    expect(JSON.parse(show.logs.join('\n'))).toEqual(
      expect.objectContaining({
        task: expect.objectContaining({ status: 'ready' }),
        currentChallenge: expect.objectContaining({
          status: 'consumed',
          attempts: 1,
        }),
      }),
    );
  });

  test('ignore marks a task ignored and records an event', async () => {
    const result = await runCli(['bill-inbox', 'ignore', 'task-2', '--format', 'json']);

    expect(JSON.parse(result.logs.join('\n'))).toEqual({
      taskId: 'task-2',
      status: 'ignored',
    });

    const show = await runCli(['bill-inbox', 'show', 'task-2', '--format', 'json']);
    expect(JSON.parse(show.logs.join('\n')).events).toEqual([
      expect.objectContaining({
        type: 'task.ignored',
        message: '任务已忽略',
      }),
    ]);
  });
});
