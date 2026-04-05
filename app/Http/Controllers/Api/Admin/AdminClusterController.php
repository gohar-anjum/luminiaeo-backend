<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\KeywordClusterSnapshot;
use App\Services\Admin\AdminClusterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminClusterController extends Controller
{
    public function index(Request $request, AdminClusterService $clusters): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 30);
        $paginator = $clusters->paginateClusters($perPage);

        return response()->json([
            'data' => $paginator->getCollection()->map(fn (KeywordClusterSnapshot $s) => $clusters->serializeCluster($s))->values()->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function snapshots(int $id, AdminClusterService $clusters): JsonResponse
    {
        $anchor = KeywordClusterSnapshot::query()->findOrFail($id);

        return response()->json([
            'data' => $clusters->snapshotsForCluster($anchor),
        ]);
    }
}
