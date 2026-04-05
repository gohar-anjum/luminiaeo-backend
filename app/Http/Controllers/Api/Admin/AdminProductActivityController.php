<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\AdminProductActivityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * User-facing product activity (per feature). Prefer these over {@see AdminApiLogController}
 * when showing what customers did in the app.
 */
class AdminProductActivityController extends Controller
{
    public function catalog(AdminProductActivityService $activity): JsonResponse
    {
        return response()->json($activity->catalog());
    }

    public function faqTasks(Request $request, AdminProductActivityService $activity): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'user_id' => ['sometimes', 'nullable', 'integer'],
            'status' => ['sometimes', 'nullable', 'string', 'max:32'],
        ]);

        $p = $activity->paginateFaqTasks((int) ($validated['per_page'] ?? 50), [
            'user_id' => $validated['user_id'] ?? null,
            'status' => $validated['status'] ?? null,
        ]);

        return $this->jsonPage($p, fn ($row) => $activity->serializeFaqTask($row));
    }

    public function citationTasks(Request $request, AdminProductActivityService $activity): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'user_id' => ['sometimes', 'nullable', 'integer'],
            'status' => ['sometimes', 'nullable', 'string', 'max:32'],
        ]);

        $p = $activity->paginateCitationTasks((int) ($validated['per_page'] ?? 50), [
            'user_id' => $validated['user_id'] ?? null,
            'status' => $validated['status'] ?? null,
        ]);

        return $this->jsonPage($p, fn ($row) => $activity->serializeCitationTask($row));
    }

    public function keywordResearch(Request $request, AdminProductActivityService $activity): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'user_id' => ['sometimes', 'nullable', 'integer'],
            'status' => ['sometimes', 'nullable', 'string', 'max:32'],
        ]);

        $p = $activity->paginateKeywordResearch((int) ($validated['per_page'] ?? 50), [
            'user_id' => $validated['user_id'] ?? null,
            'status' => $validated['status'] ?? null,
        ]);

        return $this->jsonPage($p, fn ($row) => $activity->serializeKeywordResearchJob($row));
    }

    public function metaAnalyses(Request $request, AdminProductActivityService $activity): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'user_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        $p = $activity->paginateMetaAnalyses((int) ($validated['per_page'] ?? 50), [
            'user_id' => $validated['user_id'] ?? null,
        ]);

        return $this->jsonPage($p, fn ($row) => $activity->serializeMetaAnalysis($row));
    }

    public function semanticAnalyses(Request $request, AdminProductActivityService $activity): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'user_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        $p = $activity->paginateSemanticAnalyses((int) ($validated['per_page'] ?? 50), [
            'user_id' => $validated['user_id'] ?? null,
        ]);

        return $this->jsonPage($p, fn ($row) => $activity->serializeSemanticAnalysis($row));
    }

    public function contentOutlines(Request $request, AdminProductActivityService $activity): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'user_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        $p = $activity->paginateContentOutlines((int) ($validated['per_page'] ?? 50), [
            'user_id' => $validated['user_id'] ?? null,
        ]);

        return $this->jsonPage($p, fn ($row) => $activity->serializeContentOutline($row));
    }

    public function faqs(Request $request, AdminProductActivityService $activity): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'user_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        $p = $activity->paginateFaqs((int) ($validated['per_page'] ?? 50), [
            'user_id' => $validated['user_id'] ?? null,
        ]);

        return $this->jsonPage($p, fn ($row) => $activity->serializeFaq($row));
    }

    public function pbnDetections(Request $request, AdminProductActivityService $activity): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'status' => ['sometimes', 'nullable', 'string', 'max:64'],
            'domain' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $p = $activity->paginatePbnDetections((int) ($validated['per_page'] ?? 50), [
            'status' => $validated['status'] ?? null,
            'domain' => $validated['domain'] ?? null,
        ]);

        return $this->jsonPage($p, fn ($row) => $activity->serializePbnDetection($row));
    }

    public function clusterJobs(Request $request, AdminProductActivityService $activity): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'user_id' => ['sometimes', 'nullable', 'integer'],
            'status' => ['sometimes', 'nullable', 'string', 'max:32'],
        ]);

        $p = $activity->paginateClusterJobs((int) ($validated['per_page'] ?? 50), [
            'user_id' => $validated['user_id'] ?? null,
            'status' => $validated['status'] ?? null,
        ]);

        return $this->jsonPage($p, fn ($row) => $activity->serializeClusterJob($row));
    }

    /**
     * @param  \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, mixed>  $paginator
     * @param  callable(object): array<string, mixed>  $serialize
     */
    protected function jsonPage($paginator, callable $serialize): JsonResponse
    {
        return response()->json([
            'data' => $paginator->getCollection()->map($serialize)->values()->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }
}
