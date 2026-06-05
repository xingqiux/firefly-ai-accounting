import { createProgram } from '../../src/cli.js';

export interface CliRunResult {
  logs: string[];
  errors: string[];
}

export async function runCli(args: string[]): Promise<CliRunResult> {
  const logs: string[] = [];
  const errors: string[] = [];
  const originalLog = console.log;
  const originalError = console.error;
  console.log = (message?: unknown) => {
    logs.push(String(message ?? ''));
  };
  console.error = (message?: unknown) => {
    errors.push(String(message ?? ''));
  };
  try {
    const program = createProgram();
    program.exitOverride();
    await program.parseAsync(args, { from: 'user' });
    return { logs, errors };
  } finally {
    console.log = originalLog;
    console.error = originalError;
  }
}
