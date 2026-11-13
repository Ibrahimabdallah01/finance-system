<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\ReportPeriodRequest;
use App\Services\ProfitReportService;
use Illuminate\Http\JsonResponse;

class ProfitReportController extends Controller
{
    public function __invoke(ReportPeriodRequest $request, ProfitReportService $report): JsonResponse
    {
        $from = $request->validated('from') ?? now()->startOfMonth()->toDateString();
        $to = $request->validated('to') ?? now()->toDateString();

        return response()->json(['data' => $report->profitAndLoss($from, $to)]);
    }
}
