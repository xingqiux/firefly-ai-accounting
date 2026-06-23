<?php

declare(strict_types=1);

namespace FireflyIII\Services\BillIngestion;

use Carbon\Carbon;
use FireflyIII\Models\BillStatementRow;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BillStatementRowSplitService
{
    /**
     * @param array<int,array<string,mixed>> $splits
     *
     * @return array<int,BillStatementRow>
     */
    public function split(BillStatementRow $row, array $splits): array
    {
        if ('needs_split' !== $row->status) {
            throw new RuntimeException('只有需要拆分的组合支付流水可以拆分。');
        }
        if (null !== $row->transaction_group_id) {
            throw new RuntimeException('这条组合支付已经存入账本，请先撤销旧交易后再拆分。');
        }

        $parts = $this->normalizeSplits($splits);
        $total = array_reduce($parts, static fn (string $carry, array $part): string => bcadd($carry, $part['amount'], 2), '0');
        if (0 !== bccomp($total, (string) $row->amount, 2)) {
            throw new RuntimeException(sprintf('拆分金额合计 %s 必须等于原流水金额 %s。', $total, $row->amount));
        }

        return DB::transaction(function () use ($row, $parts): array {
            $created = [];
            $nextRowNumber = ((int) BillStatementRow::query()
                ->where('bill_statement_import_id', $row->bill_statement_import_id)
                ->max('row_number')) + 1;

            foreach ($parts as $index => $part) {
                $created[] = BillStatementRow::query()->create($this->childAttributes($row, $part, $nextRowNumber + $index));
            }

            $metadata = is_array($row->metadata) ? $row->metadata : [];
            $metadata['payment_split']['status'] = 'split';
            $metadata['payment_split']['child_row_ids'] = array_map(static fn (BillStatementRow $child): int => (int) $child->id, $created);
            $row->status = 'split';
            $row->firefly_type = null;
            $row->firefly_amount = null;
            $row->metadata = $metadata;
            $row->user_modified_at = Carbon::now('Asia/Shanghai');
            $row->save();

            return $created;
        });
    }

    /**
     * @param array<int,array<string,mixed>> $splits
     *
     * @return array<int,array{payment_method:string,source_name:string,amount:string}>
     */
    private function normalizeSplits(array $splits): array
    {
        $parts = [];
        foreach ($splits as $split) {
            $paymentMethod = trim((string) ($split['payment_method'] ?? ''));
            $sourceName = trim((string) ($split['source_name'] ?? ''));
            $amount = trim((string) ($split['amount'] ?? ''));
            if ('' === $paymentMethod) {
                $paymentMethod = $sourceName;
            }
            if ('' === $sourceName) {
                $sourceName = $this->fireflyAccountName($paymentMethod);
            }
            if ('' === $paymentMethod || '' === $sourceName || '' === $amount || 1 !== bccomp($amount, '0', 2)) {
                continue;
            }
            $parts[] = [
                'payment_method' => $paymentMethod,
                'source_name'    => $sourceName,
                'amount'         => bcadd($amount, '0', 2),
            ];
        }

        if (count($parts) < 2) {
            throw new RuntimeException('组合支付至少需要拆成两条流水。');
        }

        return $parts;
    }

    /**
     * @param array{payment_method:string,source_name:string,amount:string} $part
     *
     * @return array<string,mixed>
     */
    private function childAttributes(BillStatementRow $row, array $part, int $rowNumber): array
    {
        $metadata = is_array($row->metadata) ? $row->metadata : [];
        $direction = (string) $row->direction;
        $fireflyType = '收入' === $direction ? 'deposit' : 'withdrawal';

        return [
            'user_id'                  => $row->user_id,
            'bill_task_id'             => $row->bill_task_id,
            'bill_statement_import_id' => $row->bill_statement_import_id,
            'row_number'               => $rowNumber,
            'status'                   => 'pending',
            'occurred_at'              => $row->occurred_at,
            'platform_category'        => $row->platform_category,
            'counterparty'             => $row->counterparty,
            'counterparty_account'     => $row->counterparty_account,
            'description'              => $row->description,
            'direction'                => $row->direction,
            'amount'                   => $part['amount'],
            'payment_method'           => $part['payment_method'],
            'transaction_status'       => $row->transaction_status,
            'platform_order_no'        => $row->platform_order_no,
            'merchant_order_no'        => $row->merchant_order_no,
            'remark'                   => $row->remark,
            'raw_data'                 => $row->raw_data,
            'editable_data'            => array_replace(is_array($row->editable_data) ? $row->editable_data : [], [
                '金额'       => $part['amount'],
                '收/付款方式' => $part['payment_method'],
            ]),
            'firefly_type'             => $fireflyType,
            'firefly_date'             => $row->firefly_date ?: $row->occurred_at,
            'firefly_amount'           => $part['amount'],
            'firefly_description'      => $row->firefly_description ?: $row->description ?: $row->counterparty,
            'source_name'              => 'withdrawal' === $fireflyType ? $part['source_name'] : $row->source_name,
            'destination_name'         => 'withdrawal' === $fireflyType ? $row->destination_name : $part['source_name'],
            'category_name'            => $row->category_name,
            'notes'                    => trim((string) $row->notes."\n组合支付拆分自流水 #".$row->id),
            'tags'                     => $row->tags,
            'external_key'             => null === $row->external_key ? null : $row->external_key.':split:'.$rowNumber,
            'fingerprint'              => 'sha256:'.hash('sha256', implode('|', [$row->id, $rowNumber, $part['source_name'], $part['amount']])),
            'duplicate_state'          => 'unique',
            'metadata'                 => [
                'payment_split' => [
                    'parent_row_id' => $row->id,
                    'source_status' => $metadata['payment_split']['status'] ?? 'needs_split',
                ],
            ],
        ];
    }

    private function fireflyAccountName(string $paymentMethod): string
    {
        if (str_contains($paymentMethod, '招商银行')) {
            return '招商银行';
        }
        if (str_contains($paymentMethod, '中国银行')) {
            return '中国银行';
        }
        if (str_contains($paymentMethod, '花呗')) {
            return '花呗';
        }

        return '' === $paymentMethod ? '支付宝' : $paymentMethod;
    }
}
