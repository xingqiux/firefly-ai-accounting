import { describe, expect, test } from 'vitest';

import { endpointCatalog } from '../../src/schemas/endpoint-catalog.js';

describe('endpoint catalog', () => {
  test('includes the MVP resource and platform endpoints', () => {
    const paths = endpointCatalog.map((endpoint) => `${endpoint.method} ${endpoint.path}`);

    expect(paths).toContain('GET /api/v1/accounts');
    expect(paths).toContain('POST /api/v1/transactions');
    expect(paths).toContain('POST /api/v1/webhooks/{webhook}/submit');
    expect(paths).toContain('GET /api/v1/users');
    expect(paths).toContain('PUT /api/v1/configuration/{dynamicConfigKey}');
    expect(paths).toContain('GET /api/v1/data/export/transactions');
    expect(paths).toContain('GET /api/v1/cron/{cliToken}');
  });

  test('marks admin-only user endpoints', () => {
    const usersEndpoint = endpointCatalog.find(
      (endpoint) => endpoint.method === 'GET' && endpoint.path === '/api/v1/users',
    );

    expect(usersEndpoint?.admin).toBe(true);
  });
});
