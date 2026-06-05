import { FireflyInputError } from './errors.js';

export function collectOption(value: string, previous: string[]): string[] {
  previous.push(value);
  return previous;
}

export function parseKeyValueOptions(values: string[] = []): Record<string, string> {
  const parsed: Record<string, string> = {};
  for (const value of values) {
    const separator = value.indexOf('=');
    if (separator <= 0) {
      throw new FireflyInputError(`Expected key=value, received "${value}".`);
    }
    parsed[value.slice(0, separator)] = value.slice(separator + 1);
  }
  return parsed;
}
