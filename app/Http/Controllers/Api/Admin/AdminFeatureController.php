<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Billing\Models\Feature;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAdminFeatureRequest;
use App\Http\Requests\Admin\UpdateAdminFeatureRequest;
use App\Services\Admin\AdminFeatureService;
use Illuminate\Http\JsonResponse;

class AdminFeatureController extends Controller
{
    public function index(AdminFeatureService $features): JsonResponse
    {
        $rows = $features->allOrdered()->map(fn (Feature $f) => $features->serialize($f))->values()->all();

        return response()->json(['data' => $rows]);
    }

    public function store(StoreAdminFeatureRequest $request, AdminFeatureService $features): JsonResponse
    {
        $feature = $features->create($request->featurePayload());

        return response()->json($features->serialize($feature), JsonResponse::HTTP_CREATED);
    }

    public function update(UpdateAdminFeatureRequest $request, int $id, AdminFeatureService $features): JsonResponse
    {
        $feature = Feature::query()->findOrFail($id);
        $updated = $features->update($feature, $request->featurePatch());

        return response()->json($features->serialize($updated));
    }
}
