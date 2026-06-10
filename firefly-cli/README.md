# Firefly CLI

Standalone command-line client for Firefly III.

The CLI talks to Firefly III through `/api/v1` using bearer-token authentication. It provides friendly commands for common resources and platform operations, plus `ffc api` as an escape hatch for endpoints that do not have a dedicated command yet.

## Install And Build

```bash
npm install
npm run build
```

## Global Development Link

Expose this development checkout as the global `ffc` command:

```bash
npm run build
npm link
ffc --help
```

The global npm link points back to this project directory, so rebuilding here updates the command you run globally. During active development, keep a watcher running in another terminal:

```bash
npm run build:watch
```

Run from source during development:

```bash
npm run dev -- --help
```

Run the built CLI:

```bash
node dist/cli.js --help
```

## Authentication

Create a personal access token in Firefly III, then store it in a local profile:

```bash
ffc auth set-token --profile local --url http://127.0.0.1:8000 --token <token>
ffc auth use local
ffc auth status
```

Config is stored at `~/.config/firefly-cli/config.json` by default. Use `FIREFLY_CLI_CONFIG` or `--config <file>` to override the path.

Global flags:

```bash
--profile <name>
--format table|json|raw
--config <file>
--trace-id <uuid>
--timeout <ms>
```

## Health And Identity

```bash
ffc ping
ffc about --format json
ffc me --format json
```

Local Firefly III diagnostics:

```bash
ffc doctor local --root ../firefly-iii --url http://127.0.0.1:8000 --format json
```

The local doctor checks the Firefly root, SQLite database path, `APP_URL`, `TZ`, v2 Vite assets, v1 transaction UI assets, frontpage account preferences, and HTTP reachability. This project expects local accounting data in `Asia/Shanghai`; if your source bills use another timezone, pass it explicitly during import. If the v1 or v2 assets are missing, run the suggested asset build command from `firefly-iii/` and hard refresh the browser.

## Resources

Supported resource commands:

```bash
ffc accounts list
ffc accounts get <id>
ffc accounts create --name "Cash" --type asset
ffc accounts update <id> --name "Wallet" --set order=1
ffc accounts delete <id>
```

Personal finance shortcuts for account setup:

```bash
ffc accounts create --asset --name 微信钱包 --balance 798 --currency CNY
ffc accounts create --liability --name 花呗 --debt 2026.24 --liability-type debt
ffc accounts create --liability --name 助学贷款 --debt 56000 --liability-type loan --notes "2022-08-08 12000; 2023-08-04 12000"
```

Shortcut account creation sends Firefly payloads with sensible defaults: asset accounts use `account_role=defaultAsset`; liability accounts use `liability_direction=debit`, `interest=0`, and monthly interest periods unless `--liability-type loan` is used. `--currency` defaults to `CNY`, and `--date` defaults to today.

The same CRUD shape is available for:

```text
budgets
categories
tags
bills
currencies
webhooks
transactions
```

List commands support common query options:

```bash
ffc accounts list --page 1 --limit 50 --sort name --filter type=asset
```

For full Firefly payloads, pass inline JSON or a JSON file:

```bash
ffc budgets create --json '{"name":"Groceries"}'
ffc bills update 12 --body bill.json
```

Transaction create has MVP convenience flags:

```bash
ffc transactions create \
  --type withdrawal \
  --source 1 \
  --destination 2 \
  --amount 12.34 \
  --description "Coffee"
```

Batch transaction import supports a JSON preview before writing:

```bash
ffc transactions import --input transactions.json --dry-run --format json
ffc transactions import --input transactions.json --timezone Asia/Shanghai --confirm --format json
```

The input may be a JSON array or an object with a `transactions` array. Rows can include `type`, `date`, `source_id` or `source_name`, `destination_id` or `destination_name`, `amount`, `description`, `category_name`, `notes`, and `tags`. The dry run reports rows as `create`, `duplicate`, or `ambiguous`; confirmation submits only create-ready rows. Use `--timezone Asia/Shanghai` for local bill timestamps such as Alipay, WeChat, and bank statements; dry-run output shows `originalDate` and `fireflyDate` when conversion is applied.

Webhook manual submission:

```bash
ffc webhooks submit <id>
```

## Bill Inbox

`bill-inbox` is the local task layer for bill emails and artifacts. It does not sync IMAP yet; the first version provides the common task store and CLI controls that later mailbox workers and source processors will use.

By default, local state is stored in `firefly-cli-data/inbox.json`. Override it with:

```bash
FIREFLY_BILLS_DATA_DIR=/path/to/data ffc bill-inbox list --format json
```

Inspect and progress tasks:

```bash
ffc bill-inbox list --format json
ffc bill-inbox show <taskId> --format json
ffc bill-inbox artifacts <taskId> --format json
ffc bill-inbox events <taskId> --format json
ffc bill-inbox secret submit <taskId> --value <password> --format json
ffc bill-inbox ignore <taskId> --format json
```

## Platform Operations

Admin user commands require a token with owner/admin permissions:

```bash
ffc admin users list
ffc admin users get <id>
ffc admin users create --email user@example.com --password <password>
ffc admin users update <id> --email new@example.com
ffc admin users delete <id>
```

Configuration:

```bash
ffc config list
ffc config get <key>
ffc config set <key> <value>
```

Export and cron:

```bash
ffc data export transactions --output transactions.json
ffc cron run --token <cliToken>
```

## Generic API Access

Use exact Firefly API paths when a dedicated command is missing:

```bash
ffc api GET /api/v1/accounts --format json
ffc api POST /api/v1/accounts --json '{"name":"Cash","type":"asset"}'
ffc api PUT /api/v1/accounts/1 --body account.json
ffc api DELETE /api/v1/accounts/1
ffc api GET /api/v1/accounts --query page=1 --query limit=50
```

The client sends `Accept: application/json`, `Authorization: Bearer <token>` when configured, and JSON `Content-Type` for JSON writes.

## Output Formats

```bash
--format table
--format json
--format raw
```

`table` is the default for interactive use. `json` pretty-prints parsed API responses. `raw` prints strings unchanged and otherwise emits compact JSON.

## Local Smoke Test

Against a local Firefly III instance at `http://127.0.0.1:8000`:

```bash
npm run build
ffc auth set-token --profile local --url http://127.0.0.1:8000 --token <token>
ffc auth status
ffc ping
ffc about --format json
ffc me --format json
ffc accounts list --format table
ffc api GET /api/v1/accounts --format json
```

## Troubleshooting

- `Authentication failed`: check `ffc auth status`, token value, and token expiry.
- `Permission denied`: the endpoint likely needs owner/admin permissions.
- `Not found`: confirm the configured base URL and API path.
- `Unsupported content type`: use `--json` or `--body` for JSON writes.
- `Validation failed`: Firefly III rejected the payload; re-run with `--format json` to inspect details.
- `Could not reach Firefly III`: check the server is running and the profile URL is correct.

## Development Checks

```bash
npm test -- --run
npm run build
npm run lint
npm run format:check
```
