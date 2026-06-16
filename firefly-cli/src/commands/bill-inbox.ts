import { Command } from 'commander';

import { createCommandContext } from '../core/command-context.js';
import { FireflyInputError } from '../core/errors.js';
import { renderOutput } from '../core/output.js';
import { BillTaskService } from '../services/bill-task-service.js';

interface SecretSubmitOptions {
  value?: string;
}

interface BillTaskListOptions {
  source?: string;
  status?: string;
}

interface BillStatementRowListOptions {
  status?: string;
  from?: string;
  to?: string;
}

interface BillStatementRowUpdateOptions {
  set?: string[];
}

interface BillStatementImportCommandOptions {
  all?: boolean;
  rows?: string;
  confirm?: boolean;
}

interface BillTaskArchiveOptions {
  ids?: string;
}

export function registerBillInboxCommands(program: Command): void {
  const bills = program
    .command('bill-inbox')
    .description('Manage Firefly bill email ingestion tasks.');

  bills
    .command('list')
    .description('List bill inbox tasks.')
    .option('--source <source>', 'Filter tasks by source, for example alipay.')
    .option('--status <status>', 'Filter tasks by status, for example parsed.')
    .action(async function (options: BillTaskListOptions) {
      const context = await createCommandContext(this);
      const service = new BillTaskService(context.client);
      const tasks = await service.list({
        source: blankToUndefined(options.source),
        status: blankToUndefined(options.status),
      });
      console.log(renderOutput(tasks, { format: context.format }));
    });

  bills
    .command('show')
    .description('Show a bill inbox task with related records.')
    .argument('<taskId>', 'Bill task identifier.')
    .action(async function (taskId: string) {
      const context = await createCommandContext(this);
      const service = new BillTaskService(context.client);
      const detail = await service.show(taskId);
      console.log(renderOutput(detail, { format: context.format }));
    });

  bills
    .command('rows')
    .description('List parsed statement rows for a bill inbox task.')
    .argument('<taskId>', 'Bill task identifier.')
    .option('--status <status>', 'Filter rows by import status.')
    .option('--from <date>', 'Only include rows on or after YYYY-MM-DD.')
    .option('--to <date>', 'Only include rows on or before YYYY-MM-DD.')
    .action(async function (taskId: string, options: BillStatementRowListOptions) {
      const context = await createCommandContext(this);
      const service = new BillTaskService(context.client);
      const rows = await service.rows(taskId, {
        status: blankToUndefined(options.status),
        from: blankToUndefined(options.from),
        to: blankToUndefined(options.to),
      });
      console.log(renderOutput(rows, { format: context.format }));
    });

  const row = bills.command('row').description('Inspect or edit a parsed statement row.');
  row
    .command('show')
    .description('Show one parsed statement row with all fields.')
    .argument('<rowId>', 'Statement row identifier.')
    .action(async function (rowId: string) {
      const context = await createCommandContext(this);
      const service = new BillTaskService(context.client);
      const detail = await service.showRow(rowId);
      console.log(renderOutput(detail, { format: context.format }));
    });

  row
    .command('update')
    .description('Update editable statement row and Firefly draft fields.')
    .argument('<rowId>', 'Statement row identifier.')
    .requiredOption('--set <field=value>', 'Set a row field. Repeatable.', collect, [])
    .action(async function (rowId: string, options: BillStatementRowUpdateOptions) {
      const context = await createCommandContext(this);
      const service = new BillTaskService(context.client);
      const result = await service.updateRow(rowId, parseSetValues(options.set ?? []));
      console.log(renderOutput(result, { format: context.format }));
    });

  bills
    .command('import')
    .description('Import parsed statement rows into Firefly transactions.')
    .argument('<taskId>', 'Bill task identifier.')
    .option('--all', 'Import all importable rows for the task.')
    .option('--rows <ids>', 'Comma-separated statement row IDs to import.')
    .option('--confirm', 'Actually create Firefly transactions. Without this, performs a dry run.')
    .action(async function (taskId: string, options: BillStatementImportCommandOptions) {
      if (options.all && options.rows) {
        throw new FireflyInputError('Use either --all or --rows, not both.');
      }
      if (!options.all && !options.rows) {
        throw new FireflyInputError('Pass --all or --rows to choose statement rows.');
      }

      const context = await createCommandContext(this);
      const service = new BillTaskService(context.client);
      const result = await service.importRows(taskId, {
        all: options.all,
        rowIds: options.rows ? parseIdList(options.rows, '--rows') : undefined,
        confirm: options.confirm ?? false,
      });
      console.log(renderOutput(result, { format: context.format }));
    });

  bills
    .command('archive')
    .description('Archive one or many bill inbox tasks without deleting mail or artifacts.')
    .argument('[taskId]', 'Bill task identifier.')
    .option('--ids <ids>', 'Comma-separated task IDs to archive.')
    .action(async function (taskId: string | undefined, options: BillTaskArchiveOptions) {
      if (taskId && options.ids) {
        throw new FireflyInputError('Pass either a task ID or --ids, not both.');
      }
      if (!taskId && !options.ids) {
        throw new FireflyInputError('Pass a task ID or --ids to archive tasks.');
      }

      const context = await createCommandContext(this);
      const service = new BillTaskService(context.client);
      const result = taskId
        ? await service.archive(taskId)
        : await service.archiveMany(parseIdList(String(options.ids), '--ids'));
      console.log(renderOutput(result, { format: context.format }));
    });

  bills
    .command('artifacts')
    .description('List artifacts for a bill inbox task.')
    .argument('<taskId>', 'Bill task identifier.')
    .action(async function (taskId: string) {
      const context = await createCommandContext(this);
      const service = new BillTaskService(context.client);
      const artifacts = await service.artifacts(taskId);
      console.log(renderOutput(artifacts, { format: context.format }));
    });

  bills
    .command('events')
    .description('List event log entries for a bill inbox task.')
    .argument('<taskId>', 'Bill task identifier.')
    .action(async function (taskId: string) {
      const context = await createCommandContext(this);
      const service = new BillTaskService(context.client);
      const events = await service.events(taskId);
      console.log(renderOutput(events, { format: context.format }));
    });

  bills
    .command('ignore')
    .description('Mark a bill inbox task as ignored.')
    .argument('<taskId>', 'Bill task identifier.')
    .action(async function (taskId: string) {
      const context = await createCommandContext(this);
      const service = new BillTaskService(context.client);
      const result = await service.ignore(taskId);
      console.log(renderOutput(result, { format: context.format }));
    });

  bills
    .command('retry')
    .description('Requeue a bill inbox task for backend processing.')
    .argument('<taskId>', 'Bill task identifier.')
    .action(async function (taskId: string) {
      const context = await createCommandContext(this);
      const service = new BillTaskService(context.client);
      const result = await service.retry(taskId);
      console.log(renderOutput(result, { format: context.format }));
    });

  const secret = bills.command('secret').description('Manage bill inbox secret challenges.');
  secret
    .command('submit')
    .description('Submit a password or code for the current task challenge.')
    .argument('<taskId>', 'Bill task identifier.')
    .requiredOption('--value <secret>', 'Secret value to use for this processing attempt.')
    .action(async function (taskId: string, options: SecretSubmitOptions) {
      if (!options.value) {
        throw new FireflyInputError('Pass --value to submit a secret.');
      }
      const context = await createCommandContext(this);
      const service = new BillTaskService(context.client);
      const result = await service.submitSecret(taskId, options.value);
      console.log(renderOutput(result, { format: context.format }));
    });
}

function collect(value: string, previous: string[]): string[] {
  previous.push(value);
  return previous;
}

function parseSetValues(values: string[]): Record<string, string> {
  const parsed: Record<string, string> = {};
  for (const value of values) {
    const separator = value.indexOf('=');
    if (separator <= 0) {
      throw new FireflyInputError(`Invalid --set value "${value}". Use field=value.`);
    }
    const key = value.slice(0, separator).trim();
    const fieldValue = value.slice(separator + 1);
    if (key === '') {
      throw new FireflyInputError(`Invalid --set value "${value}". Field name is empty.`);
    }
    parsed[key] = fieldValue;
  }
  return parsed;
}

function parseIdList(value: string, option: string): number[] {
  const ids = value
    .split(',')
    .map((item) => Number(item.trim()))
    .filter((item) => Number.isInteger(item) && item > 0);
  if (ids.length === 0) {
    throw new FireflyInputError(`Pass at least one numeric ID to ${option}.`);
  }
  return ids;
}

function blankToUndefined(value?: string): string | undefined {
  return value && value.trim() !== '' ? value : undefined;
}
