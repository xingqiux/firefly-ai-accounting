export function parseQueryOptions(values: string[] = []): Record<string, string | string[]> {
  const query: Record<string, string | string[]> = {};
  for (const value of values) {
    const separator = value.indexOf('=');
    const key = separator === -1 ? value : value.slice(0, separator);
    const parsed = separator === -1 ? '' : value.slice(separator + 1);
    const existing = query[key];
    if (existing === undefined) {
      query[key] = parsed;
    } else if (Array.isArray(existing)) {
      existing.push(parsed);
    } else {
      query[key] = [existing, parsed];
    }
  }
  return query;
}
