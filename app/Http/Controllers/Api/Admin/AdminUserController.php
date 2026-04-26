<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdjustCreditsRequest;
use App\Http\Requests\Admin\AdminStoreUserRequest;
use App\Models\User;
use App\Services\Admin\AdminProductActivityService;
use App\Services\Admin\AdminUserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminUserController extends Controller
{
    public function store(AdminStoreUserRequest $request, AdminUserService $users): JsonResponse
    {
        $validated = $request->validated();
        $user = $users->createCustomerUser(
            $validated['name'],
            $validated['email'],
            $validated['password'],
        );

        return response()->json($users->serializeUser($user), 201);
    }

    public function index(Request $request, AdminUserService $users): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'suspended' => ['sometimes', 'nullable', 'boolean'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 20);
        $filters = [
            'search' => $validated['search'] ?? null,
            'suspended' => array_key_exists('suspended', $validated) ? (bool) $validated['suspended'] : null,
        ];

        $paginator = $users->paginate($perPage, $filters);

        return response()->json([
            'data' => $paginator->getCollection()->map(fn (User $u) => $users->serializeUser($u))->values()->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(Request $request, int $id, AdminUserService $users, AdminProductActivityService $activity): JsonResponse
    {
        $user = User::query()->findOrFail($id);
        abort_if($user->is_admin, 404);

        $payload = $users->serializeUser($user);
        if ($request->boolean('include_product_activity')) {
            $payload['product_activity_counts'] = $activity->countsForUser($user->id);
        }

        return response()->json($payload);
    }

    public function suspend(int $id, AdminUserService $users): JsonResponse
    {
        $user = User::query()->findOrFail($id);
        abort_if($user->is_admin, 403, 'Admin accounts cannot be managed through customer user endpoints.');
        $users->suspend($user);

        return response()->json($users->serializeUser($user->fresh()));
    }

    public function unsuspend(int $id, AdminUserService $users): JsonResponse
    {
        $user = User::query()->findOrFail($id);
        abort_if($user->is_admin, 403, 'Admin accounts cannot be managed through customer user endpoints.');
        $users->unsuspend($user);

        return response()->json($users->serializeUser($user->fresh()));
    }

    public function adjustCredits(int $id, AdjustCreditsRequest $request, AdminUserService $users): JsonResponse
    {
        $user = User::query()->findOrFail($id);
        abort_if($user->is_admin, 403, 'Admin accounts cannot be managed through customer user endpoints.');
        $data = $request->validated();
        $amount = (int) $data['amount'];
        $note = isset($data['note']) ? trim((string) $data['note']) : null;
        if ($note === '') {
            $note = null;
        }
        $transaction = $users->adjustCredits($user, $amount, $request->user()?->id, $note);
        $user = $user->fresh();

        return response()->json([
            'user' => $users->serializeUser($user),
            'transaction' => $users->serializeCreditTransaction($transaction),
        ]);
    }
}
