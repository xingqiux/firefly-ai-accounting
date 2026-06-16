# Bill Inbox CLI Optimization Design

## Goal

Make `ffc bill-inbox` usable as the command-line control surface for the full bill inbox workflow: configure mailbox access, sync Alipay and WeChat bill emails, process queued tasks, submit secrets, inspect artifacts, edit parsed rows, and import rows into Firefly III.

## Scope

This design adds API-backed CLI operations for workflow steps that currently exist only in the web UI or artisan commands. It does not change the source-channel parsing rules, statement parsers, transaction import payload mapping, or mailbox client internals.

## Commands

- `ffc bill-inbox settings show`
  - Calls `GET /api/v1/bill-inbox/settings`.
  - Returns enabled status, provider, mailbox identity, server fields, password presence, and built-in source channels.
- `ffc bill-inbox settings set`
  - Calls `PUT /api/v1/bill-inbox/settings`.
  - Supports `--enabled`, `--disabled`, `--provider`, `--email`, `--host`, `--port`, `--encryption`, `--username`, `--password`, and `--folder`.
  - Uses the same Gmail defaults as the web settings form.
- `ffc bill-inbox sync --limit <n>`
  - Calls `POST /api/v1/bill-inbox/sync`.
  - Syncs configured mailbox messages and immediately advances processable tasks.
- `ffc bill-inbox process --limit <n>`
  - Calls `POST /api/v1/bill-inbox/process`.
  - Advances queued `received` and `ready` tasks without scanning the mailbox.
- `ffc bill-inbox cleanup-stale`
  - Calls `POST /api/v1/bill-inbox/cleanup-stale`.
  - Archives stale `needs_secret` tasks for the current user.
- `ffc bill-inbox artifact download <artifactId> --output <file>`
  - Calls `GET /api/v1/bill-artifacts/{id}/download`.
  - Writes the downloaded artifact to the requested local path.

Existing task commands remain unchanged: `list`, `show`, `rows`, `row show`, `row update`, `import`, `archive`, `artifacts`, `events`, `ignore`, `retry`, and `secret submit`.

## API

Add a small `BillInboxController` under the existing bill task API namespace or a neighboring API namespace. It should reuse existing services:

- `BillMailboxSyncService::syncForUser`
- `BillTaskProcessor::processBatch`
- `BillTaskActionService::cleanupStale`
- `BillSourceChannelRegistry::settingsChannels`
- `Preferences` for mailbox settings

Responses should be JSON objects shaped for CLI table/json rendering:

- Settings responses return `data.type = bill-inbox-settings`.
- Sync responses return `data.type = bill-inbox-sync-result` with scanned, created, ignored, duplicates, failed, processed, process_failed, and errors.
- Process responses return processed and failed.
- Cleanup responses return archived.

## Data Flow

The command-line flow becomes:

1. User saves mailbox settings with `settings set`.
2. User runs `sync`; the backend searches built-in Alipay and WeChat criteria.
3. Backend creates `BillTask` records and artifacts.
4. Backend processes received tasks into `needs_secret`, including WeChat remote ZIP download.
5. User submits the Alipay or WeChat password with `secret submit`.
6. Backend parses the statement and creates editable rows.
7. User inspects, edits, and imports rows from the CLI.

## Error Handling

CLI validation catches mutually exclusive or missing options before making requests. Backend validation rejects invalid mailbox settings and limit values. API errors use existing `FireflyHttpError` behavior, so CLI displays the server message.

Artifact downloads use the existing HTTP client with raw response support so binary and text artifacts are not forced through JSON parsing.

## Testing

Use TDD for each behavior:

- CLI tests verify exact HTTP method, URL, query, JSON body, and file output.
- API integration tests verify settings, sync, process, cleanup, and artifact download endpoints.
- Existing channel tests continue to cover Alipay attachment flow and WeChat remote download/password flow.
