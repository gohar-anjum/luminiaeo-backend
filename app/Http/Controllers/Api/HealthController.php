<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class HealthController extends Controller
{
    public function check(): JsonResponse
    {
        $status = 'healthy';
        $checks = [];
        $timestamp = now()->toIso8601String();

        try {
            DB::connection()->getPdo();
            $checks['database'] = 'ok';
        } catch (\Exception $e) {
            $checks['database'] = 'failed: ' . $e->getMessage();
            $status = 'unhealthy';
        }

        try {
            Cache::store('redis')->put('health_check', 'ok', 1);
            $checks['cache'] = 'ok';
        } catch (\Exception $e) {
            $checks['cache'] = 'failed: ' . $e->getMessage();
            $status = 'degraded';
        }

        try {
            if (class_exists('Redis')) {
                $redis = Redis::connection();
                $redis->ping();
                $checks['redis'] = 'ok';
            } else {
                $checks['redis'] = 'not_configured';
            }
        } catch (\Exception $e) {
            $checks['redis'] = 'failed: ' . $e->getMessage();
            $status = 'degraded';
        }

        $response = [
            'status' => $status,
            'timestamp' => $timestamp,
            'checks' => $checks,
        ];

        $httpStatus = $status === 'healthy' ? 200 : ($status === 'degraded' ? 200 : 503);

        return response()->json($response, $httpStatus);
    }
}

