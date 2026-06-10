<?php

declare(strict_types=1);

namespace FireflyIII\Api\V1\Controllers\Models\BillTask;

use Carbon\Carbon;
use FireflyIII\Api\V1\Controllers\Controller;
use FireflyIII\Models\BillTask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ActionController extends Controller
{
    use BillTaskResponse;

    public function ignore(BillTask $billTask): JsonResponse
    {
        $billTask->status                      = 'ignored';
        $billTask->error_code                  = null;
        $billTask->error_message               = null;
        $billTask->current_secret_challenge_id = null;
        $billTask->save();

        $this->appendEvent($billTask, 'task.ignored', '任务已忽略');

        return response()->json($this->itemResponse($billTask->refresh()));
    }

    public function retry(BillTask $billTask): JsonResponse
    {
        $billTask->status        = 'received';
        $billTask->error_code    = null;
        $billTask->error_message = null;
        $billTask->save();

        $this->appendEvent($billTask, 'task.retry_requested', '任务已重新排队');

        return response()->json($this->itemResponse($billTask->refresh()));
    }

    public function secret(Request $request, BillTask $billTask): JsonResponse
    {
        $request->validate([
            'value' => ['required', 'string', 'min:1'],
        ]);

        $challenge = $billTask->currentSecretChallenge;
        if (null === $challenge || 'open' !== $challenge->status) {
            return response()->json(['message' => 'This bill task has no open secret challenge.'], 422);
        }

        DB::transaction(function () use ($billTask, $challenge): void {
            $challenge->status       = 'consumed';
            $challenge->attempts     = $challenge->attempts + 1;
            $challenge->consumed_at  = Carbon::now();
            $challenge->save();

            $billTask->status                      = 'ready';
            $billTask->current_secret_challenge_id = null;
            $billTask->error_code                  = null;
            $billTask->error_message               = null;
            $billTask->save();

            $this->appendEvent($billTask, 'task.ready', '任务已准备处理');
            $this->appendEvent($billTask, 'challenge.consumed', '验证码/密码已提交');
        });

        return response()->json($this->itemResponse($billTask->refresh()));
    }

    private function appendEvent(BillTask $billTask, string $eventType, string $message): void
    {
        $billTask->events()->create([
            'event_type' => $eventType,
            'message'    => $message,
        ]);
    }
}
