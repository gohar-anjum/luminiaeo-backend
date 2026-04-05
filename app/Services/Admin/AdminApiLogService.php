<?php

namespace App\Services\Admin;

use App\Models\ApiRequestLog;
use App\Support\Iso8601;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reads {@see ApiRequestLog} rows written by {@see \App\Services\ApiCache\ApiCacheService} (upstream API cache).
 * The JSON {@code endpoint} / {@code method} fields match the admin contract using stable synthetic values.
 */
class AdminApiLogService
{
    /**
     * Synthetic path so admins can filter by provider or feature substring.
     */
    public function formatEndpoint(ApiRequestLog $log): string
    {
        return '/api/upstream/'.$log->api_provider.'/'.$log->feature;
    }

    /**
     * Upstream fetches are HTTP POST in practice for most providers.
     */
    public function formatMethod(ApiRequestLog $log): string
    {
        return 'POST';
    }

    /**
     * @param  array{user_id?: int|null, endpoint?: string|null, method?: string|null}  $filters
     * @return LengthAwarePaginator<int, ApiRequestLog>
     */
    public function paginate(int $perPage = 50, array $filters = []): LengthAwarePaginator
    {
        $q = ApiRequestLog::query()->orderByDesc('id');

        if (! empty($filters['user_id'])) {
            $q->where('user_id', (int) $filters['user_id']);
        }

        if (! empty($filters['endpoint'])) {
            $term = '%'.$filters['endpoint'].'%';
            $q->where(function ($w) use ($term) {
                $w->where('api_provider', 'like', $term)
                    ->orWhere('feature', 'like', $term);
            });
        }

        if (! empty($filters['method']) && strtoupper($filters['method']) !== 'POST') {
            $q->whereRaw('1 = 0');
        }

        return $q->paginate($perPage);
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeLog(ApiRequestLog $log): array
    {
        return [
            'id' => $log->id,
            'user_id' => $log->user_id,
            'log_kind' => 'upstream_api_cache',
            'endpoint' => $this->formatEndpoint($log),
            'method' => $this->formatMethod($log),
            'api_provider' => $log->api_provider,
            'api_feature' => $log->feature,
            'query_summary' => $this->buildQuerySummary($log),
            'response_time_ms' => $log->response_time_ms,
            'status_code' => $log->response_status,
            'cache_hit' => (bool) $log->was_cache_hit,
            'created_at' => Iso8601::utcZ($log->created_at),
        ];
    }

    /**
     * Short human-readable hint from {@see ApiRequestLog::$request_payload} (upstream cache params).
     */
    public function buildQuerySummary(ApiRequestLog $log): ?string
    {
        $payload = $log->request_payload;
        if (! is_array($payload) || $payload === []) {
            return null;
        }

        $parts = [];

        foreach (['keyword', 'q', 'query', 'search_query', 'text'] as $key) {
            if (! empty($payload[$key]) && is_string($payload[$key])) {
                $parts[] = $key.': '.Str::limit(trim($payload[$key]), 96);

                break;
            }
        }

        if (! empty($payload['url']) && is_string($payload['url'])) {
            $parts[] = 'url: '.Str::limit(trim($payload['url']), 96);
        }

        if (! empty($payload['terms']) && is_array($payload['terms'])) {
            $terms = array_slice(array_map('strval', $payload['terms']), 0, 4);
            $parts[] = 'terms: '.Str::limit(implode(', ', $terms), 120);
        }

        if (! empty($payload['target']) && is_string($payload['target'])) {
            $parts[] = 'target: '.Str::limit(trim($payload['target']), 96);
        }

        if ($parts === []) {
            return null;
        }

        return implode('; ', array_unique($parts));
    }

    /**
     * @param  array{user_id?: int|null, endpoint?: string|null, method?: string|null}  $filters
     */
    public function exportCsv(array $filters = []): StreamedResponse
    {
        $filename = 'api_logs_'.now()->format('Y-m-d_His').'.csv';

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ];

        $q = ApiRequestLog::query()->orderBy('id');
        if (! empty($filters['user_id'])) {
            $q->where('user_id', (int) $filters['user_id']);
        }
        if (! empty($filters['endpoint'])) {
            $term = '%'.$filters['endpoint'].'%';
            $q->where(function ($w) use ($term) {
                $w->where('api_provider', 'like', $term)
                    ->orWhere('feature', 'like', $term);
            });
        }
        if (! empty($filters['method']) && strtoupper($filters['method']) !== 'POST') {
            $q->whereRaw('1 = 0');
        }

        $service = $this;

        return response()->streamDownload(function () use ($q, $service): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['id', 'user_id', 'log_kind', 'api_provider', 'api_feature', 'endpoint', 'method', 'query_summary', 'response_time_ms', 'status_code', 'cache_hit', 'created_at']);

            $q->chunkById(500, function ($chunk) use ($out, $service): void {
                foreach ($chunk as $log) {
                    /** @var ApiRequestLog $log */
                    fputcsv($out, [
                        $log->id,
                        $log->user_id,
                        'upstream_api_cache',
                        $log->api_provider,
                        $log->feature,
                        $service->formatEndpoint($log),
                        $service->formatMethod($log),
                        $service->buildQuerySummary($log) ?? '',
                        $log->response_time_ms,
                        $log->response_status,
                        $log->was_cache_hit ? '1' : '0',
                        $log->created_at?->toIso8601String() ?? '',
                    ]);
                }
            });
            fclose($out);
        }, $filename, $headers);
    }
}
