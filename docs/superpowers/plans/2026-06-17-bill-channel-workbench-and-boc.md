# 账单渠道工作台与中国银行接入设计实施文档

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 把账单收件箱主视角从“邮件任务列表”改成“渠道工作台”，并新增中国银行交易流水渠道第一版。

**Architecture:** 邮箱同步、任务、附件、验证码、导入中间表继续复用现有通用管道；UI/API 增加按渠道聚合的查询和展示层。中国银行作为 `BillSourceChannel` 新渠道接入，第一版只做邮件识别、PDF 附件保存、验证码挑战和任务归属，不做 PDF 解密、文本解析和 Firefly 字段映射。

**Tech Stack:** Laravel controller/service/view, Eloquent query builder, existing `BillSourceChannel` contract, PHPUnit integration tests, Firefly III web routes.

---

## 设计原则

账单收件箱不应该让用户围绕邮件逐封处理，而应该让用户围绕来源渠道工作。邮件只是渠道下的一次账单批次来源；同一个渠道多次导出、多个邮件、重叠账期，都归在同一个渠道入口下。

第一屏展示固定内置渠道：

- 支付宝
- 微信支付
- 招商银行
- 中国银行

每个渠道显示聚合状态：最新邮件时间、待验证码任务数、待处理任务数、已解析批次数、待导入流水数、处理失败数、最近一条摘要，以及进入渠道按钮。

渠道详情页展示该渠道下的批次列表。批次仍然使用现有 `bill_tasks`，但用户理解为“这个渠道的一次账单导出/一次邮件批次”，不是独立系统入口。验证码输入、附件下载、归档、查看流水都发生在渠道详情页的批次行内或批次展开区。

## 数据模型取舍

第一版不新增 `bill_channels` 表。渠道定义继续来自 `BillSourceChannelRegistry`，因为四个渠道是内置固定渠道，不需要用户动态创建渠道。

聚合数据由查询实时计算：

- 渠道定义：`BillSourceChannelRegistry::settingsChannels()`
- 批次：`bill_tasks.source`
- 邮件信息：`bill_mail_messages`
- 附件：`bill_artifacts`
- 流水：`bill_statement_rows`
- 状态统计：`bill_tasks.status`

后续如果需要渠道级设置，例如默认账户映射、别名、启停、隐藏渠道，再新增持久化 `bill_channel_preferences`，不要提前建表。

## 页面结构

### 收件箱首页 `/bill-inbox`

首页改为渠道概览表，而不是任务表。

列建议：

- 渠道
- 最近收到
- 待处理
- 流水
- 最近状态
- 操作

每个渠道行展示：

- 渠道名和描述
- `需要验证码` 数量
- `待处理` 数量
- `处理失败` 数量
- `已解析` 批次数
- `待存入` 流水数量
- 最新邮件主题或最近摘要
- `进入` 按钮

首页不展示单封邮件的 Message-ID、路径、原始状态码，也不展示解释密码明文处理方式的小字。

### 渠道详情 `/bill-inbox/channel/{source}`

渠道详情页展示该渠道所有批次，默认隐藏已归档，保留状态筛选。

批次表每行显示：

- 批次 ID
- 中文状态
- 邮件主题/摘要
- 收到时间
- 附件数量
- 流水数量
- 下一步操作

当批次需要验证码时，行内直接显示输入框和提交按钮。提交后回到当前渠道详情页。

当批次已解析时，显示进入批次详情或展开流水入口。

当批次处理失败时，显示中文失败原因，并提供重新排队/归档入口；如果后续 UI 仍决定隐藏“重新排队”，则失败恢复只能放在 CLI/API。

### 批次详情 `/bill-inbox/{billTask}`

批次详情继续存在，用于查看某个批次的附件、处理记录和流水明细。它不再是用户进入收件箱后的主视图。

## 中国银行渠道第一版

中国银行渠道第一版只做接入和等待真实 PDF 结构，不做解析。

渠道定义：

- `source`: `boc`
- `profile_id`: `boc-transaction-statement`
- display name: `中国银行交易流水`
- 邮箱搜索：`FROM "中国银行"` 以及保守的 `SUBJECT "中国银行交易流水"`；如果 IMAP 对中文搜索不稳定，至少使用 subject 搜索并在本地 `matches()` 二次过滤。
- 邮件识别：主题包含 `中国银行交易流水`，正文包含 `中国银行APP` 或 `交易流水打印`，并且存在 PDF 附件。
- 附件：邮件直接携带加密 PDF，`bill_artifacts.kind = pdf`，`encrypted = true`。
- 验证码提示：`请输入中国银行APP“交易流水打印”申请记录中的打开密码`
- 密码来源：中国银行 APP 的交易流水打印申请记录。
- `prepare()`：no-op。
- `needsSecret()`：存在 encrypted PDF 时返回 true。
- `process()`：
  - 第一版收到 secret 后不尝试解密，不解析 PDF。
  - 将任务状态置为 `parsed` 或 `ready_for_sample_review` 二选一。考虑现有状态集合，第一版使用 `parsed`，并在 metadata 写入 `parser_status=waiting_for_pdf_mapping`。
  - 记录事件：`中国银行账单密码已提交，等待 PDF 字段映射确认`。
  - 不写入 `bill_statement_imports` 和 `bill_statement_rows`。

这样用户可以先完成“这封中国银行邮件属于中国银行渠道、附件在系统里、验证码流程走通”。等输入密码后，开发者再下载/查看本地 PDF 产物，确认真实文本结构，补解密、文本提取、字段映射和导入中间表。

## 后续 PDF 解析预留

中国银行解析在下一阶段单独做，不混入本次第一版：

- 新增 `BocStatementImportService`
- 如果 PDF 可通过密码打开，先提取文本。
- 识别账户、币种、交易日期、摘要、对方信息、金额、余额、借贷方向。
- 写入 `bill_statement_imports` 和 `bill_statement_rows`。
- 使用统一 `BillStatementRowIdentityService` 去重。
- Firefly 草稿映射必须等真实字段确认后再定，不能复用招商或支付宝规则。

## 实施计划

### Task 1: 扩展渠道架构文档

**Files:**
- Modify: `docs/bill-ingestion-channel-architecture.md`

- [ ] **Step 1: Add channel workbench section**

在 `UI/CLI 约束` 前新增章节 `## 渠道工作台视图`：

```markdown
## 渠道工作台视图

账单收件箱首页按来源渠道聚合展示，不按邮件逐封展示。邮件和附件是渠道下的批次来源；用户的主工作流是：

渠道 -> 批次 -> 附件/验证码 -> 流水 -> 导入。

首页固定展示所有内置渠道，包括暂时没有任务的渠道。渠道详情页展示该来源下的批次列表，默认隐藏已归档批次。验证码输入优先在渠道详情页批次行内完成。
```

- [ ] **Step 2: Add BOC channel section**

在招商银行渠道规范后新增 `## 中国银行交易流水渠道规范`，内容使用本文“中国银行渠道第一版”部分。

### Task 2: 后端提供渠道聚合查询

**Files:**
- Modify: `firefly-iii/app/Http/Controllers/BillInbox/IndexController.php`
- Test: `firefly-iii/tests/integration/Http/BillInbox/BillInboxControllerTest.php`

- [ ] **Step 1: Write failing index aggregation test**

新增测试 `testIndexShowsSourceChannelsInsteadOfMailTaskRows()`：

```php
public function testIndexShowsSourceChannelsInsteadOfMailTaskRows(): void
{
    BillTask::query()->create([
        'user_id'     => $this->user->id,
        'source'      => 'alipay',
        'profile_id'  => 'alipay-statement',
        'status'      => 'parsed',
        'received_at' => Carbon::parse('2026-06-12 18:26:00', 'Asia/Shanghai'),
        'summary'     => '支付宝交易流水明细',
    ]);

    $response = $this->actingAs($this->user)->get(route('bill-inbox.index'));

    $response->assertStatus(200);
    $response->assertSee('支付宝交易流水');
    $response->assertSee('微信支付账单流水');
    $response->assertSee('招商银行交易流水');
    $response->assertSee('中国银行交易流水');
    $response->assertSee('需要验证码');
    $response->assertSee('进入');
    $response->assertDontSee('邮件/摘要');
    $response->assertDontSee('Message-ID');
}
```

- [ ] **Step 2: Run failing test**

Run:

```bash
cd firefly-iii
vendor/bin/phpunit -c phpunit.xml --filter 'BillInboxControllerTest::testIndexShowsSourceChannelsInsteadOfMailTaskRows' --no-coverage
```

Expected: FAIL because index still renders task rows and BOC channel is not registered.

- [ ] **Step 3: Add `sourceChannels()` helper**

In `IndexController`, add a private method that loops over `$this->channelRegistry->settingsChannels()` and computes per-source counts scoped to `auth()->id()`:

```php
private function sourceChannels(): array
{
    return array_map(function (array $channel): array {
        $source = (string) $channel['source'];
        $tasks = BillTask::query()
            ->where('user_id', auth()->id())
            ->where('source', $source);

        $latest = (clone $tasks)
            ->with('mailMessage')
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->first();

        return [
            'source'             => $source,
            'name'               => $channel['name'],
            'description'        => $channel['description'],
            'latest_task'        => $latest,
            'needs_secret_count' => (clone $tasks)->where('status', 'needs_secret')->count(),
            'ready_count'        => (clone $tasks)->where('status', 'ready')->count(),
            'parsed_count'       => (clone $tasks)->where('status', 'parsed')->count(),
            'failed_count'       => (clone $tasks)->where('status', 'failed')->count(),
            'pending_row_count'  => BillStatementRow::query()
                ->where('user_id', auth()->id())
                ->where('status', 'pending')
                ->whereHas('import', static fn ($query) => $query->where('source', $source))
                ->count(),
        ];
    }, $this->channelRegistry->settingsChannels());
}
```

If PHPStan complains about closure types, add explicit `Builder` imports and parameter types.

- [ ] **Step 4: Pass channels to index view**

Change `index()` to pass:

```php
'sourceChannels' => $this->sourceChannels(),
```

Keep existing `tasks` query temporarily if route filters still need it during migration; remove it only after view no longer uses it.

### Task 3: Add channel detail route and controller action

**Files:**
- Modify: `firefly-iii/routes/web.php`
- Modify: `firefly-iii/app/Http/Controllers/BillInbox/IndexController.php`
- Create: `firefly-iii/resources/views/bill-inbox/channel.twig`
- Test: `firefly-iii/tests/integration/Http/BillInbox/BillInboxControllerTest.php`

- [ ] **Step 1: Add failing route test**

Add:

```php
public function testChannelPageShowsTasksForSingleSource(): void
{
    BillTask::query()->create([
        'user_id'     => $this->user->id,
        'source'      => 'alipay',
        'profile_id'  => 'alipay-statement',
        'status'      => 'parsed',
        'received_at' => Carbon::parse('2026-06-12 18:26:00', 'Asia/Shanghai'),
        'summary'     => '支付宝交易流水明细',
    ]);

    $response = $this->actingAs($this->user)->get(route('bill-inbox.channel', ['source' => 'alipay']));

    $response->assertStatus(200);
    $response->assertSee('支付宝交易流水');
    $response->assertSee('支付宝交易流水明细');
    $response->assertSee('批次');
    $response->assertSee('请输入');
    $response->assertDontSee('微信支付账单流水文件');
}
```

- [ ] **Step 2: Add route before `{billTask}` route**

In `firefly-iii/routes/web.php`, within bill inbox group, add before `GET {billTask}`:

```php
Route::get('channel/{source}', ['uses' => 'IndexController@channel', 'as' => 'channel']);
```

- [ ] **Step 3: Add controller action**

Add `channel(Request $request, string $source)`:

```php
public function channel(Request $request, string $source): Factory|View
{
    $channel = collect($this->channelRegistry->settingsChannels())
        ->first(static fn (array $channel): bool => $channel['source'] === $source);
    if (null === $channel) {
        abort(404);
    }

    $status = (string) $request->query('status', '');
    $query = BillTask::query()
        ->where('user_id', auth()->id())
        ->where('source', $source)
        ->with(['mailMessage', 'currentSecretChallenge', 'statementRows'])
        ->orderByDesc('received_at')
        ->orderByDesc('id');

    if ('' !== $status) {
        $query->where('status', $status);
    } else {
        $query->where('status', '!=', 'cleaned');
    }

    return view('bill-inbox.channel', [
        'channel'       => $channel,
        'tasks'         => $query->paginate(25)->withQueryString(),
        'currentStatus' => $status,
        'statusLabels'  => $this->statusLabels(),
        'statusClasses' => $this->statusClasses(),
    ]);
}
```

- [ ] **Step 4: Create channel view**

Create `channel.twig` by moving the existing task table from `index.twig` into this new file. Keep inline secret forms and archive actions. Change secret hidden fields:

```twig
<input type="hidden" name="redirect_to" value="channel">
<input type="hidden" name="source" value="{{ channel.source }}">
```

### Task 4: Update index view to render channel cards/table

**Files:**
- Modify: `firefly-iii/resources/views/bill-inbox/index.twig`
- Test: `firefly-iii/tests/integration/Http/BillInbox/BillInboxControllerTest.php`

- [ ] **Step 1: Replace task table with source channel table**

Render `sourceChannels`:

```twig
<table class="table table-hover table-striped">
    <thead>
    <tr>
        <th>渠道</th>
        <th>最近收到</th>
        <th>待处理</th>
        <th>流水</th>
        <th>最近状态</th>
        <th style="width: 120px;">操作</th>
    </tr>
    </thead>
    <tbody>
    {% for channel in sourceChannels %}
        <tr>
            <td>
                <strong>{{ channel.name }}</strong><br>
                <small class="text-muted">{{ channel.description }}</small>
            </td>
            <td>
                {% if channel.latest_task and channel.latest_task.received_at %}
                    {{ channel.latest_task.received_at.format('Y-m-d H:i') }}<br>
                    <small class="text-muted">{{ channel.latest_task.summary|default(channel.latest_task.mailMessage.subject|default('-')) }}</small>
                {% else %}
                    -
                {% endif %}
            </td>
            <td>
                <span class="label label-warning">需要验证码 {{ channel.needs_secret_count }}</span>
                <span class="label label-warning">待处理 {{ channel.ready_count }}</span>
                <span class="label label-danger">失败 {{ channel.failed_count }}</span>
            </td>
            <td>
                <span class="label label-primary">已解析 {{ channel.parsed_count }}</span>
                <span class="label label-warning">待存入 {{ channel.pending_row_count }}</span>
            </td>
            <td>
                {% if channel.latest_task %}
                    <span class="label {{ statusClasses[channel.latest_task.status]|default('label-info') }}">{{ statusLabels[channel.latest_task.status]|default(channel.latest_task.status) }}</span>
                {% else %}
                    <span class="text-muted">暂无批次</span>
                {% endif %}
            </td>
            <td>
                <a class="btn btn-xs btn-primary" href="{{ route('bill-inbox.channel', {source: channel.source}) }}">
                    <span class="fa fa-folder-open fa-fw"></span> 进入
                </a>
            </td>
        </tr>
    {% endfor %}
    </tbody>
</table>
```

- [ ] **Step 2: Run index test**

Run the new index test from Task 2 and update assertions if the final copy differs.

### Task 5: Support redirect after secret submit to channel page

**Files:**
- Modify: `firefly-iii/app/Http/Controllers/BillInbox/IndexController.php`
- Test: `firefly-iii/tests/integration/Http/BillInbox/BillInboxControllerTest.php`

- [ ] **Step 1: Add failing redirect test**

```php
public function testSecretSubmitCanReturnToChannelPage(): void
{
    $response = $this->actingAs($this->user)->post(route('bill-inbox.secret', [$this->task->id]), [
        'value'       => '123456',
        'redirect_to' => 'channel',
        'source'      => 'cmb',
    ]);

    $response->assertRedirect(route('bill-inbox.channel', ['source' => 'cmb']));
}
```

- [ ] **Step 2: Add redirect branch**

In `postSecret()` after the existing index redirect branch:

```php
if ('channel' === (string) $request->input('redirect_to', '')) {
    $source = (string) $request->input('source', $billTask->source);

    return redirect(route('bill-inbox.channel', ['source' => $source]));
}
```

### Task 6: Add China Bank source channel

**Files:**
- Create: `firefly-iii/app/Services/BillIngestion/Channels/BocTransactionBillSourceChannel.php`
- Modify: `firefly-iii/app/Providers/FireflyServiceProvider.php`
- Test: `firefly-iii/tests/integration/Services/BillIngestion/BillMailboxSyncServiceTest.php`
- Test: `firefly-iii/tests/integration/Http/BillInbox/BillInboxControllerTest.php`

- [ ] **Step 1: Add failing registry/settings test**

In mailbox sync service test, assert settings includes source `boc`, display `中国银行交易流水`, and search criteria includes `SUBJECT "中国银行交易流水"`.

- [ ] **Step 2: Add failing sync test**

Create a fake mail:

```php
private function bocRawMessage(): string
{
    $email = (new \Symfony\Component\Mime\Email())
        ->from('中国银行 <service@bank-of-china.example>')
        ->to('ziyufg@gmail.com')
        ->subject('中国银行交易流水')
        ->date(new \DateTimeImmutable('2026-06-17 16:44:00 +0800'))
        ->text('附件是您通过中国银行APP申请的电子版交易流水，打开密码请在中国银行APP“交易流水打印”的申请记录中查询。')
        ->attach('encrypted pdf bytes', 'KA020003687d1a432d8001.pdf', 'application/pdf');
    $email->getHeaders()->addIdHeader('Message-ID', 'boc-statement-20260617@mail.example');

    return $email->toString();
}
```

Test expected:

```php
$task = BillTask::query()->where('source', 'boc')->first();
$this->assertSame('boc-transaction-statement', $task->profile_id);
$this->assertSame('needs_secret', $task->status);
$this->assertSame('中国银行交易流水', $task->summary);
$this->assertSame('请输入中国银行APP“交易流水打印”申请记录中的打开密码', $task->currentSecretChallenge->prompt);
$this->assertSame(1, $task->artifacts()->where('kind', 'pdf')->where('encrypted', true)->count());
```

- [ ] **Step 3: Implement channel**

Create class:

```php
final class BocTransactionBillSourceChannel implements BillSourceChannel
{
    public function source(): string { return 'boc'; }
    public function displayName(): string { return '中国银行交易流水'; }
    public function settingsDescription(): string
    {
        return '会自动识别中国银行交易流水邮件，并保存加密 PDF 附件等待输入打开密码。';
    }
    public function profileIds(): array { return ['boc-transaction-statement']; }
    public function mailboxSearchCriteria(): array { return ['SUBJECT "中国银行交易流水"']; }
}
```

Implement `matches()` by checking subject/body/PDF attachment. Implement `ingest()` like CMB but create PDF artifacts and message `已识别中国银行交易流水邮件，等待打开密码`。

Implement:

```php
public function prepare(BillTask $task): bool { return true; }
public function needsSecret(BillTask $task): bool
{
    return $task->artifacts()->where('kind', 'pdf')->where('encrypted', true)->exists();
}
public function secretPrompt(BillTask $task): string
{
    return '请输入中国银行APP“交易流水打印”申请记录中的打开密码';
}
public function process(BillTask $task, ?string $secret = null): bool
{
    if (null === $secret || '' === trim($secret)) {
        $this->openSecretChallenge($task);

        return true;
    }

    $metadata = is_array($task->metadata) ? $task->metadata : [];
    $metadata['parser_status'] = 'waiting_for_pdf_mapping';
    $metadata['password_submitted_at'] = now('Asia/Shanghai')->toAtomString();
    $task->metadata = $metadata;
    $task->status = 'parsed';
    $task->error_code = null;
    $task->error_message = null;
    $task->save();
    $this->appendEvent($task, 'task.parsed', '中国银行账单密码已提交，等待 PDF 字段映射确认');

    return true;
}
public function shouldProcessAfterSecret(BillTask $task): bool { return true; }
```

Do not store `$secret` in metadata, task, artifact, event, or logs.

- [ ] **Step 4: Register channel**

Add import and registry entry in `FireflyServiceProvider` after CMB or before CMB:

```php
app(BocTransactionBillSourceChannel::class),
```

### Task 7: Verification

**Files:**
- No production files unless tests reveal issues.

- [ ] **Step 1: Run backend focused tests**

Run:

```bash
cd firefly-iii
vendor/bin/phpunit -c phpunit.xml --filter 'BillInboxControllerTest|BillMailboxSyncServiceTest' --no-coverage
```

Expected: all tests pass.

- [ ] **Step 2: Run bill ingestion service tests**

Run:

```bash
cd firefly-iii
vendor/bin/phpunit -c phpunit.xml --filter 'BillMailIngestionServiceTest|BillTaskProcessorTest' --no-coverage
```

Expected: all tests pass.

- [ ] **Step 3: Check whitespace**

Run:

```bash
git diff --check
```

Expected: no output and exit 0.

## Commit Plan

实现完成后按功能分两次提交：

1. `feat(bill-inbox): 增加渠道聚合工作台`
   - index/channel controller and views
   - related web tests

2. `feat(bill-inbox): 接入中国银行交易流水渠道`
   - BOC channel class
   - provider registration
   - mailbox sync and channel tests
   - architecture doc update

如果实现过程中只适合一个完整提交，则使用：

```text
feat(bill-inbox): 增加渠道工作台和中国银行渠道
```
