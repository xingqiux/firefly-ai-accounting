<?php

declare(strict_types=1);

namespace FireflyIII\Services\BillIngestion;

use Carbon\Carbon;
use FireflyIII\Models\BillTask;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BillTaskActionService
{
    public function ignore(BillTask $billTask): BillTask
    {
        $billTask->status                      = 'ignored';
        $billTask->error_code                  = null;
        $billTask->error_message               = null;
        $billTask->current_secret_challenge_id = null;
        $billTask->save();

        $this->appendEvent($billTask, 'task.ignored', '任务已忽略');

        return $billTask->refresh();
    }

    public function retry(BillTask $billTask): BillTask
    {
        $billTask->status        = 'received';
        $billTask->error_code    = null;
        $billTask->error_message = null;
        $billTask->save();

        $this->appendEvent($billTask, 'task.retry_requested', '任务已重新排队');

        return $billTask->refresh();
    }

    public function submitSecret(BillTask $billTask, string $secret): BillTask
    {
        if ('' === trim($secret)) {
            throw new RuntimeException('Secret value must not be blank.');
        }

        $challenge = $billTask->currentSecretChallenge;
        if (null === $challenge || 'open' !== $challenge->status) {
            throw new RuntimeException('This bill task has no open secret challenge.');
        }

        DB::transaction(function () use ($billTask, $challenge): void {
            $challenge->status      = 'consumed';
            $challenge->attempts    = $challenge->attempts + 1;
            $challenge->consumed_at = Carbon::now();
            $challenge->save();

            $billTask->status                      = 'ready';
            $billTask->current_secret_challenge_id = null;
            $billTask->error_code                  = null;
            $billTask->error_message               = null;
            $billTask->save();

            $this->appendEvent($billTask, 'task.ready', '任务已准备处理');
            $this->appendEvent($billTask, 'challenge.consumed', '验证码/密码已提交');
        });

        return $billTask->refresh();
    }

    private function appendEvent(BillTask $billTask, string $eventType, string $message): void
    {
        $billTask->events()->create([
            'event_type' => $eventType,
            'message'    => $message,
        ]);
    }
}
