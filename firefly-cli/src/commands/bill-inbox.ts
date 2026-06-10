import { Command } from 'commander';

import { FireflyInputError } from '../core/errors.js';
import { renderOutput } from '../core/output.js';
import { BillInboxStore } from '../services/bill-inbox-store.js';

interface SecretSubmitOptions {
  value?: string;
}

export function registerBillInboxCommands(program: Command): void {
  const bills = program
    .command('bill-inbox')
    .description('Manage local bill email ingestion tasks.');

  bills
    .command('list')
    .description('List bill inbox tasks.')
    .action(async function () {
      const store = new BillInboxStore();
      const globals = this.optsWithGlobals();
      const tasks = await store.listTasks();
      console.log(
        renderOutput(
          tasks.map((task) => ({
            id: task.id,
            source: task.source,
            profileId: task.profileId,
            status: task.status,
            receivedAt: task.receivedAt,
            summary: task.summary,
          })),
          { format: globals.format ?? 'table' },
        ),
      );
    });

  bills
    .command('show')
    .description('Show a bill inbox task with related records.')
    .argument('<taskId>', 'Bill task identifier.')
    .action(async function (taskId: string) {
      const store = new BillInboxStore();
      const globals = this.optsWithGlobals();
      const detail = await store.getTaskDetail(taskId);
      console.log(renderOutput(detail, { format: globals.format ?? 'table' }));
    });

  bills
    .command('artifacts')
    .description('List artifacts for a bill inbox task.')
    .argument('<taskId>', 'Bill task identifier.')
    .action(async function (taskId: string) {
      const store = new BillInboxStore();
      const globals = this.optsWithGlobals();
      const artifacts = await store.listArtifacts(taskId);
      console.log(renderOutput(artifacts, { format: globals.format ?? 'table' }));
    });

  bills
    .command('events')
    .description('List event log entries for a bill inbox task.')
    .argument('<taskId>', 'Bill task identifier.')
    .action(async function (taskId: string) {
      const store = new BillInboxStore();
      const globals = this.optsWithGlobals();
      const events = await store.listEvents(taskId);
      console.log(renderOutput(events, { format: globals.format ?? 'table' }));
    });

  bills
    .command('ignore')
    .description('Mark a bill inbox task as ignored.')
    .argument('<taskId>', 'Bill task identifier.')
    .action(async function (taskId: string) {
      const store = new BillInboxStore();
      const globals = this.optsWithGlobals();
      const result = await store.ignoreTask(taskId);
      console.log(renderOutput(result, { format: globals.format ?? 'table' }));
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
      const store = new BillInboxStore();
      const globals = this.optsWithGlobals();
      const result = await store.submitSecret(taskId, options.value);
      console.log(renderOutput(result, { format: globals.format ?? 'table' }));
    });
}
