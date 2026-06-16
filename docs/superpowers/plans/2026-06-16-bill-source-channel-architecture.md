# Bill Source Channel Architecture Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn the current Alipay-only bill inbox pipeline into a common bill mailbox pipeline with source-specific channel processors.

**Architecture:** The backend keeps one common task/artifact/challenge/import-row model. Source-specific logic moves behind `BillSourceChannel` implementations registered in `BillSourceChannelRegistry`; Alipay becomes the first built-in channel, and future WeChat/CMB/BOC support adds channel classes instead of changing UI/CLI workflows.

**Tech Stack:** Laravel service container, Eloquent models, Firefly III web/API controllers, PHP integration tests, existing `ffc` CLI over Firefly API.

---

### Task 1: Document The Channel Contract

**Files:**
- Create: `docs/bill-ingestion-channel-architecture.md`
- Modify: `docs/bill-ingestion-workflow.md`

- [ ] **Step 1: Write the architecture document**

Create `docs/bill-ingestion-channel-architecture.md` with Chinese sections covering:

```markdown
# 账单邮箱来源渠道架构规范

## 目标

账单邮箱由通用管道负责同步、存档、任务状态、验证码挑战、中间表、UI/API/CLI 操作。支付宝、微信、招商银行、中国银行等来源只实现自己的渠道处理器。

## 分层

- 通用管道：邮箱同步、邮件入库、任务生命周期、附件存储、事件日志、中间表、Firefly 导入。
- 来源渠道：邮件匹配、验证码策略、附件展开、账单解析、字段映射、归档命名。
```

- [ ] **Step 2: Mark the older workflow doc as historical**

Add a short note near the top of `docs/bill-ingestion-workflow.md`:

```markdown
> 当前实现准则见 `docs/bill-ingestion-channel-architecture.md`。本文保留早期工作流背景，部分“不做 UI/不做支付宝解析”的阶段描述已经被后续实现取代。
```

### Task 2: Add Channel Registry Tests

**Files:**
- Modify: `firefly-iii/tests/integration/Services/BillIngestion/BillMailboxSyncServiceTest.php`

- [ ] **Step 1: Add failing registry test**

Add a test asserting `BillSourceChannelRegistry` exists, exposes Alipay mailbox search criteria, and can find the Alipay channel by `source/profile_id`.

- [ ] **Step 2: Run the focused test**

Run:

```bash
cd firefly-iii
php artisan test tests/integration/Services/BillIngestion/BillMailboxSyncServiceTest.php --filter testBuiltInChannelsExposeMailboxSearchCriteriaThroughRegistry --no-coverage
```

Expected before implementation: failure because registry and channel classes do not exist.

### Task 3: Implement Channel Contract And Alipay Channel

**Files:**
- Create: `firefly-iii/app/Services/BillIngestion/BillSourceChannel.php`
- Create: `firefly-iii/app/Services/BillIngestion/BillSourceChannelRegistry.php`
- Create: `firefly-iii/app/Services/BillIngestion/Channels/AlipayBillSourceChannel.php`
- Modify: `firefly-iii/app/Providers/FireflyServiceProvider.php`

- [ ] **Step 1: Add `BillSourceChannel`**

Define methods for source id, profile ids, mailbox search criteria, mail match, task ingestion, secret requirement, prompt, task processing, and after-secret auto processing.

- [ ] **Step 2: Add registry**

Store built-in channels, deduplicate search criteria, match incoming mail, and find the channel for a task.

- [ ] **Step 3: Move Alipay-specific behavior into channel**

Move sender/subject matching, encrypted ZIP artifact creation, password prompt, ZIP extraction, parsed task status update, and event messages into `AlipayBillSourceChannel`.

- [ ] **Step 4: Register built-in channel**

Register `BillSourceChannelRegistry` in `FireflyServiceProvider` with `AlipayBillSourceChannel`.

### Task 4: Route Common Services Through Registry

**Files:**
- Modify: `firefly-iii/app/Services/BillIngestion/BillMailboxSyncService.php`
- Modify: `firefly-iii/app/Services/BillIngestion/BillMailIngestionService.php`
- Modify: `firefly-iii/app/Services/BillIngestion/BillTaskProcessor.php`
- Modify: `firefly-iii/app/Services/BillIngestion/BillTaskActionService.php`

- [ ] **Step 1: Use registry for mailbox search**

`BillMailboxSyncService` should call `BillSourceChannelRegistry::mailboxSearchCriteria()`.

- [ ] **Step 2: Use registry for mail ingestion**

`BillMailIngestionService` should ask the registry to match a channel and then delegate task creation to that channel.

- [ ] **Step 3: Use registry for processing**

`BillTaskProcessor` should keep generic state handling but delegate ready task processing and secret prompts to the matched channel.

- [ ] **Step 4: Use registry after secret submission**

`BillTaskActionService` should auto-process after secret submission when the matched channel says it should.

### Task 5: Verify Existing Behavior

**Files:**
- Test existing service/controller/CLI test suites.

- [ ] **Step 1: Run backend targeted tests**

Run:

```bash
cd firefly-iii
php artisan test --filter 'BillTaskProcessorTest|BillTaskControllerTest|BillInboxControllerTest|BillMailIngestionServiceTest|BillMailboxSyncServiceTest' --no-coverage
```

Expected: all targeted tests pass.

- [ ] **Step 2: Run CLI tests/build if backend tests pass**

Run the existing `firefly-cli` tests and build commands used by the project.
