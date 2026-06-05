import type { QueryValue } from './http-client.js';

export interface ListOptions {
  page?: string | number;
  limit?: string | number;
  sort?: string;
  filter?: string[];
}

export function buildListQuery(options: ListOptions): Record<string, QueryValue> {
  return {
    page: options.page,
    limit: options.limit,
    sort: options.sort,
    filter: options.filter,
  };
}
