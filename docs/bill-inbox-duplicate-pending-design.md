# Bill Inbox 重复流水状态设计问题

## 背景

在实际使用账单收件箱时，用户看到大量流水仍处于 `pending`，但这些流水实际上已经通过其他账单批次或此前导入流程进入 Firefly。继续按 `pending` 理解会让用户误以为还有大量待导入账单。

本说明记录当前发现的问题和建议的修复方向。

## 现象

以当前真实环境中的账单任务为例：

- 支付宝任务 `9`
  - `pending`: 116 行
  - `duplicate_candidates`: 100 行
  - `by_duplicate_state`: `duplicate` 151 行 / `unique` 16 行
- 微信任务 `13`
  - `pending`: 73 行
  - `duplicate_candidates`: 69 行
  - `by_duplicate_state`: `duplicate` 113 行 / `unique` 4 行

这些行的状态经常是：

```text
status = pending
duplicate_state = duplicate
```

因此只按 `status=pending` 统计时，会把已经识别为重复的流水错误地展示成“待处理/待导入”。

## 设计问题 1：duplicate 行仍进入 new_candidates

`BillStatementRowSummaryService::reviewTaskRows()` 中，当前逻辑会先识别 duplicate：

```php
if ('duplicate' === $row->duplicate_state) {
    $duplicateCandidates[] = $preview + ['reason' => '已存在相同账单流水'];
}
```

但随后构造 `new_candidates` 时只排除了 `conflict`，没有排除 `duplicate`：

```php
if (null !== $row->firefly_type && '' !== $row->firefly_type && !$this->looksSpecialCase($row) && 'conflict' !== $row->duplicate_state) {
    $newCandidates[] = $preview;
}
```

结果是同一条流水可以同时出现在：

- `duplicate_candidates`
- `new_candidates`

这会让 review 结果自相矛盾：一边提示“已存在相同账单流水”，一边又把它算进可导入候选。

### 建议修复

`new_candidates` 应排除所有非唯一状态，至少排除 `duplicate` 和 `conflict`：

```php
if (
    null !== $row->firefly_type
    && '' !== $row->firefly_type
    && !$this->looksSpecialCase($row)
    && !in_array($row->duplicate_state, ['duplicate', 'conflict'], true)
) {
    $newCandidates[] = $preview;
}
```

## 设计问题 2：导入服务未阻止 duplicate 再导入

`BillStatementRowImportService::importRow()` 当前会跳过：

- `needs_split` / `split`
- 已 `imported` 且有 `transaction_group_id`
- `firefly_type` 为空

但没有阻止：

```text
status = pending
duplicate_state = duplicate
```

这意味着如果用户执行 `bill-inbox import --all --confirm`，已经被识别为 duplicate 的 pending 行仍可能被导入。

### 建议修复

在 `importRow()` 早期加入 duplicate/conflict guard：

```php
if (in_array($row->duplicate_state, ['duplicate', 'conflict'], true)) {
    return $this->reportForRow($row, [
        'status' => 'skipped',
        'error'  => '这条流水已识别为重复或冲突，不自动导入。',
    ]);
}
```

## 设计问题 3：pending 状态语义过宽

目前 `pending` 同时包含：

- 真正待导入的新流水
- 已识别为 duplicate 的重复流水
- conflict/需人工确认流水
- 不是普通收支、应跳过的流水

这会导致 UI 和 CLI 汇总很难准确表达用户下一步应该做什么。

### 建议修复

汇总时至少区分：

```text
pending + unique     => 真正待处理/待导入
pending + duplicate  => 重复待清理
pending + conflict   => 需人工确认
pending + no type    => 不可直接导入/应跳过
```

或者在重复识别后将重复行流转到更明确的状态，例如：

```text
duplicate / skipped / ignored / cleaned
```

避免长期停留在 `pending`。

## 中长期问题：跨来源去重不足

当前账单流水去重主要基于：

- 同一 source 下的订单号 / merchant order / fingerprint
- Firefly transaction journal meta 中的 `internal_reference` / `external_id`

这对支付宝/微信账单之间的重复有一定效果，但对银行流水与支付平台账单的重复识别不足。

典型场景：

- 招商银行流水 vs 微信支付账单
- 招商银行流水 vs 支付宝账单
- 中国银行流水 vs 支付宝/微信账单

同一笔消费可能同时出现在支付平台账单和银行卡流水中。如果没有跨来源匹配，会把银行卡流水当成新的待导入记录。

建议后续增加跨来源弱匹配策略，例如：

```text
日期/时间接近 + 金额相同 + 资产账户/支付方式一致 + 商户名相似
```

并在 review 中将其展示为 `possible_cross_source_duplicate`，由用户确认。

## 期望结果

- Review 结果中 duplicate 不再同时出现在 new candidates。
- `import --all --confirm` 不会导入 duplicate/conflict 行。
- UI/CLI 汇总不再把重复流水简单算作“待导入”。
- 后续可逐步支持跨来源重复识别，减少银行卡账单与支付平台账单重复入账风险。
