<?php

namespace App\Services\Keyword;

use App\DTOs\KeywordDataDTO;
use App\Models\InformationalPlannerQuery;
use App\Models\Keyword;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class InformationalPlannerKeywordStorageService
{
    /**
     * Persist one row per keyword for a shared pool query (first paying request only). Uses query id, not per-user run.
     *
     * @param  array<int, KeywordDataDTO>  $keywordDtos
     * @param  array{location_code: int, language_code: string, limit: int, top_n: int}  $options
     */
    public function storeForQuery(
        InformationalPlannerQuery $query,
        array $keywordDtos,
        array $options
    ): void {
        if ($keywordDtos === []) {
            return;
        }

        $existingColumns = Schema::getColumnListing('keywords');
        $keywordColumns = array_flip($existingColumns);

        if (! isset($keywordColumns['informational_planner_query_id'])) {
            throw new \RuntimeException(
                'The keywords table must have informational_planner_query_id. Run database migrations.'
            );
        }

        $languageCode = $options['language_code'] ?? 'en';
        $geoTargetId = (int) ($options['location_code'] ?? 2840);
        $batchSize = 500;
        $batches = array_chunk($keywordDtos, $batchSize);

        foreach ($batches as $batch) {
            $insertData = [];
            $now = now();

            foreach ($batch as $dto) {
                $row = [
                    'keyword' => $dto->keyword,
                    'search_volume' => $dto->searchVolume,
                    'competition' => $dto->competition,
                    'cpc' => $dto->cpc,
                    'intent' => $dto->intent ?? null,
                    'location' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if (isset($keywordColumns['project_id'])) {
                    $row['project_id'] = null;
                }
                if (isset($keywordColumns['keyword_research_job_id'])) {
                    $row['keyword_research_job_id'] = null;
                }
                $row['informational_planner_query_id'] = $query->id;

                if (isset($keywordColumns['source'])) {
                    $row['source'] = $dto->source ?? null;
                }
                if (isset($keywordColumns['language_code'])) {
                    $row['language_code'] = $languageCode;
                }
                if (isset($keywordColumns['geoTargetId'])) {
                    $row['geoTargetId'] = $geoTargetId;
                }
                if (isset($keywordColumns['intent_category'])) {
                    $row['intent_category'] = $dto->intentCategory ?? null;
                }
                if (isset($keywordColumns['intent_metadata']) && $dto->intentMetadata) {
                    $row['intent_metadata'] = json_encode($dto->intentMetadata);
                }
                if (isset($keywordColumns['ai_visibility_score'])) {
                    $row['ai_visibility_score'] = $dto->aiVisibilityScore ?? null;
                }
                if (isset($keywordColumns['semantic_data']) && $dto->semanticData) {
                    $row['semantic_data'] = json_encode($dto->semanticData);
                }

                $insertData[] = $row;
            }

            if ($insertData === []) {
                continue;
            }

            try {
                Keyword::insert($insertData);
            } catch (\Throwable $e) {
                Log::error('Informational planner keyword bulk insert failed', [
                    'query_id' => $query->id,
                    'batch_size' => count($insertData),
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }
        }
    }
}
