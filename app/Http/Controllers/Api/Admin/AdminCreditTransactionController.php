<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\AdminCreditTransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminCreditTransactionController extends Controller
{
    public function index(Request $request, AdminCreditTransactionService $service): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'user_id' => ['sometimes', 'nullable', 'integer'],
            'type' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 50);
        $filters = [
            'user_id' => $validated['user_id'] ?? null,
            'type' => $validated['type'] ?? null,
        ];

        $paginator = $service->paginate($perPage, $filters);

        return response()->json([
            'data' => $paginator->getCollection()->map(fn ($tx) => $service->serializeTransaction($tx))->values()->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function export(Request $request, AdminCreditTransactionService $service): StreamedResponse
    {
        $validated = $request->validate([
            'user_id' => ['sometimes', 'nullable', 'integer'],
            'type' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        return $service->exportCsv([
            'user_id' => $validated['user_id'] ?? null,
            'type' => $validated['type'] ?? null,
        ]);
    }
}
