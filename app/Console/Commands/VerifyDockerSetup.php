<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\DB;

class VerifyDockerSetup extends Command
{
    protected $signature = 'docker:verify';
    protected $description = 'Verify all Docker services are running and accessible';

    public function handle()
    {
        $this->info('Verifying Docker setup...');
        $this->newLine();

        $checks = [
            'Database Connection' => fn() => $this->checkDatabase(),
            'Redis Connection' => fn() => $this->checkRedis(),
            'Clustering Service' => fn() => $this->checkClusteringService(),
            'PBN Detector Service' => fn() => $this->checkPbnDetector(),
        ];

        $results = [];
        foreach ($checks as $name => $check) {
            $this->info("Checking {$name}...");
            try {
                $result = $check();
                $results[$name] = $result;
                if ($result['status'] === 'ok') {
                    $this->info("  ✓ {$name}: OK");
                } else {
                    $this->error("  ✗ {$name}: {$result['message']}");
                }
            } catch (\Exception $e) {
                $results[$name] = ['status' => 'error', 'message' => $e->getMessage()];
                $this->error("  ✗ {$name}: {$e->getMessage()}");
            }
            $this->newLine();
        }

        $this->info('Summary:');
        $passed = count(array_filter($results, fn($r) => $r['status'] === 'ok'));
        $total = count($results);

        if ($passed === $total) {
            $this->info("✓ All checks passed ({$passed}/{$total})");
            return 0;
        } else {
            $this->error("✗ Some checks failed ({$passed}/{$total} passed)");
            return 1;
        }
    }

    protected function checkDatabase(): array
    {
        try {
            DB::connection()->getPdo();
            $tables = DB::select('SHOW TABLES');
            return [
                'status' => 'ok',
                'message' => 'Connected',
                'tables' => count($tables),
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }

    protected function checkRedis(): array
    {
        try {
            Redis::ping();
            $info = Redis::info();
            return [
                'status' => 'ok',
                'message' => 'Connected',
                'version' => $info['redis_version'] ?? 'unknown',
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }

    protected function checkClusteringService(): array
    {
        $url = config('services.keyword_clustering.url', 'http://localhost:8000');
        try {
            $response = Http::timeout(5)->get("{$url}/health");
            if ($response->successful()) {
                $data = $response->json();
                return [
                    'status' => 'ok',
                    'message' => 'Service is healthy',
                    'model_loaded' => $data['model_loaded'] ?? false,
                ];
            }
            return [
                'status' => 'error',
                'message' => 'Service returned non-200 status',
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }

    protected function checkPbnDetector(): array
    {
        $url = config('services.pbn_detector.base_url', 'http://localhost:9000');
        try {
            $response = Http::timeout(5)->get("{$url}/health");
            if ($response->successful()) {
                return [
                    'status' => 'ok',
                    'message' => 'Service is healthy',
                ];
            }
            return [
                'status' => 'error',
                'message' => 'Service returned non-200 status',
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }
}
