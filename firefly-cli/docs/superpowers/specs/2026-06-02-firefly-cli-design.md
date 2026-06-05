# Firefly CLI Design

## Context

Build a new standalone `ffc` command-line tool in `/Users/youla/proj/firefly-cli`. The CLI will operate against Firefly III through its existing HTTP APIs. It will not modify the Firefly III Laravel application.

The source Firefly III workspace at `/Users/youla/proj/firefly-iii` currently exposes 244 `/api/v1` routes, summarized in `/Users/youla/proj/firefly-iii/doc/api-endpoints.md`. Firefly III uses Laravel Passport API authentication for `/api/v1` endpoints. API clients should send `Authorization: Bearer <token>`, `Accept: application/json`, and `Content-Type: application/json` for JSON `POST` and `PUT` requests. Some routes require owner/admin privileges through the `api-admin` middleware.

## Product Direction

The first version will combine two goals:

1. A resource-oriented CLI for common Firefly III workflows.
2. A platform/operations CLI for authentication, health checks, admin actions, configuration, export, cron, and webhooks.

The CLI will use a hand-written command experience for core resources instead of generating a mechanical command for all 244 endpoints. A generic `ffc api` command will provide access to any endpoint that does not yet have a dedicated command.

## Technology

Use Node.js and TypeScript.

Recommended libraries:

- `commander` for command routing.
- Native `fetch` or `undici` for HTTP.
- `cli-table3` for table output.
- `prompts` or `inquirer` for optional interactive prompts.
- `vitest` for tests.
- `eslint` and `prettier` for quality.
- `tsup` or `tsx` plus `tsc` for local development and packaging.

## Architecture

The project will be organized into clear layers:

- Command layer: parses CLI commands and flags.
- Service layer: exposes typed operations for Firefly resources and platform features.
- HTTP client layer: handles base URL, token, headers, request bodies, pagination, errors, and response normalization.
- Config/auth layer: stores profiles, base URLs, tokens, and defaults.
- Schema/docs layer: carries endpoint catalog information and leaves room for future OpenAPI integration.

Data flow:

```text
CLI args
  -> command parser
  -> service method
  -> HTTP client
  -> Firefly III /api/v1
  -> response normalizer
  -> table/json/raw renderer
```

## Commands

Authentication and status:

```bash
ffc auth set-token --url http://127.0.0.1:8000 --token <token>
ffc auth set-token --profile local --url http://127.0.0.1:8000 --token <token>
ffc auth use local
ffc auth status
ffc ping
ffc about
ffc me
```

Core resources:

```bash
ffc accounts list
ffc accounts get <id>
ffc accounts create --name "Cash" --type asset
ffc accounts update <id> --name "New name"
ffc accounts delete <id>

ffc transactions list
ffc transactions get <id>
ffc transactions create --type withdrawal --source 1 --destination 2 --amount 12.34 --description "Coffee"
ffc transactions update <id>
ffc transactions delete <id>
```

The same list/get/create/update/delete pattern will be implemented where appropriate for:

- `budgets`
- `categories`
- `tags`
- `bills`
- `currencies`
- `webhooks`

Platform and operations:

```bash
ffc admin users list
ffc admin users get <id>
ffc admin users create --email user@example.com --password <password>
ffc admin users update <id>
ffc admin users delete <id>

ffc config list
ffc config get <key>
ffc config set <key> <value>

ffc data export transactions --output transactions.json
ffc cron run --token <cliToken>
```

Generic endpoint access:

```bash
ffc api GET /api/v1/accounts
ffc api POST /api/v1/accounts --json '{"name":"Cash","type":"asset"}'
ffc api PUT /api/v1/accounts/1 --body account.json
ffc api DELETE /api/v1/accounts/1
```

All list commands should support common flags when the API endpoint accepts them:

```bash
--page 1
--limit 50
--sort name
--filter name=Cash
```

Output modes:

```bash
--format table
--format json
--format raw
```

## Authentication And Configuration

Use personal access token / bearer token authentication as the primary MVP flow. Users create a token in Firefly III and save it locally with `ffc auth set-token`.

Store config under the user config directory, for example:

```text
~/.config/firefly-cli/config.json
```

Config fields:

- `activeProfile`
- profile `baseUrl`
- profile `token`
- `defaultFormat`
- `timeout`

Security rules:

- Do not print full tokens in normal output or errors.
- `auth status` may show only token presence and a short suffix.
- Set config file permissions so only the current user can read/write when the OS supports it.
- Use a plain local config file for MVP; leave system keychain support for a later version.

## Firefly Platform Rules

The HTTP layer will follow these Firefly III rules:

- Send `Accept: application/json`.
- Send `Content-Type: application/json` for JSON `POST` and `PUT` requests.
- Send `Authorization: Bearer <token>` when a profile token is configured.
- Preserve Firefly III authorization behavior; admin commands must fail clearly when the token lacks owner privileges.
- Support an optional `X-Trace-Id` header for debugging.
- Respect API error responses instead of duplicating all Laravel validation rules client-side.

## Error Handling

Input errors:

- Missing required arguments.
- Invalid flag values.
- Unreadable `--body` files.
- Invalid local JSON.

Platform errors:

- `401`: missing or invalid token.
- `403`: authenticated but not authorized, commonly non-owner access to admin endpoints.
- `404`: resource or endpoint not found.
- `415`: invalid or missing content type.
- `422`: Firefly validation failure; show field-level messages.
- `500`: server error; show request method/path and correlation hints without exposing secrets.

Network errors:

- Firefly III is not running.
- Base URL is wrong.
- Request times out.
- TLS or certificate failure.

The CLI should make next steps clear, such as running `ffc auth status`, `ffc ping`, or checking the configured base URL.

## Testing

Unit tests:

- Config store read/write and profile switching.
- URL joining and query serialization.
- Header injection and token redaction.
- Error normalization.
- Table/json/raw output.

Command tests:

- `auth set-token`
- `ping`
- `about`
- `me`
- `accounts list`
- `transactions create`
- `api GET`

HTTP tests:

- Use a mock HTTP server or fetch mocking.
- Use fixtures based on representative Firefly III responses.

Optional smoke tests:

- Against a local Firefly III instance at `http://127.0.0.1:8000`.
- Cover `about`, `me`, and a read-only resource list.

## Proposed Project Structure

```text
firefly-cli/
  package.json
  tsconfig.json
  README.md
  src/
    cli.ts
    commands/
      auth.ts
      api.ts
      accounts.ts
      transactions.ts
      budgets.ts
      categories.ts
      tags.ts
      bills.ts
      currencies.ts
      webhooks.ts
      admin.ts
      config.ts
      data.ts
      cron.ts
    core/
      http-client.ts
      config-store.ts
      errors.ts
      output.ts
      pagination.ts
    services/
      accounts.ts
      transactions.ts
      budgets.ts
      categories.ts
      tags.ts
      bills.ts
      currencies.ts
      webhooks.ts
      admin-users.ts
      configuration.ts
    schemas/
      endpoint-catalog.ts
    tests/
      unit/
      commands/
      fixtures/
```

## MVP Milestones

1. Initialize the repository and TypeScript tooling.
2. Implement config, auth, and profile management.
3. Implement HTTP client, error normalization, output rendering, and pagination helpers.
4. Implement `ping`, `about`, `me`, and generic `api`.
5. Implement core resource commands for accounts, transactions, budgets, categories, tags, bills, and currencies.
6. Implement platform operations for admin users, configuration, data export, cron, and webhooks.
7. Write README usage examples and smoke test instructions.

## Acceptance Criteria

- A user can configure a Firefly III instance with `ffc auth set-token --url ... --token ...`.
- `ffc auth status`, `ffc ping`, `ffc about`, and `ffc me` work against a valid instance.
- Core resources can be listed, read, created, updated, and deleted where Firefly III supports those operations.
- `ffc api` can call any `/api/v1/*` endpoint with arbitrary method, JSON body, or body file.
- Admin-only commands show clear authorization errors when the token is not an owner token.
- Output can be rendered as table, JSON, or raw response.
- Core HTTP, config, output, and command behavior is covered by automated tests.
- README explains setup, token configuration, common commands, output formats, and troubleshooting.

## Deferred Work

- Automatic command generation for all 244 endpoints.
- Full OpenAPI type generation.
- System keychain storage.
- Shell completions.
- Interactive transaction creation wizard.
- Import workflows beyond Firefly III's existing API surface.
- Plugin architecture for custom commands.
