<?php

namespace App\Services\Keyword;

use App\DTOs\KeywordDataDTO;
use App\Models\InformationalPlannerQuery;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use JsonException;

class InformationalPlannerPoolService
{
    public function __construct(
        protected InformationalPlannerKeywordStorageService $keywordStorage
    ) {}

    /**
     * @param  array{location_code: int, language_code: string, limit: int, top_n: int}  $options
     * @param  \Closure(): array<int, KeywordDataDTO>  $fetchFreshDtos
     * @return array{query: InformationalPlannerQuery, bill: bool, data: array<int, array<string, mixed>>, served_from: 'shared_pool'|'fresh'}
     *
     * @throws JsonException
     */
    public function resolve(
        User $user,
        array $seeds,
        array $options,
        \Closure $fetchFreshDtos
    ): array {
        $fingerprint = $this->fingerprint($seeds, $options);

        $shared = $this->takeFromPoolIfAny($fingerprint, $user);
        if ($shared !== null) {
            return $shared;
        }

        $lockKey = 'infoplan:'.hash('sha256', $fingerprint);

        return Cache::lock($lockKey, 90)->block(60, function () use ($fingerprint, $user, $seeds, $options, $fetchFreshDtos) {
            $shared = $this->takeFromPoolIfAny($fingerprint, $user);
            if ($shared !== null) {
                return $shared;
            }

            $dtos = $fetchFreshDtos();
            $data = array_map(fn (KeywordDataDTO $dto) => $dto->toArray(), $dtos);

            $days = max(1, (int) config('services.informational_planner.dedup_ttl_days', 14));

            return DB::transaction(function () use ($fingerprint, $user, $seeds, $options, $dtos, $data, $days) {
                $query = InformationalPlannerQuery::query()
                    ->where('fingerprint', $fingerprint)
                    ->notExpired()
                    ->lockForUpdate()
                    ->first();

                if ($query) {
                    $query->users()->syncWithoutDetaching([$user->id]);

                    return [
                        'query' => $query,
                        'bill' => false,
                        'data' => is_array($query->keywords) ? $query->keywords : $data,
                        'served_from' => 'shared_pool',
                    ];
                }

                $query = InformationalPlannerQuery::create([
                    'fingerprint' => $fingerprint,
                    'seeds' => $seeds,
                    'options' => $options,
                    'keywords' => $data,
                    'total_count' => count($data),
                    'expires_at' => now()->addDays($days),
                ]);
                $query->users()->syncWithoutDetaching([$user->id]);
                $this->keywordStorage->storeForQuery($query, $dtos, $options);

                return [
                    'query' => $query,
                    'bill' => true,
                    'data' => $data,
                    'served_from' => 'fresh',
                ];
            });
        });
    }

    /**
     * @return array{query: InformationalPlannerQuery, bill: false, data: array<int, array<string, mixed>>, served_from: 'shared_pool'}|null
     */
    private function takeFromPoolIfAny(string $fingerprint, User $user): ?array
    {
        $query = InformationalPlannerQuery::query()
            ->notExpired()
            ->where('fingerprint', $fingerprint)
            ->first();

        if (! $query) {
            return null;
        }

        $query->users()->syncWithoutDetaching([$user->id]);
        $data = is_array($query->keywords) ? $query->keywords : [];

        return [
            'query' => $query,
            'bill' => false,
            'data' => $data,
            'served_from' => 'shared_pool',
        ];
    }

    /**
     * @param  array{location_code: int, language_code: string, limit: int, top_n: int}  $options
     *
     * @throws JsonException
     */
    public function fingerprint(array $seeds, array $options): string
    {
        $normalized = $this->normalizeSeeds($seeds);
        $h = $this->optionsForHash($options);
        $payload = ['seeds' => $normalized, 'options' => $h];

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return list<string>
     */
    public function normalizeSeeds(array $seeds): array
    {
        $out = [];
        foreach ($seeds as $s) {
            $t = trim((string) $s);
            if ($t === '') {
                continue;
            }
            $out[] = mb_strtolower($t, 'UTF-8');
        }
        $out = array_values(array_unique($out));
        sort($out);

        return $out;
    }

    /**
     * @return array{location_code: int, language_code: string, limit: int, top_n: int}
     */
    public function optionsForHash(array $o): array
    {
        return [
            'location_code' => (int) ($o['location_code'] ?? 0),
            'language_code' => mb_strtolower((string) ($o['language_code'] ?? ''), 'UTF-8'),
            'limit' => (int) ($o['limit'] ?? 0),
            'top_n' => (int) ($o['top_n'] ?? 0),
        ];
    }
}
