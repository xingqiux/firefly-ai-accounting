import { Command } from 'commander';

import { createCommandContext } from '../core/command-context.js';
import { FireflyInputError } from '../core/errors.js';
import { renderOutput } from '../core/output.js';
import { BillTaskService } from '../services/bill-task-service.js';

interface SecretSubmitOptions {
  value?: string;
}

export function registerBillInboxCommands(program: Command): void {
  const bills = program
    .command('bill-inbox')
    .description('Manage Firefly bill email ingestion tasks.');

  bills
    .command('list')
    .description('List bill inbox tasks.')
    .action(async function () {
      const context = await createCommandContext(this);
      const service = new BillTaskService(context.client);
      const tasks = await service.list();
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
