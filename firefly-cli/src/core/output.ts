import Table from 'cli-table3';

import type { OutputFormat } from './config-store.js';

export interface RenderOptions {
  format: OutputFormat;
  columns?: string[];
}

export function renderOutput(data: unknown, options: RenderOptions): string {
  if (options.format === 'json') {
    return JSON.stringify(data, null, 2);
  }
  if (options.format === 'raw') {
    return typeof data === 'string' ? data : JSON.stringify(data);
  }
  return renderTable(data, options.columns);
}

function renderTable(data: unknown, columns?: string[]): string {
  const rows = normalizeRows(data);
  if (rows.length === 0) {
    return '(empty)';
  }

  const resolvedColumns = columns && columns.length > 0 ? columns : inferColumns(rows);
  const table = new Table({ head: resolvedColumns });

  for (const row of rows) {
    table.push(resolvedColumns.map((column) => formatCell(row[column])));
  }

  return table.toString();
}

function normalizeRows(data: unknown): Array<Record<string, unknown>> {
  if (Array.isArray(data)) {
    return data.map(flattenItem);
  }
  if (isRecord(data) && Array.isArray(data.data)) {
    return data.data.map(flattenItem);
  }
  if (isRecord(data)) {
    return [flattenItem(data)];
  }
  if (data === undefined || data === null) {
    return [];
  }
  return [{ value: data }];
}

function flattenItem(item: unknown): Record<string, unknown> {
  if (!isRecord(item)) {
    return { value: item };
  }
  if (isRecord(item.attributes)) {
    return { id: item.id, ...item.attributes };
  }
  return item;
}

function inferColumns(rows: Array<Record<string, unknown>>): string[] {
  const columns = new Set<string>();
  for (const row of rows) {
    for (const key of Object.keys(row)) {
      columns.add(key);
    }
  }
  return [...columns].slice(0, 8);
}

function formatCell(value: unknown): string {
  if (value === undefined || value === null) {
    return '';
  }
  if (typeof value === 'object') {
    return JSON.stringify(value);
  }
  return String(value);
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}
