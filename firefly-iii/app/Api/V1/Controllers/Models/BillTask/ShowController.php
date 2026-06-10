<?php

declare(strict_types=1);

namespace FireflyIII\Api\V1\Controllers\Models\BillTask;

use FireflyIII\Api\V1\Controllers\Controller;
use FireflyIII\Api\V1\Requests\PaginationRequest;
use FireflyIII\Models\BillTask;
use FireflyIII\User;
use Illuminate\Http\JsonResponse;

final class ShowController extends Controller
{
    use BillTaskResponse;

    public function index(PaginationRequest $request): JsonResponse
    {
        ['limit' => $limit, 'page' => $page] = $request->attributes->all();

        /** @var User $user */
        $user                                = auth()->user();

        $paginator                           = $user->billTasks()
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->paginate($limit, ['*'], 'page', $page)
        ;

        return response()->json($this->collectionResponse($paginator));
    }

    public function show(BillTask $billTask): JsonResponse
    {
        $billTask->load([
            'mailMessage',
            'artifacts'              => fn ($query) => $query->orderBy('id'),
            'currentSecretChallenge',
            'events'                 => fn ($query) => $query->orderBy('id'),
        ]);

        return response()->json($this->itemResponse($billTask, true));
    }
}
