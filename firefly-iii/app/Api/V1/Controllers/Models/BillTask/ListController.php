<?php

declare(strict_types=1);

namespace FireflyIII\Api\V1\Controllers\Models\BillTask;

use FireflyIII\Api\V1\Controllers\Controller;
use FireflyIII\Models\BillTask;
use Illuminate\Http\JsonResponse;

final class ListController extends Controller
{
    use BillTaskResponse;

    public function artifacts(BillTask $billTask): JsonResponse
    {
        return response()->json($this->artifactCollectionResponse($billTask->artifacts()->orderBy('id')->get()));
    }

    public function events(BillTask $billTask): JsonResponse
    {
        return response()->json($this->eventCollectionResponse($billTask->events()->orderBy('id')->get()));
    }

    public function rows(BillTask $billTask): JsonResponse
    {
        $query  = $billTask->statementRows()->orderBy('occurred_at', 'desc')->orderBy('row_number');
        $status = (string) request()->query('status', '');
        $from   = (string) request()->query('from', '');
        $to     = (string) request()->query('to', '');
        if ('' !== $status) {
            $query->where('status', $status);
        }
        if ('' !== $from) {
            $query->where('occurred_at', '>=', $from.' 00:00:00');
        }
        if ('' !== $to) {
            $query->where('occurred_at', '<=', $to.' 23:59:59');
        }

        return response()->json($this->rowCollectionResponse($query->get()));
    }
}
