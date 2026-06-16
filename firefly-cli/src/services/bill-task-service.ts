import type { FireflyHttpClient } from '../core/http-client.js';

const ENDPOINT = '/api/v1/bill-tasks';
const ROW_ENDPOINT = '/api/v1/bill-statement-rows';

export interface BillTaskListFilters {
  source?: string;
  status?: string;
}

export interface BillStatementRowFilters {
  status?: string;
  from?: string;
  to?: string;
}

export interface BillStatementImportOptions {
  all?: boolean;
  rowIds?: number[];
  confirm?: boolean;
}

export class BillTaskService {
  constructor(private readonly client: FireflyHttpClient) {}

  list(filters: BillTaskListFilters = {}): Promise<unknown> {
    return this.client.request('GET', ENDPOINT, { query: filters });
  }

  show(taskId: string): Promise<unknown> {
    return this.client.request('GET', taskPath(taskId));
  }

  artifacts(taskId: string): Promise<unknown> {
    return this.client.request('GET', `${taskPath(taskId)}/artifacts`);
  }

  events(taskId: string): Promise<unknown> {
    return this.client.request('GET', `${taskPath(taskId)}/events`);
  }

  submitSecret(taskId: string, value: string): Promise<unknown> {
    return this.client.request('POST', `${taskPath(taskId)}/secret`, { json: { value } });
  }

  ignore(taskId: string): Promise<unknown> {
    return this.client.request('POST', `${taskPath(taskId)}/ignore`, { json: {} });
  }

  retry(taskId: string): Promise<unknown> {
    return this.client.request('POST', `${taskPath(taskId)}/retry`, { json: {} });
  }

  rows(taskId: string, filters: BillStatementRowFilters = {}): Promise<unknown> {
    return this.client.request('GET', `${taskPath(taskId)}/rows`, { query: filters });
  }

  showRow(rowId: string): Promise<unknown> {
    return this.client.request('GET', rowPath(rowId));
  }

  updateRow(rowId: string, values: Record<string, string>): Promise<unknown> {
    return this.client.request('PATCH', rowPath(rowId), { json: values });
  }

  importRows(taskId: string, options: BillStatementImportOptions): Promise<unknown> {
    const payload: Record<string, unknown> = {
      confirm: options.confirm ?? false,
    };
    if (options.all) {
      payload.all = true;
    } else {
      payload.row_ids = options.rowIds ?? [];
    }

    return this.client.request('POST', `${taskPath(taskId)}/import`, { json: payload });
  }

  archive(taskId: string): Promise<unknown> {
    return this.client.request('POST', `${taskPath(taskId)}/archive`, { json: {} });
  }

  archiveMany(ids: number[]): Promise<unknown> {
    return this.client.request('POST', `${ENDPOINT}/archive`, { json: { ids } });
  }
}

function taskPath(taskId: string): string {
  return `${ENDPOINT}/${encodeURIComponent(taskId)}`;
}

function rowPath(rowId: string): string {
  return `${ROW_ENDPOINT}/${encodeURIComponent(rowId)}`;
}
