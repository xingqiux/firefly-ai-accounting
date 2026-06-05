export type EndpointMethod = 'GET' | 'POST' | 'PUT' | 'DELETE';

export interface EndpointCatalogEntry {
  method: EndpointMethod;
  path: string;
  module: string;
  routeName: string;
  admin?: boolean;
}

export const endpointCatalog: EndpointCatalogEntry[] = [
  { method: 'GET', path: '/api/v1/about', module: 'about', routeName: 'api.v1.about.index' },
  {
    method: 'GET',
    path: '/api/v1/about/user',
    module: 'about',
    routeName: 'api.v1.about.user',
  },
  ...crud('accounts', '/api/v1/accounts', 'account'),
  ...crud('budgets', '/api/v1/budgets', 'budget'),
  ...crud('categories', '/api/v1/categories', 'category'),
  ...crud('tags', '/api/v1/tags', 'tagOrId'),
  ...crud('bills', '/api/v1/bills', 'bill'),
  ...crud('currencies', '/api/v1/currencies', 'currency_code'),
  ...crud('transactions', '/api/v1/transactions', 'transactionGroup'),
  ...crud('webhooks', '/api/v1/webhooks', 'webhook'),
  {
    method: 'POST',
    path: '/api/v1/webhooks/{webhook}/submit',
    module: 'webhooks',
    routeName: 'api.v1.webhooks.submit',
  },
  ...crud('users', '/api/v1/users', 'user', true),
  {
    method: 'GET',
    path: '/api/v1/configuration',
    module: 'configuration',
    routeName: 'api.v1.configuration.index',
  },
  {
    method: 'GET',
    path: '/api/v1/configuration/{eitherConfigKey}',
    module: 'configuration',
    routeName: 'api.v1.configuration.show',
  },
  {
    method: 'PUT',
    path: '/api/v1/configuration/{dynamicConfigKey}',
    module: 'configuration',
    routeName: 'api.v1.configuration.update',
  },
  {
    method: 'GET',
    path: '/api/v1/data/export/transactions',
    module: 'data',
    routeName: 'api.v1.data.export.transactions',
  },
  {
    method: 'GET',
    path: '/api/v1/cron/{cliToken}',
    module: 'cron',
    routeName: 'api.v1.cron.index',
  },
];

function crud(
  module: string,
  collectionPath: string,
  routeParameter: string,
  admin = false,
): EndpointCatalogEntry[] {
  const itemPath = `${collectionPath}/{${routeParameter}}`;
  return [
    {
      method: 'GET',
      path: collectionPath,
      module,
      routeName: `api.v1.${module}.index`,
      admin,
    },
    {
      method: 'POST',
      path: collectionPath,
      module,
      routeName: `api.v1.${module}.store`,
      admin,
    },
    {
      method: 'GET',
      path: itemPath,
      module,
      routeName: `api.v1.${module}.show`,
      admin,
    },
    {
      method: 'PUT',
      path: itemPath,
      module,
      routeName: `api.v1.${module}.update`,
      admin,
    },
    {
      method: 'DELETE',
      path: itemPath,
      module,
      routeName: `api.v1.${module}.delete`,
      admin,
    },
  ];
}
