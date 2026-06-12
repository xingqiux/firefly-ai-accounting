<?php

declare(strict_types=1);

namespace FireflyIII\Services\BillIngestion;

use FireflyIII\Models\BillTask;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BillTaskProcessor
{
    private const array PROCESSABLE_STATUSES = ['received', 'ready'];

    public function processBatch(int $limit = 25): BillTaskBatchResult
    {
        $limit     = max(1, min($limit, 500));
        $processed = 0;
        $failed    = 0;

        $this->nextTasks($limit)->each(function (BillTask $task) use (&$processed, &$failed): void {
            ++$processed;
            if (false === $this->process($task)) {
                ++$failed;
            }
        });

        return new BillTaskBatchResult($processed, $failed);
    }

    public function process(BillTask $task): bool
    {
        return DB::transaction(function () use ($task): bool {
            $task->refresh();

            if ('received' === $task->status) {
                return $this->routeReceivedTask($task);
            }
            if ('ready' === $task->status) {
                return $this->processReadyTask($task);
            }

            return true;
        });
    }

    /**
     * @return Collection<int, BillTask>
     */
    private function nextTasks(int $limit): Collection
    {
        return BillTask::query()
            ->whereIn('status', self::PROCESSABLE_STATUSES)
            ->orderBy('received_at')
            ->orderBy('id')
            ->limit($limit)
            ->get()
        ;
    }

    private function routeReceivedTask(BillTask $task): bool
    {
        if ('unknown' === $task->source || null === $task->profile_id) {
            $task->status = 'unknown';
            $task->save();
            $this->appendEvent($task, 'task.unknown', '未匹配到账单处理配置');

            return true;
        }

        if ($this->needsSecret($task)) {
            $challenge = $task->secretChallenges()->create([
                'kind'     => 'password',
                'prompt'   => $this->secretPrompt($task),
                'status'   => 'open',
                'attempts' => 0,
            ]);

            $task->status                      = 'needs_secret';
            $task->current_secret_challenge_id = $challenge->id;
            $task->save();
            $this->appendEvent($task, 'challenge.created', '任务需要密码或验证码');

            return true;
        }

        $task->status = 'ready';
        $task->save();
        $this->appendEvent($task, 'task.ready', '任务已准备处理');

        return true;
    }

    private function processReadyTask(BillTask $task): bool
    {
        $task->status        = 'failed';
        $task->error_code    = 'processor_missing';
        $task->error_message = 'No source-specific bill processor is registered for this task yet.';
        $task->save();
        $this->appendEvent($task, 'task.failed', '缺少来源处理器，任务暂时无法解析');

        return false;
    }

    private function needsSecret(BillTask $task): bool
    {
        return $task->artifacts()->where('encrypted', true)->exists();
    }

    private function secretPrompt(BillTask $task): string
    {
        if ('alipay' === $task->source && 'alipay-statement' === $task->profile_id) {
            return '请输入支付宝服务消息中的账单解压密码';
        }

        return '请输入账单解密密码或验证码';
    }

    private function appendEvent(BillTask $task, string $eventType, string $message): void
    {
        $task->events()->create([
            'event_type' => $eventType,
            'message'    => $message,
        ]);
    }
}
