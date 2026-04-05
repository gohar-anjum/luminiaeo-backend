<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Backlink;
use App\Services\Admin\AdminBacklinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminBacklinkController extends Controller
{
    public function index(Request $request, AdminBacklinkService $backlinks): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'status' => ['sometimes', 'nullable', 'in:pending,verified,failed'],
            'domain' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 50);
        $filters = [
            'status' => $validated['status'] ?? null,
            'domain' => $validated['domain'] ?? null,
        ];

        $paginator = $backlinks->paginate($perPage, $filters);

        return response()->json([
            'data' => $paginator->getCollection()->map(fn (Backlink $b) => $backlinks->serializeBacklink($b))->values()->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(int $id, AdminBacklinkService $backlinks): JsonResponse
    {
        $backlink = Backlink::query()->with(['seoTask.user'])->findOrFail($id);

        return response()->json($backlinks->serializeBacklink($backlink));
    }

    public function destroy(int $id, AdminBacklinkService $backlinks): Response
    {
        $backlink = Backlink::query()->findOrFail($id);
        $backlinks->delete($backlink);

        return response()->noContent();
    }

    public function verify(int $id, AdminBacklinkService $backlinks): JsonResponse
    {
        $backlink = Backlink::query()->with(['seoTask.user'])->findOrFail($id);
        $fresh = $backlinks->verify($backlink);

        return response()->json($backlinks->serializeBacklink($fresh));
    }
}
