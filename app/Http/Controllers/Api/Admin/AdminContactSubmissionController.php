<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContactSubmission;
use App\Support\Iso8601;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminContactSubmissionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $perPage = (int) ($validated['per_page'] ?? 20);
        $paginator = ContactSubmission::query()
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'data' => $paginator->getCollection()
                ->map(fn (ContactSubmission $c) => $this->serialize($c))
                ->values()
                ->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $contact = ContactSubmission::query()->findOrFail($id);

        return response()->json($this->serialize($contact));
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(ContactSubmission $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'email' => $c->email,
            'message' => $c->message,
            'created_at' => Iso8601::utcZ($c->created_at),
        ];
    }
}
