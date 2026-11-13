<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\ReportPeriodRequest;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    /** Cards and charts for ?from=&to= (default: first day of this month to today). */
    public function __invoke(ReportPeriodRequest $request, DashboardService $dashboard): JsonResponse
    {
        $from = $request->validated('from') ?? now()->startOfMonth()->toDateString();
        $to = $request->validated('to') ?? now()->toDateString();

        return response()->json(['data' => $dashboard->build($from, $to)]);
    }
}
