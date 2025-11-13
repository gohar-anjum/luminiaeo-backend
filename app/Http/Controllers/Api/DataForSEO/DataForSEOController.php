<?php

namespace App\Http\Controllers\Api\DataForSEO;

use App\Http\Controllers\Controller;
use App\Services\ApiResponseModifier;
use App\Services\DataForSEO\DataForSEOService;
use Illuminate\Http\Request;

class DataForSEOController extends Controller
{
    protected DataForSEOService $service;
    protected ApiResponseModifier $responseModifier;

    public function __construct(DataForSEOService $service , ApiResponseModifier $responseModifier)
    {
        $this->service = $service;
        $this->responseModifier = $responseModifier;
    }

    public function searchVolume(Request $request)
    {
        $keywords = $request->input('keywords', ['laravel development']);
        $data = $this->service->getSearchVolume($keywords);
        return $this->responseModifier->setData($data)->response();
    }
}
