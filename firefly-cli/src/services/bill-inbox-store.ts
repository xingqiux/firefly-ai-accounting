import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { dirname, join } from 'node:path';

import { FireflyInputError } from '../core/errors.js';

export type BillTaskStatus =
  | 'received'
  | 'archived'
  | 'routed'
  | 'unknown'
  | 'needs_secret'
  | 'ready'
  | 'processing'
  | 'parsed'
  | 'matched'
  | 'review'
  | 'imported'
  | 'ignored'
  | 'failed';

export interface MailMessage {
  id: string;
  messageId?: string;
  mailbox?: string;
  from?: string;
  to?: string;
  subject?: string;
  receivedAt?: string;
  rawPath?: string;
  bodyTextPath?: string;
  bodyHtmlPath?: string;
  checksum?: string;
  syncCursor?: string;
}

export interface BillTask {
  id: string;
  mailMessageId?: string;
  source: string;
  profileId?: string;
  status: BillTaskStatus;
  receivedAt?: string;
  summary?: string;
  currentChallengeId?: string;
  errorCode?: string;
  errorMessage?: string;
}

export interface BillArtifact {
  id: string;
  taskId: string;
  kind: string;
  filename?: string;
  path?: string;
  checksum?: string;
  encrypted?: boolean;
  derivedFromArtifactId?: string;
  metadata?: Record<string, unknown>;
}

export interface SecretChallenge {
  id: string;
  taskId: string;
  kind: 'password' | 'code';
  prompt?: string;
  status: 'open' | 'consumed' | 'failed' | 'cancelled';
  attempts: number;
  createdAt?: string;
  consumedAt?: string;
}

export interface BillEvent {
  id: string;
  taskId: string;
  type: string;
  at: string;
  message: string;
  metadata?: Record<string, unknown>;
}

export interface BillInboxData {
  tasks: BillTask[];
  mailMessages: MailMessage[];
  artifacts: BillArtifact[];
  challenges: SecretChallenge[];
  events: BillEvent[];
}

export interface BillTaskDetail {
  task: BillTask;
  mailMessage?: MailMessage;
  artifacts: BillArtifact[];
  currentChallenge?: SecretChallenge;
  events: BillEvent[];
}

export class BillInboxStore {
  constructor(private readonly dataDir = getDefaultBillInboxDataDir()) {}

  getPath(): string {
    return join(this.dataDir, 'inbox.json');
  }

  async listTasks(): Promise<BillTask[]> {
    const data = await this.load();
    return [...data.tasks].sort((left, right) =>
      String(left.receivedAt ?? '').localeCompare(String(right.receivedAt ?? '')),
    );
  }

  async getTaskDetail(taskId: string): Promise<BillTaskDetail> {
    const data = await this.load();
    const task = findTask(data, taskId);
    return {
      task,
      mailMessage: data.mailMessages.find((message) => message.id === task.mailMessageId),
      artifacts: data.artifacts.filter((artifact) => artifact.taskId === taskId),
      currentChallenge: task.currentChallengeId
        ? data.challenges.find((challenge) => challenge.id === task.currentChallengeId)
        : undefined,
      events: data.events.filter((event) => event.taskId === taskId),
    };
  }

  async listArtifacts(taskId: string): Promise<BillArtifact[]> {
    const data = await this.load();
    findTask(data, taskId);
    return data.artifacts.filter((artifact) => artifact.taskId === taskId);
  }

  async listEvents(taskId: string): Promise<BillEvent[]> {
    const data = await this.load();
    findTask(data, taskId);
    return data.events.filter((event) => event.taskId === taskId);
  }

  async submitSecret(
    taskId: string,
    value: string,
  ): Promise<{
    taskId: string;
    status: BillTaskStatus;
    challengeId: string;
    challengeStatus: SecretChallenge['status'];
  }> {
    if (value.trim() === '') {
      throw new FireflyInputError('Secret value must not be empty.');
    }

    const data = await this.load();
    const task = findTask(data, taskId);
    if (!task.currentChallengeId) {
      throw new FireflyInputError(`Task "${taskId}" has no open challenge.`);
    }

    const challenge = data.challenges.find((candidate) => candidate.id === task.currentChallengeId);
    if (!challenge || challenge.status !== 'open') {
      throw new FireflyInputError(`Task "${taskId}" has no open challenge.`);
    }

    challenge.status = 'consumed';
    challenge.attempts += 1;
    challenge.consumedAt = now();
    task.status = 'ready';
    appendEvent(data, taskId, 'challenge.consumed', '验证码/密码已提交');
    appendEvent(data, taskId, 'task.ready', '任务已准备处理');
    await this.save(data);

    return {
      taskId,
      status: task.status,
      challengeId: challenge.id,
      challengeStatus: challenge.status,
    };
  }

  async ignoreTask(taskId: string): Promise<{ taskId: string; status: BillTaskStatus }> {
    const data = await this.load();
    const task = findTask(data, taskId);
    task.status = 'ignored';
    appendEvent(data, taskId, 'task.ignored', '任务已忽略');
    await this.save(data);
    return { taskId, status: task.status };
  }

  async load(): Promise<BillInboxData> {
    try {
      const parsed = JSON.parse(await readFile(this.getPath(), 'utf8')) as Partial<BillInboxData>;
      return normalizeData(parsed);
    } catch {
      return createEmptyData();
    }
  }

  async save(data: BillInboxData): Promise<void> {
    await mkdir(dirname(this.getPath()), { recursive: true });
    await writeFile(this.getPath(), `${JSON.stringify(normalizeData(data), null, 2)}\n`);
  }
}

export function getDefaultBillInboxDataDir(): string {
  return process.env.FIREFLY_BILLS_DATA_DIR ?? 'firefly-cli-data';
}

function createEmptyData(): BillInboxData {
  return {
    tasks: [],
    mailMessages: [],
    artifacts: [],
    challenges: [],
    events: [],
  };
}

function normalizeData(input: Partial<BillInboxData>): BillInboxData {
  return {
    tasks: Array.isArray(input.tasks) ? input.tasks : [],
    mailMessages: Array.isArray(input.mailMessages) ? input.mailMessages : [],
    artifacts: Array.isArray(input.artifacts) ? input.artifacts : [],
    challenges: Array.isArray(input.challenges) ? input.challenges : [],
    events: Array.isArray(input.events) ? input.events : [],
  };
}

function findTask(data: BillInboxData, taskId: string): BillTask {
  const task = data.tasks.find((candidate) => candidate.id === taskId);
  if (!task) {
    throw new FireflyInputError(`Bill task "${taskId}" was not found.`);
  }
  return task;
}

function appendEvent(data: BillInboxData, taskId: string, type: string, message: string): void {
  data.events.push({
    id: `event-${data.events.length + 1}`,
    taskId,
    type,
    at: now(),
    message,
  });
}

function now(): string {
  return new Date().toISOString();
}
