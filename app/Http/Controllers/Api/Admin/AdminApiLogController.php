<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\AdminApiLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminApiLogController extends Controller
{
    public function index(Request $request, AdminApiLogService $service): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'user_id' => ['sometimes', 'nullable', 'integer'],
            'endpoint' => ['sometimes', 'nullable', 'string', 'max:255'],
            'method' => ['sometimes', 'nullable', 'string', 'max:16'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 50);
        $filters = [
            'user_id' => $validated['user_id'] ?? null,
            'endpoint' => $validated['endpoint'] ?? null,
            'method' => $validated['method'] ?? null,
        ];

        $paginator = $service->paginate($perPage, $filters);

        return response()->json([
            'data' => $paginator->getCollection()->map(fn ($log) => $service->serializeLog($log))->values()->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
            'context' => [
                'log_kind' => 'upstream_api_cache',
                'description' => 'Internal shared cache layer for third-party SEO/API providers. One row per cache resolve (including hits), not per end-user feature. For user-facing runs use GET /api/admin/activity/*.',
                'catalog' => ['method' => 'GET', 'path' => '/api/admin/activity/catalog'],
            ],
        ]);
    }

    public function export(Request $request, AdminApiLogService $service): StreamedResponse
    {
        $validated = $request->validate([
            'user_id' => ['sometimes', 'nullable', 'integer'],
            'endpoint' => ['sometimes', 'nullable', 'string', 'max:255'],
            'method' => ['sometimes', 'nullable', 'string', 'max:16'],
        ]);

        return $service->exportCsv([
            'user_id' => $validated['user_id'] ?? null,
            'endpoint' => $validated['endpoint'] ?? null,
            'method' => $validated['method'] ?? null,
        ]);
    }
}
