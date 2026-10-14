<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounts\ReconcileAccountRequest;
use App\Http\Resources\ReconciliationResource;
use App\Models\Account;
use App\Services\ReconciliationService;
use Illuminate\Http\JsonResponse;

class ReconciliationController extends Controller
{
    public function index(Account $account)
    {
        return ReconciliationResource::collection(
            $account->reconciliations()->orderByDesc('reconciled_on')->orderByDesc('id')->paginate(25)
        );
    }

    public function store(ReconcileAccountRequest $request, Account $account, ReconciliationService $service): JsonResponse
    {
        $data = $request->validated();

        $reconciliation = $service->reconcile(
            $account,
            $data['actual_balance'],
            $data['reconciled_on'],
            (bool) ($data['post_adjustment'] ?? false),
            $data['notes'] ?? null,
        );

        return (new ReconciliationResource($reconciliation))->response()->setStatusCode(201);
    }
}
