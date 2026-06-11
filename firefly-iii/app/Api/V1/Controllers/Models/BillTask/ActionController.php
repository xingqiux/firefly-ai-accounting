<?php

declare(strict_types=1);

namespace FireflyIII\Api\V1\Controllers\Models\BillTask;

use FireflyIII\Api\V1\Controllers\Controller;
use FireflyIII\Models\BillTask;
use FireflyIII\Services\BillIngestion\BillTaskActionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class ActionController extends Controller
{
    use BillTaskResponse;

    public function __construct(private readonly BillTaskActionService $actionService) {}

    public function ignore(BillTask $billTask): JsonResponse
    {
        return response()->json($this->itemResponse($this->actionService->ignore($billTask)));
    }

    public function retry(BillTask $billTask): JsonResponse
    {
        return response()->json($this->itemResponse($this->actionService->retry($billTask)));
    }

    public function secret(Request $request, BillTask $billTask): JsonResponse
    {
        $request->validate([
            'value' => ['required', 'string', 'min:1'],
        ]);

        try {
            return response()->json($this->itemResponse($this->actionService->submitSecret($billTask, (string) $request->string('value'))));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
