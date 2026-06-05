import { realpathSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

import { Command } from 'commander';

import { registerAuthCommands } from './commands/auth.js';
import { registerBaseCommands } from './commands/base.js';
import { registerPlatformCommands } from './commands/platform.js';
import { registerResourceCommands } from './commands/resources.js';
import type { OutputFormat } from './core/config-store.js';

export function createProgram(): Command {
  const program = new Command();
  program
    .name('ffc')
    .description('Command-line client for Firefly III.')
    .version('0.1.0')
    .option('--profile <name>', 'Config profile to use.')
    .option('--format <format>', 'Output format: table, json, raw.', parseFormat, 'table')
    .option('--config <file>', 'Path to config file.')
    .option('--trace-id <uuid>', 'Send X-Trace-Id header.')
    .option('--timeout <ms>', 'Request timeout in milliseconds.');
  registerAuthCommands(program);
  registerBaseCommands(program);
  registerResourceCommands(program);
  registerPlatformCommands(program);
  return program;
}

export async function main(argv = process.argv): Promise<void> {
  const program = createProgram();
  await program.parseAsync(argv);
}

if (isCliEntrypoint(import.meta.url, process.argv[1])) {
  main().catch((error: unknown) => {
    console.error(error instanceof Error ? error.message : String(error));
    process.exitCode = 1;
  });
}

function parseFormat(value: string): OutputFormat {
  if (value === 'table' || value === 'json' || value === 'raw') {
    return value;
  }
  throw new Error('Format must be one of: table, json, raw.');
}

export function isCliEntrypoint(moduleUrl: string, argvPath?: string): boolean {
  if (!argvPath) {
    return false;
  }
  return realpathSync(fileURLToPath(moduleUrl)) === realpathSync(argvPath);
}
