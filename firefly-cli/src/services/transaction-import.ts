import { readFile } from 'node:fs/promises';

import { FireflyInputError } from '../core/errors.js';
import type { FireflyHttpClient } from '../core/http-client.js';

export interface TransactionImportOptions {
  input: string;
  mode: 'dry-run' | 'confirm';
  timezone?: string;
}

export interface TransactionImportReport {
  mode: 'dry-run' | 'confirm';
  timezone?: string;
  summary: {
    total: number;
    create: number;
    duplicate: number;
    ambiguous: number;
    submitted?: number;
  };
  rows: TransactionImportRowReport[];
  response?: unknown;
}

export interface TransactionImportRowReport {
  row: number;
  status: 'create' | 'duplicate' | 'ambiguous' | 'created';
  transaction: FireflyTransactionImportRow;
  originalDate?: string;
  fireflyDate?: string;
  reasons?: string[];
  duplicateIds?: string[];
}

export interface FireflyTransactionImportRow {
  type?: string;
  date?: string;
  original_date?: string;
  source_id?: string;
  source_name?: string;
  destination_id?: string;
  destination_name?: string;
  amount?: string;
  description?: string;
  category_name?: string;
  notes?: string;
  tags?: string[];
}

interface ExistingTransaction {
  id: string;
  date?: string;
  source_id?: string;
  source_name?: string;
  destination_id?: string;
  destination_name?: string;
  amount?: string;
  description?: string;
}

export async function importTransactions(
  client: FireflyHttpClient,
  options: TransactionImportOptions,
): Promise<TransactionImportReport> {
  const transactions = applyTimezone(await readTransactions(options.input), options.timezone);
  const existing = await fetchExistingTransactions(client, transactions);
  const rows = transactions.map((transaction, index) =>
    classifyTransaction(index + 1, transaction, existing),
  );
  const summary = summarize(rows);

  if (options.mode === 'dry-run') {
    return stripUndefined({ mode: options.mode, timezone: options.timezone, summary, rows });
  }

  const createRows = rows.filter((row) => row.status === 'create');
  if (createRows.length === 0) {
    return {
      mode: options.mode,
      timezone: options.timezone,
      summary: { ...summary, submitted: 0 },
      rows,
    };
  }

  const response = await client.request('POST', '/api/v1/transactions', {
    json: { transactions: createRows.map((row) => buildFireflyTransaction(row.transaction)) },
  });

  return {
    mode: options.mode,
    timezone: options.timezone,
    summary: { ...summary, submitted: createRows.length },
    rows: rows.map((row) => (row.status === 'create' ? { ...row, status: 'created' } : row)),
    response,
  };
}

async function readTransactions(path: string): Promise<FireflyTransactionImportRow[]> {
  let parsed: unknown;
  try {
    parsed = JSON.parse(await readFile(path, 'utf8'));
  } catch (error) {
    throw new FireflyInputError(
      `Could not read transaction import file at ${path}: ${
        error instanceof Error ? error.message : String(error)
      }`,
    );
  }

  const rows = Array.isArray(parsed) ? parsed : isRecord(parsed) ? parsed.transactions : undefined;
  if (!Array.isArray(rows)) {
    throw new FireflyInputError(
      'Transaction import file must be a JSON array or an object with a transactions array.',
    );
  }

  return rows.map((row, index) => normalizeImportRow(row, index + 1));
}

function normalizeImportRow(row: unknown, index: number): FireflyTransactionImportRow {
  if (!isRecord(row)) {
    throw new FireflyInputError(`Transaction import row ${index} must be an object.`);
  }

  return stripUndefined({
    type: stringValue(row.type),
    date: stringValue(row.date),
    source_id: stringValue(row.source_id ?? row.source),
    source_name: stringValue(row.source_name),
    destination_id: stringValue(row.destination_id ?? row.destination),
    destination_name: stringValue(row.destination_name ?? row.merchant),
    amount: stringValue(row.amount),
    description: stringValue(row.description),
    category_name: stringValue(row.category_name ?? row.category),
    notes: stringValue(row.notes),
    tags: Array.isArray(row.tags) ? row.tags.map((tag) => String(tag)) : undefined,
  });
}

async function fetchExistingTransactions(
  client: FireflyHttpClient,
  transactions: FireflyTransactionImportRow[],
): Promise<ExistingTransaction[]> {
  const dates = transactions
    .map((transaction) => transaction.date)
    .filter(isNonEmptyString)
    .map(dateOnly)
    .sort();
  if (dates.length === 0) {
    return [];
  }

  const response = await client.request('GET', '/api/v1/transactions', {
    query: {
      start: dates[0],
      end: dates.at(-1),
      limit: 500,
    },
  });

  return extractExistingTransactions(response);
}

function extractExistingTransactions(response: unknown): ExistingTransaction[] {
  if (!isRecord(response) || !Array.isArray(response.data)) {
    return [];
  }

  const transactions: ExistingTransaction[] = [];
  for (const group of response.data) {
    if (!isRecord(group)) {
      continue;
    }
    const groupId = stringValue(group.id);
    const attributes = isRecord(group.attributes) ? group.attributes : {};
    const nested = Array.isArray(attributes.transactions) ? attributes.transactions : [attributes];
    for (const item of nested) {
      if (!isRecord(item)) {
        continue;
      }
      transactions.push({
        id: groupId ?? stringValue(item.transaction_journal_id) ?? '',
        date: stringValue(item.date),
        source_id: stringValue(item.source_id),
        source_name: stringValue(item.source_name),
        destination_id: stringValue(item.destination_id),
        destination_name: stringValue(item.destination_name),
        amount: stringValue(item.amount),
        description: stringValue(item.description),
      });
    }
  }
  return transactions.filter((transaction) => transaction.id !== '');
}

function classifyTransaction(
  row: number,
  transaction: FireflyTransactionImportRow,
  existing: ExistingTransaction[],
): TransactionImportRowReport {
  const reasons = validateTransaction(transaction);
  const dates = extractDateMetadata(transaction);
  if (reasons.length > 0) {
    return { row, status: 'ambiguous', transaction, ...dates, reasons };
  }

  const duplicateIds = existing
    .filter((candidate) => isLikelyDuplicate(transaction, candidate))
    .map((candidate) => candidate.id);
  if (duplicateIds.length > 0) {
    return { row, status: 'duplicate', transaction, ...dates, duplicateIds };
  }

  return { row, status: 'create', transaction, ...dates };
}

function applyTimezone(
  transactions: FireflyTransactionImportRow[],
  timezone?: string,
): FireflyTransactionImportRow[] {
  if (!timezone) {
    return transactions;
  }
  return transactions.map((transaction) => {
    if (!transaction.date) {
      return transaction;
    }
    return {
      ...transaction,
      original_date: transaction.date,
      date: convertLocalDateToFireflyDate(transaction.date, timezone),
    };
  });
}

function buildFireflyTransaction(
  transaction: FireflyTransactionImportRow,
): FireflyTransactionImportRow {
  const fireflyTransaction = { ...transaction };
  delete fireflyTransaction.original_date;
  return fireflyTransaction;
}

function convertLocalDateToFireflyDate(value: string, timezone: string): string {
  const offset = timezoneOffset(timezone);
  const normalized = value.trim().replace(' ', 'T');
  if (/^\d{4}-\d{2}-\d{2}$/.test(normalized)) {
    return `${normalized}T00:00:00${offset}`;
  }
  if (/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(normalized)) {
    return `${normalized}:00${offset}`;
  }
  if (/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/.test(normalized)) {
    return `${normalized}${offset}`;
  }
  return value;
}

function timezoneOffset(timezone: string): string {
  if (timezone === 'Asia/Shanghai') {
    return '+08:00';
  }
  throw new FireflyInputError(
    `Unsupported timezone "${timezone}". Supported timezones: Asia/Shanghai.`,
  );
}

function extractDateMetadata(
  transaction: FireflyTransactionImportRow & { original_date?: string },
): Pick<TransactionImportRowReport, 'originalDate' | 'fireflyDate'> {
  if (!transaction.original_date) {
    return {};
  }
  return {
    originalDate: transaction.original_date,
    fireflyDate: transaction.date,
  };
}

function validateTransaction(transaction: FireflyTransactionImportRow): string[] {
  const reasons: string[] = [];
  if (!isNonEmptyString(transaction.type)) {
    reasons.push('type is required');
  }
  if (!isNonEmptyString(transaction.date)) {
    reasons.push('date is required');
  }
  if (!isNonEmptyString(transaction.amount)) {
    reasons.push('amount is required');
  }
  if (!isNonEmptyString(transaction.description)) {
    reasons.push('description is required');
  }
  if (!isNonEmptyString(transaction.source_id) && !isNonEmptyString(transaction.source_name)) {
    reasons.push('source_id or source_name is required');
  }
  if (
    !isNonEmptyString(transaction.destination_id) &&
    !isNonEmptyString(transaction.destination_name)
  ) {
    reasons.push('destination_id or destination_name is required');
  }
  return reasons;
}

function isLikelyDuplicate(
  transaction: FireflyTransactionImportRow,
  existing: ExistingTransaction,
): boolean {
  if (dateOnly(transaction.date) !== dateOnly(existing.date)) {
    return false;
  }
  if (normalizeAmount(transaction.amount) !== normalizeAmount(existing.amount)) {
    return false;
  }
  if (
    !matchesEither(
      transaction.source_id,
      existing.source_id,
      transaction.source_name,
      existing.source_name,
    )
  ) {
    return false;
  }
  const destinationMatches = matchesEither(
    transaction.destination_id,
    existing.destination_id,
    transaction.destination_name,
    existing.destination_name,
  );
  const descriptionMatches =
    normalizeText(transaction.description) === normalizeText(existing.description);
  return destinationMatches || descriptionMatches;
}

function matchesEither(
  leftId?: string,
  rightId?: string,
  leftName?: string,
  rightName?: string,
): boolean {
  if (isNonEmptyString(leftId) && isNonEmptyString(rightId)) {
    return leftId === rightId;
  }
  if (isNonEmptyString(leftName) && isNonEmptyString(rightName)) {
    return normalizeText(leftName) === normalizeText(rightName);
  }
  return true;
}

function summarize(rows: TransactionImportRowReport[]): TransactionImportReport['summary'] {
  return {
    total: rows.length,
    create: rows.filter((row) => row.status === 'create').length,
    duplicate: rows.filter((row) => row.status === 'duplicate').length,
    ambiguous: rows.filter((row) => row.status === 'ambiguous').length,
  };
}

function dateOnly(value?: string): string {
  return value?.slice(0, 10) ?? '';
}

function normalizeAmount(value?: string): string {
  if (!isNonEmptyString(value)) {
    return '';
  }
  const numeric = Number(value);
  return Number.isFinite(numeric) ? numeric.toFixed(2) : value;
}

function normalizeText(value?: string): string {
  return value?.trim().toLowerCase() ?? '';
}

function stringValue(value: unknown): string | undefined {
  if (value === undefined || value === null) {
    return undefined;
  }
  const stringified = String(value).trim();
  return stringified === '' ? undefined : stringified;
}

function isNonEmptyString(value: unknown): value is string {
  return typeof value === 'string' && value.trim() !== '';
}

function stripUndefined<T extends Record<string, unknown>>(input: T): T {
  return Object.fromEntries(Object.entries(input).filter(([, value]) => value !== undefined)) as T;
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}
