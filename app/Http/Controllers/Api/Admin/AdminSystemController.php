<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAnnouncementRequest;
use App\Services\Admin\AdminSystemService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class AdminSystemController extends Controller
{
    public function clearCache(AdminSystemService $system): JsonResponse
    {
        $system->clearApplicationCache();

        return response()->json(['ok' => true]);
    }

    public function health(AdminSystemService $system): JsonResponse
    {
        return response()->json($system->health());
    }

    public function storeAnnouncement(StoreAnnouncementRequest $request, AdminSystemService $system): JsonResponse
    {
        $data = $system->createAnnouncement(
            $request->validated('title'),
            $request->validated('body'),
            $request->user()?->id
        );

        return response()->json($data, Response::HTTP_CREATED);
    }
}
