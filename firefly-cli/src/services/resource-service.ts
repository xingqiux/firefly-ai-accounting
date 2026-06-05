import type { ListOptions } from '../core/pagination.js';
import { buildListQuery } from '../core/pagination.js';
import { parseKeyValueOptions } from '../core/key-value.js';
import type { FireflyHttpClient } from '../core/http-client.js';

export interface ResourceServiceOptions {
  endpoint: string;
  idSegment?: string;
}

export class ResourceService {
  constructor(
    private readonly client: FireflyHttpClient,
    private readonly options: ResourceServiceOptions,
  ) {}

  list(options: ListOptions = {}): Promise<unknown> {
    const { filter, ...listOptions } = options;
    return this.client.request('GET', this.options.endpoint, {
      query: {
        ...buildListQuery(listOptions),
        ...parseKeyValueOptions(filter),
      },
    });
  }

  get(id: string): Promise<unknown> {
    return this.client.request('GET', this.itemPath(id));
  }

  create(body: unknown): Promise<unknown> {
    return this.client.request('POST', this.options.endpoint, { json: body });
  }

  update(id: string, body: unknown): Promise<unknown> {
    return this.client.request('PUT', this.itemPath(id), { json: body });
  }

  delete(id: string): Promise<unknown> {
    return this.client.request('DELETE', this.itemPath(id));
  }

  post(path: string, body?: unknown): Promise<unknown> {
    return this.client.request('POST', `${this.options.endpoint}/${path.replace(/^\/+/, '')}`, {
      json: body,
    });
  }

  private itemPath(id: string): string {
    return `${this.options.endpoint}/${encodeURIComponent(id)}`;
  }
}
