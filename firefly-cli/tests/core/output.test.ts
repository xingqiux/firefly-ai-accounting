import { describe, expect, test } from 'vitest';

import { renderOutput } from '../../src/core/output.js';

describe('renderOutput', () => {
  test('renders JSON output', () => {
    expect(renderOutput({ ok: true }, { format: 'json' })).toBe('{\n  "ok": true\n}');
  });

  test('renders raw strings', () => {
    expect(renderOutput('plain', { format: 'raw' })).toBe('plain');
  });

  test('renders empty arrays clearly', () => {
    expect(renderOutput([], { format: 'table' })).toBe('(empty)');
  });

  test('renders Firefly collection data as a table', () => {
    const output = renderOutput(
      {
        data: [
          { id: '1', attributes: { name: 'Cash', active: true } },
          { id: '2', attributes: { name: 'Bank', active: false } },
        ],
      },
      { format: 'table', columns: ['id', 'name', 'active'] },
    );

    expect(output).toContain('Cash');
    expect(output).toContain('Bank');
    expect(output).toContain('active');
  });
});
