<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\AdminDashboardService;
use Illuminate\Http\JsonResponse;

class AdminDashboardController extends Controller
{
    public function stats(AdminDashboardService $dashboard): JsonResponse
    {
        return response()->json($dashboard->getCachedStats());
    }

    public function charts(AdminDashboardService $dashboard): JsonResponse
    {
        return response()->json($dashboard->getCachedCharts());
    }
}
