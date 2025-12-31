<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LocationCode;
use App\Services\ApiResponseModifier;
use App\Traits\HasApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class LocationCodeController extends Controller
{
    use HasApiResponse;

    public function __construct(
        protected ApiResponseModifier $responseModifier,
    ) {
    }

    /**
     * Get all location codes
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $cacheKey = 'location_codes:index:' . md5(json_encode($request->all()));
        $cacheTTL = config('cache.location_codes_ttl', 86400); // 24 hours default

        $locationCodes = Cache::remember($cacheKey, $cacheTTL, function () use ($request) {
            $query = LocationCode::query();

            // Filter by location type (Country, Region, etc.)
            if ($request->has('type')) {
                $query->where('location_type', $request->input('type'));
            }

            // Filter by country ISO code
            if ($request->has('country_iso_code')) {
                $query->where('country_iso_code', $request->input('country_iso_code'));
            }

            // Get only countries (most common use case)
            if ($request->boolean('countries_only', false)) {
                $query->countries();
            }

            // Search by location name
            if ($request->has('search')) {
                $search = $request->input('search');
                $query->where('location_name', 'like', "%{$search}%");
            }

            // Sort
            $sortBy = $request->input('sort_by', 'location_name');
            $sortOrder = $request->input('sort_order', 'asc');
            $query->orderBy($sortBy, $sortOrder);

            // Pagination
            $perPage = min((int) $request->input('per_page', 50), 500);
            return $query->paginate($perPage);
        });

        return $this->responseModifier
            ->setData([
                'location_codes' => $locationCodes->items(),
                'pagination' => [
                    'current_page' => $locationCodes->currentPage(),
                    'per_page' => $locationCodes->perPage(),
                    'total' => $locationCodes->total(),
                    'last_page' => $locationCodes->lastPage(),
                ],
            ])
            ->setMessage('Location codes retrieved successfully')
            ->response();
    }

    /**
     * Get all countries only
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function countries()
    {
        $cacheKey = 'location_codes:countries';
        $cacheTTL = config('cache.location_codes_ttl', 86400); // 24 hours default

        $countries = Cache::remember($cacheKey, $cacheTTL, function () {
            return LocationCode::countries()
                ->orderBy('location_name')
                ->get();
        });

        return $this->responseModifier
            ->setData([
                'countries' => $countries,
                'total' => $countries->count(),
            ])
            ->setMessage('Countries retrieved successfully')
            ->response();
    }

    /**
     * Get location code by code
     * 
     * @param int $locationCode
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(int $locationCode)
    {
        $cacheKey = "location_codes:show:{$locationCode}";
        $cacheTTL = config('cache.location_codes_ttl', 86400); // 24 hours default

        $location = Cache::remember($cacheKey, $cacheTTL, function () use ($locationCode) {
            return LocationCode::where('location_code', $locationCode)->first();
        });

        if (!$location) {
            return $this->responseModifier
                ->setMessage('Location code not found')
                ->setResponseCode(404)
                ->response();
        }

        return $this->responseModifier
            ->setData(['location_code' => $location])
            ->setMessage('Location code retrieved successfully')
            ->response();
    }
}
