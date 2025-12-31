<?php

namespace App\Repositories;

use App\Interfaces\CitationRepositoryInterface;
use App\Models\CitationTask;
use Illuminate\Support\Facades\DB;

class CitationRepository implements CitationRepositoryInterface
{
    public function create(array $attributes): CitationTask
    {
        return CitationTask::create($attributes);
    }

    public function find(int $id): ?CitationTask
    {
        return CitationTask::find($id);
    }

    public function update(CitationTask $task, array $attributes): CitationTask
    {
        $task->fill($attributes);
        $task->save();

        return $task;
    }

    public function appendResults(CitationTask $task, array $payload): CitationTask
    {
        return DB::transaction(function () use ($task, $payload) {
            $locked = CitationTask::lockForUpdate()->findOrFail($task->id);
            $results = $locked->results ?? [];

            if (isset($payload['queries']) && is_array($payload['queries'])) {
                $results['queries'] = $payload['queries'];
            }

            if (isset($payload['by_query']) && is_array($payload['by_query'])) {
                $existing = $results['by_query'] ?? [];
                $results['by_query'] = array_replace($existing, $payload['by_query']);
            }

            $progressPayload = $payload['progress'] ?? [];
            $progress = $results['progress'] ?? [];
            if (isset($progressPayload['total'])) {
                $progress['total'] = $progressPayload['total'];
            }
            if (isset($progressPayload['last_query_index'])) {
                $progress['last_query_index'] = $progressPayload['last_query_index'];
            }
            if (isset($progressPayload['processed_increment'])) {
                $progress['processed'] = ($progress['processed'] ?? 0) + $progressPayload['processed_increment'];
            } else {
                $progress['processed'] = count($results['by_query'] ?? []);
            }
            $progress['updated_at'] = now()->toIso8601String();
            $results['progress'] = $progress;

            $locked->results = $results;

            if (!empty($payload['status'])) {
                $locked->status = $payload['status'];
            }

            if (!empty($payload['meta'])) {
                $meta = $locked->meta ?? [];
                foreach ($payload['meta'] as $key => $value) {
                    $meta[$key] = $value;
                }
                $locked->meta = $meta;
            }

            $locked->save();

            return $locked;
        });
    }

    public function updateCompetitorsAndMeta(CitationTask $task, array $competitors, array $meta): CitationTask
    {
        return DB::transaction(function () use ($task, $competitors, $meta) {
            $locked = CitationTask::lockForUpdate()->findOrFail($task->id);
            $locked->competitors = $competitors;
            $mergedMeta = $locked->meta ?? [];
            foreach ($meta as $key => $value) {
                $mergedMeta[$key] = $value;
            }
            $locked->meta = $mergedMeta;
            $locked->status = $meta['status'] ?? $locked->status;
            $locked->save();

            return $locked;
        });
    }

    public function findCompletedByUrl(string $url, ?int $cacheDays = null): ?CitationTask
    {
        $normalizedUrl = $this->normalizeUrl($url);

        $query = CitationTask::where('url', $normalizedUrl)
            ->where('status', CitationTask::STATUS_COMPLETED)
            ->orderBy('created_at', 'desc');

        if ($cacheDays !== null) {
            $query->where('created_at', '>=', now()->subDays($cacheDays));
        }

        return $query->first();
    }

    public function findInProgressByUrl(string $url): ?CitationTask
    {
        $normalizedUrl = $this->normalizeUrl($url);

        return CitationTask::where('url', $normalizedUrl)
            ->whereIn('status', [
                CitationTask::STATUS_GENERATING,
                CitationTask::STATUS_QUEUED,
                CitationTask::STATUS_PROCESSING,
            ])
            ->orderBy('created_at', 'desc')
            ->first();
    }

    protected function normalizeUrl(string $url): string
    {
        $url = trim($url);

        if (!preg_match('/^https?:\/\//', $url)) {
            $url = 'https://' . $url;
        }

        $parsed = parse_url($url);
        if (!$parsed) {
            return $url;
        }

        $scheme = $parsed['scheme'] ?? 'https';
        $host = strtolower($parsed['host'] ?? '');
        $host = preg_replace('/^www\./', '', $host);
        $path = rtrim($parsed['path'] ?? '', '/');
        $query = $parsed['query'] ?? '';
        $fragment = $parsed['fragment'] ?? '';

        $normalized = $scheme . '://' . $host . $path;
        if ($query) {
            $normalized .= '?' . $query;
        }
        if ($fragment) {
            $normalized .= '#' . $fragment;
        }

        return $normalized;
    }
}
