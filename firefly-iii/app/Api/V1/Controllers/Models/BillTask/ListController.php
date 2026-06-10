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
}
