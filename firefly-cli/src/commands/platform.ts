import { writeFile } from 'node:fs/promises';

import { Command } from 'commander';

import { createCommandContext } from '../core/command-context.js';
import { collectOption, parseKeyValueOptions } from '../core/key-value.js';
import { renderOutput } from '../core/output.js';
import { parseBodyOptions } from '../core/request-body.js';
import { ResourceService } from '../services/resource-service.js';

interface UserBodyOptions {
  email?: string;
  password?: string;
  json?: string;
  body?: string;
  set?: string[];
}

interface ExportOptions {
  output: string;
}

export function registerPlatformCommands(program: Command): void {
  registerAdminCommands(program);
  registerConfigCommands(program);
  registerDataCommands(program);
  registerCronCommands(program);
}

function registerAdminCommands(program: Command): void {
  const admin = program.command('admin').description('Run Firefly III admin operations.');
  const users = admin.command('users').description('Manage Firefly III users.');
  const endpoint = '/api/v1/users';

  users
    .command('list')
    .description('List users.')
    .action(async function () {
      const context = await createCommandContext(this);
      const result = await new ResourceService(context.client, { endpoint }).list();
      console.log(renderOutput(result, { format: context.format }));
    });

  users
    .command('get')
    .description('Show a user.')
    .argument('<id>', 'User identifier.')
    .action(async function (id: string) {
      const context = await createCommandContext(this);
      const result = await new ResourceService(context.client, { endpoint }).get(id);
      console.log(renderOutput(result, { format: context.format }));
    });

  const create = users.command('create').description('Create a user.');
  addUserBodyFlags(create);
  create.action(async function (options: UserBodyOptions) {
    const context = await createCommandContext(this);
    const result = await new ResourceService(context.client, { endpoint }).create(
      await buildUserBody(options),
    );
    console.log(renderOutput(result, { format: context.format }));
  });

  const update = users
    .command('update')
    .description('Update a user.')
    .argument('<id>', 'User identifier.');
  addUserBodyFlags(update);
  update.action(async function (id: string, options: UserBodyOptions) {
    const context = await createCommandContext(this);
    const result = await new ResourceService(context.client, { endpoint }).update(
      id,
      await buildUserBody(options),
    );
    console.log(renderOutput(result, { format: context.format }));
  });

  users
    .command('delete')
    .description('Delete a user.')
    .argument('<id>', 'User identifier.')
    .action(async function (id: string) {
      const context = await createCommandContext(this);
      const result = await new ResourceService(context.client, { endpoint }).delete(id);
      console.log(renderOutput(result, { format: context.format }));
    });
}

function registerConfigCommands(program: Command): void {
  const config = program.command('config').description('Read or update Firefly III configuration.');
  const endpoint = '/api/v1/configuration';

  config
    .command('list')
    .description('List configuration values.')
    .action(async function () {
      const context = await createCommandContext(this);
      const result = await context.client.request('GET', endpoint);
      console.log(renderOutput(result, { format: context.format }));
    });

  config
    .command('get')
    .description('Show one configuration value.')
    .argument('<key>', 'Configuration key.')
    .action(async function (key: string) {
      const context = await createCommandContext(this);
      const result = await context.client.request('GET', `${endpoint}/${encodeURIComponent(key)}`);
      console.log(renderOutput(result, { format: context.format }));
    });

  config
    .command('set')
    .description('Set one configuration value.')
    .argument('<key>', 'Configuration key.')
    .argument('<value>', 'Configuration value.')
    .action(async function (key: string, value: string) {
      const context = await createCommandContext(this);
      const result = await context.client.request('PUT', `${endpoint}/${encodeURIComponent(key)}`, {
        json: { value },
      });
      console.log(renderOutput(result, { format: context.format }));
    });
}

function registerDataCommands(program: Command): void {
  const data = program.command('data').description('Run Firefly III data operations.');
  const exportCommand = data.command('export').description('Export Firefly III data.');

  exportCommand
    .command('transactions')
    .description('Export transactions.')
    .requiredOption('--output <file>', 'Output file path.')
    .action(async function (options: ExportOptions) {
      const context = await createCommandContext(this);
      const result = await context.client.request('GET', '/api/v1/data/export/transactions');
      await writeFile(options.output, `${JSON.stringify(result, null, 2)}\n`, 'utf8');
      console.log(renderOutput({ output: options.output }, { format: context.format }));
    });
}

function registerCronCommands(program: Command): void {
  const cron = program.command('cron').description('Run Firefly III cron operations.');

  cron
    .command('run')
    .description('Trigger Firefly III cron using the configured CLI token.')
    .requiredOption('--token <token>', 'Firefly III cron CLI token.')
    .action(async function (options: { token: string }) {
      const context = await createCommandContext(this);
      const result = await context.client.request(
        'GET',
        `/api/v1/cron/${encodeURIComponent(options.token)}`,
      );
      console.log(renderOutput(result, { format: context.format }));
    });
}

function addUserBodyFlags(command: Command): void {
  command
    .option('--email <email>', 'User email address.')
    .option('--password <password>', 'User password.')
    .option('--json <json>', 'Inline JSON request body.')
    .option('--body <file>', 'Read JSON request body from file.')
    .option('--set <key=value>', 'Set a JSON field. Repeatable.', collectOption, []);
}

async function buildUserBody(options: UserBodyOptions): Promise<unknown> {
  const parsed = await parseBodyOptions(options);
  if (parsed.json !== undefined) {
    return parsed.json;
  }
  return {
    ...stripUndefined({
      email: options.email,
      password: options.password,
    }),
    ...parseKeyValueOptions(options.set),
  };
}

function stripUndefined(input: Record<string, unknown>): Record<string, unknown> {
  return Object.fromEntries(Object.entries(input).filter(([, value]) => value !== undefined));
}
