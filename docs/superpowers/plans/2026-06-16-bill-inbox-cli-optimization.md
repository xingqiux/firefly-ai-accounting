# Bill Inbox CLI Optimization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add API and CLI commands so `ffc bill-inbox` can configure, sync, process, inspect, download, and import Alipay and WeChat bill inbox tasks.

**Architecture:** Reuse existing Firefly backend services and expose the missing web/artisan actions through small API endpoints. Extend the TypeScript CLI service and commands to call those endpoints, preserving the current JSON/table output model.

**Tech Stack:** PHP/Laravel controllers and integration tests; TypeScript Commander CLI; Vitest CLI tests; existing `FireflyHttpClient`.

---

### Task 1: CLI Tests For Missing Workflow Commands

**Files:**
- Modify: `firefly-cli/tests/commands/bills.test.ts`

- [ ] **Step 1: Write failing tests for settings, sync, process, cleanup, and artifact download**

Add Vitest cases that assert:

- `bill-inbox sync --limit 50` sends `POST /api/v1/bill-inbox/sync` with `{ limit: 50 }`.
- `bill-inbox process --limit 10` sends `POST /api/v1/bill-inbox/process` with `{ limit: 10 }`.
- `bill-inbox cleanup-stale` sends `POST /api/v1/bill-inbox/cleanup-stale`.
- `bill-inbox settings show` sends `GET /api/v1/bill-inbox/settings`.
- `bill-inbox settings set --enabled --provider gmail --email user@example.com --password app-pass` sends `PUT /api/v1/bill-inbox/settings`.
- `bill-inbox artifact download 9 --output /tmp/a.zip` sends `GET /api/v1/bill-artifacts/9/download` and writes the response body.

- [ ] **Step 2: Run tests and verify they fail**

Run: `npm test -- --run tests/commands/bills.test.ts`

Expected: command-not-found or missing-method failures for the new commands.

### Task 2: Backend API Tests

**Files:**
- Modify: `firefly-iii/tests/integration/Api/Models/BillTask/BillTaskControllerTest.php`

- [ ] **Step 1: Write failing integration tests**

Add tests for:

- Showing mailbox settings.
- Updating mailbox settings.
- Syncing mailbox with a mocked sync service result if practical, or verifying a disabled mailbox returns zero counts.
- Processing queued tasks.
- Cleaning stale tasks.
- Downloading an artifact through the API route.

- [ ] **Step 2: Run API tests and verify they fail**

Run the narrow PHPUnit target for `BillTaskControllerTest`.

Expected: route-not-found failures for new API endpoints.

### Task 3: Backend API Implementation

**Files:**
- Create: `firefly-iii/app/Api/V1/Controllers/Models/BillTask/BillInboxController.php`
- Modify: `firefly-iii/routes/api.php`

- [ ] **Step 1: Implement API controller**

Controller methods:

- `settings()`
- `updateSettings(Request $request)`
- `sync(Request $request)`
- `process(Request $request)`
- `cleanupStale()`

Use existing services and `Preferences`.

- [ ] **Step 2: Wire routes**

Add route group:

- `GET v1/bill-inbox/settings`
- `PUT v1/bill-inbox/settings`
- `POST v1/bill-inbox/sync`
- `POST v1/bill-inbox/process`
- `POST v1/bill-inbox/cleanup-stale`

- [ ] **Step 3: Run backend tests**

Run the narrow API test target and fix failures.

### Task 4: CLI HTTP Support For Downloads

**Files:**
- Modify: `firefly-cli/src/core/http-client.ts`
- Test: `firefly-cli/tests/commands/bills.test.ts`

- [ ] **Step 1: Add raw response helper**

Add a method that returns a `Response` or `ArrayBuffer` for artifact downloads while preserving auth, timeout, and HTTP error behavior.

- [ ] **Step 2: Verify artifact download test still fails for missing command only**

Run the CLI test target.

### Task 5: CLI Service And Commands

**Files:**
- Modify: `firefly-cli/src/services/bill-task-service.ts`
- Modify: `firefly-cli/src/commands/bill-inbox.ts`

- [ ] **Step 1: Add service methods**

Add:

- `settings()`
- `updateSettings(payload)`
- `sync(limit?)`
- `process(limit?)`
- `cleanupStale()`
- `downloadArtifact(artifactId)`

- [ ] **Step 2: Add Commander commands**

Add:

- `sync`
- `process`
- `cleanup-stale`
- `settings show`
- `settings set`
- `artifact download`

- [ ] **Step 3: Run CLI tests and build**

Run:

- `npm test -- --run tests/commands/bills.test.ts`
- `npm run build`

### Task 6: Documentation

**Files:**
- Modify: `firefly-cli/README.md`

- [ ] **Step 1: Document full bill inbox workflow**

Show examples for settings, sync, process, secret submit, rows, row update, import, archive, and artifact download.

- [ ] **Step 2: Run final verification**

Run:

- `npm test -- --run tests/commands/bills.test.ts`
- `npm run build`
- backend narrow PHPUnit target if available in local environment
