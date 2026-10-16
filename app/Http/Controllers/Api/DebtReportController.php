<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Debts\ListDebtsRequest;
use App\Services\DebtSummaryService;
use Illuminate\Http\JsonResponse;

class DebtReportController extends Controller
{
    public function __construct(private readonly DebtSummaryService $summary)
    {
    }

    /** Money owed to me, money I owe, overdue items and what falls due in the next ?days (default 30). */
    public function summary(ListDebtsRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->summary->summary((int) ($request->validated('days') ?? 30))]);
    }

    /** Customers who still owe on sales, plus people I lent money to. */
    public function receivables(): JsonResponse
    {
        return response()->json(['data' => $this->summary->receivables()]);
    }
}
