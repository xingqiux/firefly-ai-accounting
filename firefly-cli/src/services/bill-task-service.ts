import type { FireflyHttpClient } from '../core/http-client.js';

const ENDPOINT = '/api/v1/bill-tasks';

export class BillTaskService {
  constructor(private readonly client: FireflyHttpClient) {}

  list(): Promise<unknown> {
    return this.client.request('GET', ENDPOINT);
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
}

function taskPath(taskId: string): string {
  return `${ENDPOINT}/${encodeURIComponent(taskId)}`;
}
