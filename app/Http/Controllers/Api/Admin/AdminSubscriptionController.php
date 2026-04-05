<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\AdminSubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Laravel\Cashier\Subscription;

class AdminSubscriptionController extends Controller
{
    public function index(Request $request, AdminSubscriptionService $service): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 30);
        $paginator = $service->paginate($perPage);

        return response()->json([
            'data' => $paginator->getCollection()->map(fn (Subscription $s) => $service->serializeSubscription($s))->values()->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(int $id, AdminSubscriptionService $service): JsonResponse
    {
        if (! Schema::hasTable('subscriptions')) {
            abort(404);
        }

        $sub = Subscription::query()->with('items')->findOrFail($id);

        return response()->json($service->serializeSubscription($sub));
    }
}
