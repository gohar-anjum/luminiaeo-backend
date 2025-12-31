<?php

namespace App\Services;

use App\DTOs\CitationRequestDTO;
use App\Interfaces\CitationRepositoryInterface;
use App\Jobs\CitationChunkJob;
use App\Jobs\GenerateCitationQueriesJob;
use App\Jobs\ProcessCitationTaskJob;
use App\Models\CitationTask;
use App\Services\LLM\LLMClient;
use App\Services\DataForSEO\CitationService as DataForSEOCitationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CitationService
{
    public function __construct(
        protected CitationRepositoryInterface $repository,
        protected LLMClient $llmClient,
        protected ?DataForSEOCitationService $dataForSEOCitationService = null
    ) {
        if (config('citations.dataforseo.enabled', false) || config('citations.dataforseo.llm_mentions_enabled', false)) {
            try {
                $this->dataForSEOCitationService = app(DataForSEOCitationService::class);
            } catch (\Exception $e) {
                Log::warning('DataForSEO Citation Service not available', ['error' => $e->getMessage()]);
            }
        }
    }

    public function createTask(CitationRequestDTO $dto): CitationTask
    {
        $normalizedUrl = $this->normalizeUrl($dto->url);
        
        $lockKey = 'citation:lock:' . md5($normalizedUrl);
        $timeout = config('cache_locks.citations.timeout', 60);
        
        return Cache::lock($lockKey, $timeout)->get(function () use ($normalizedUrl, $dto) {
            $cacheDays = config('citations.cache_days', 30);
            $existingTask = $this->repository->findCompletedByUrl($normalizedUrl, $cacheDays);

            if ($existingTask) {
                Log::info('Returning cached citation task', [
                    'task_id' => $existingTask->id,
                    'url' => $normalizedUrl,
                    'cached_at' => $existingTask->created_at,
                ]);
                return $existingTask;
            }

            $inProgressTask = $this->repository->findInProgressByUrl($normalizedUrl);
            if ($inProgressTask) {
                Log::info('Returning existing in-progress citation task', [
                    'task_id' => $inProgressTask->id,
                    'url' => $normalizedUrl,
                    'status' => $inProgressTask->status,
                ]);
                return $inProgressTask;
            }

            $max = config('citations.max_queries');
            $numQueries = min(max($dto->numQueries, 1), $max);

            $task = $this->repository->create([
                'user_id' => \Illuminate\Support\Facades\Auth::id(),
                'url' => $normalizedUrl,
                'status' => CitationTask::STATUS_GENERATING,
                'meta' => [
                    'requested_queries' => $dto->numQueries,
                    'num_queries' => $numQueries,
                ],
            ]);

            GenerateCitationQueriesJob::dispatch($task->id, $normalizedUrl, $numQueries);

            Log::info('Citation task created, query generation queued', [
                'task_id' => $task->id,
                'url' => $normalizedUrl,
                'num_queries' => $numQueries,
            ]);

            return $task->fresh();
        });
    }

    /**
     * Create task using LLM Mentions API directly
     * This is a separate flow that skips query generation and SERP processing
     * Returns results immediately from DataForSEO LLM Mentions API
     */
    public function createLLMMentionsTask(CitationRequestDTO $dto): CitationTask
    {
        $normalizedUrl = $this->normalizeUrl($dto->url);
        $lockKey = 'citation:llm_mentions:lock:' . md5($normalizedUrl);
        $timeout = config('cache_locks.citations.timeout', 60);
        
        return Cache::lock($lockKey, $timeout)->get(function () use ($normalizedUrl, $dto) {
            $cacheDays = config('citations.cache_days', 30);
            $existingTask = $this->repository->findCompletedByUrl($normalizedUrl, $cacheDays);

            if ($existingTask && isset($existingTask->meta['llm_mentions']) && $existingTask->meta['llm_mentions']) {
                Log::info('Returning cached LLM Mentions task', [
                    'task_id' => $existingTask->id,
                    'url' => $normalizedUrl,
                    'cached_at' => $existingTask->created_at,
                ]);
                return $existingTask;
            }

            $inProgressTask = $this->repository->findInProgressByUrl($normalizedUrl);
            if ($inProgressTask && isset($inProgressTask->meta['llm_mentions']) && $inProgressTask->meta['llm_mentions']) {
                Log::info('Returning existing in-progress LLM Mentions task', [
                    'task_id' => $inProgressTask->id,
                    'url' => $normalizedUrl,
                    'status' => $inProgressTask->status,
                ]);
                return $inProgressTask;
            }

            // Create task with LLM Mentions status
            $task = $this->repository->create([
                'user_id' => \Illuminate\Support\Facades\Auth::id(),
                'url' => $normalizedUrl,
                'status' => CitationTask::STATUS_PROCESSING,
                'meta' => [
                    'llm_mentions' => true,
                    'platform' => config('citations.dataforseo.llm_mentions_platform', 'google'),
                ],
            ]);

            // Get LLM Mentions data directly
            try {
                if (!$this->dataForSEOCitationService) {
                    throw new \RuntimeException('DataForSEO Citation Service not available');
                }

                $platform = config('citations.dataforseo.llm_mentions_platform', 'google');
                $locationCode = config('services.citations.default_location_code', 2840);
                $languageCode = config('services.citations.default_language_code', 'en');

                Log::info('Fetching LLM Mentions data directly', [
                    'task_id' => $task->id,
                    'url' => $normalizedUrl,
                    'platform' => $platform,
                ]);

                $llmMentionsData = $this->dataForSEOCitationService->getLLMMentions(
                    $normalizedUrl,
                    $platform,
                    $locationCode,
                    $languageCode,
                    [
                        'limit' => config('citations.dataforseo.llm_mentions_limit', 1000),
                    ]
                );

                // Format results for compatibility with existing response structure
                $formattedResults = $this->formatLLMMentionsResults($llmMentionsData, $normalizedUrl);

                // Finalize task immediately with LLM Mentions results
                $task = $this->repository->update($task, [
                    'status' => CitationTask::STATUS_COMPLETED,
                    'results' => [
                        'by_query' => $formattedResults['by_query'] ?? [],
                        'llm_mentions' => $llmMentionsData,
                    ],
                    'competitors' => $formattedResults['competitors'] ?? [],
                    'meta' => array_merge($task->meta ?? [], [
                        'llm_mentions' => true,
                        'platform' => $platform,
                        'completed_at' => now()->toIso8601String(),
                        'gpt_score' => $formattedResults['gpt_score'] ?? 0.0,
                        'gemini_score' => 0.0,
                        'dataforseo_score' => $formattedResults['gpt_score'] ?? 0.0,
                    ]),
                ]);

                Log::info('LLM Mentions task completed immediately', [
                    'task_id' => $task->id,
                    'url' => $normalizedUrl,
                    'mentions_count' => count($formattedResults['by_query'] ?? []),
                ]);

                return $task->fresh();
            } catch (\Exception $e) {
                Log::error('LLM Mentions API call failed', [
                    'task_id' => $task->id,
                    'url' => $normalizedUrl,
                    'error' => $e->getMessage(),
                ]);

                return $this->recordFailure($task, 'LLM Mentions API failed: ' . $e->getMessage());
            }
        });
    }

    /**
     * Format LLM Mentions API response to match existing citation result structure
     */
    protected function formatLLMMentionsResults(array $llmMentionsData, string $targetUrl): array
    {
        $byQuery = [];
        $totalMentions = 0;
        $competitorData = [];
        $targetDomain = $this->normalizeDomainForGrouping($targetUrl);

        // Process LLM Mentions results
        // Response structure: result[0].items[] or result[] directly
        $items = [];
        if (isset($llmMentionsData[0]['items']) && is_array($llmMentionsData[0]['items'])) {
            $items = $llmMentionsData[0]['items'];
        } elseif (is_array($llmMentionsData) && isset($llmMentionsData[0]) && is_array($llmMentionsData[0])) {
            // Check if first element has 'question' or 'sources' (it's an item)
            if (isset($llmMentionsData[0]['question']) || isset($llmMentionsData[0]['sources'])) {
                $items = $llmMentionsData;
            }
        }

        Log::info('Processing LLM Mentions results', [
            'items_count' => count($items),
            'result_structure' => array_keys($llmMentionsData[0] ?? []),
        ]);

        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                continue;
            }

            $query = $item['question'] ?? $item['query'] ?? $item['full_question'] ?? "Mention #{$index}";
            $sources = $item['sources'] ?? $item['cited_sources'] ?? $item['related_sources'] ?? [];
            $citationFound = !empty($sources) && is_array($sources);
            
            $citations = [];
            $competitors = [];
            
            if ($citationFound) {
                foreach ($sources as $source) {
                    if (!is_array($source)) {
                        continue;
                    }

                    $sourceUrl = $source['url'] ?? $source['domain'] ?? $source['link'] ?? '';
                    if (empty($sourceUrl)) {
                        continue;
                    }
                    
                    $sourceDomain = $this->normalizeDomainForGrouping($sourceUrl);
                    $targetVariations = $this->getDomainVariations($targetUrl);
                    
                    if ($this->isTargetDomain($sourceDomain, $targetVariations)) {
                        $citations[] = $sourceUrl;
                        $totalMentions++;
                    } else {
                        $competitors[] = [
                            'domain' => $sourceDomain,
                            'url' => $sourceUrl,
                            'title' => $source['title'] ?? $source['name'] ?? '',
                        ];
                        
                        // Track competitor data
                        if (!isset($competitorData[$sourceDomain])) {
                            $competitorData[$sourceDomain] = [
                                'domain' => $sourceDomain,
                                'citation_count' => 0,
                                'urls' => [],
                            ];
                        }
                        $competitorData[$sourceDomain]['citation_count']++;
                        if (!in_array($sourceUrl, $competitorData[$sourceDomain]['urls'])) {
                            $competitorData[$sourceDomain]['urls'][] = $sourceUrl;
                        }
                    }
                }
            }

            $confidence = $citationFound ? min(1.0, count($citations) / 10.0) : 0.0;

            $byQuery[(string) $index] = [
                'query' => $query,
                'gpt' => [
                    'provider' => 'dataforseo_llm_mentions',
                    'citation_found' => $citationFound,
                    'confidence' => $confidence,
                    'citation_references' => array_slice($citations, 0, 10),
                    'competitors' => array_slice($competitors, 0, 5),
                    'explanation' => $citationFound 
                        ? 'Citation found via DataForSEO LLM Mentions API' 
                        : 'No citation found via DataForSEO LLM Mentions API',
                    'raw_response' => $item,
                ],
                'gemini' => [
                    'provider' => 'gemini',
                    'citation_found' => false,
                    'confidence' => 0.0,
                    'citation_references' => [],
                    'competitors' => [],
                    'explanation' => 'Not used when LLM Mentions API is enabled',
                ],
                'top_competitors' => array_slice($competitors, 0, 2),
            ];
        }

        // Calculate scores
        $totalQueries = max(count($byQuery), 1);
        $gptScore = round(($totalMentions / $totalQueries) * 100, 2);

        // Format competitors
        $competitors = [];
        foreach ($competitorData as $domain => $data) {
            $competitors[] = [
                'domain' => $domain,
                'citation_count' => $data['citation_count'],
                'urls' => array_slice($data['urls'], 0, 10),
            ];
        }
        usort($competitors, fn($a, $b) => $b['citation_count'] <=> $a['citation_count']);
        $competitors = array_slice($competitors, 0, 20);

        return [
            'by_query' => $byQuery,
            'competitors' => [
                'total_citations' => $totalMentions,
                'total_queries' => $totalQueries,
                'competitors' => $competitors,
            ],
            'gpt_score' => $gptScore,
        ];
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

    public function generateQueries(string $url, int $numQueries): array
    {
        // When DataForSEO is enabled, skip LLM calls to save time and cost
        if (config('citations.dataforseo.enabled', false)) {
            Log::info('DataForSEO enabled - skipping LLM query generation, using template-based queries', [
                'url' => $url,
                'num_queries' => $numQueries,
            ]);
            
            // Generate queries using template-based approach (faster, no LLM cost)
            $templateQueries = $this->buildTemplateQueries($url, $numQueries);
            $queries = collect($templateQueries);

            return $queries
                ->map(fn ($q) => $this->sanitizeQuery($q))
                ->filter()
                ->unique()
                ->take($numQueries)
                ->values()
                ->all();
        }

        // Use LLM to generate queries when DataForSEO is not enabled
        $llmQueries = $this->llmClient->generateQueries($url, $numQueries);
        $queries = collect($llmQueries);

        return $queries
            ->map(fn ($q) => $this->sanitizeQuery($q))
            ->filter()
            ->unique()
            ->take($numQueries)
            ->values()
            ->all();
    }

    public function dispatchChunkJobs(CitationTask $task): void
    {
        $queries = $task->queries ?? [];
        $chunkSize = max(1, config('citations.chunk_size', config('services.dataforseo.citation.chunk_size', 25)));
        $chunks = array_chunk($queries, $chunkSize, true);
        $delay = config('citations.chunk_delay_seconds', 0);

        foreach ($chunks as $offset => $chunk) {
            $job = new CitationChunkJob($task->id, $chunk, $offset * $chunkSize, count($queries));
            if ($delay > 0) {
                dispatch($job)->delay(now()->addSeconds($delay * $offset));
            } else {
                dispatch($job);
            }
        }
    }

    public function dispatchPartialChunks(CitationTask $task, array $subset): void
    {
        if (empty($subset)) {
            return;
        }

        if ($task->status !== CitationTask::STATUS_PROCESSING) {
            $this->repository->update($task, ['status' => CitationTask::STATUS_PROCESSING]);
        }

        $chunkSize = max(1, config('citations.chunk_size', config('services.dataforseo.citation.chunk_size', 25)));
        $chunks = array_chunk($subset, $chunkSize, true);

        foreach ($chunks as $chunk) {
            dispatch(new CitationChunkJob($task->id, $chunk, 0, count($task->queries ?? [])));
        }
    }

    public function mergeChunkResults(CitationTask $task, array $results, array $meta = [], array $progress = []): CitationTask
    {
        $progress['total'] = $progress['total'] ?? count($task->queries ?? []);

        return $this->repository->appendResults($task, [
            'by_query' => $results,
            'meta' => $meta,
            'progress' => $progress,
        ]);
    }

    public function finalizeTask(CitationTask $task): CitationTask
    {
        $results = $task->results['by_query'] ?? [];
        $stats = $this->calculateScores($results);
        $competitors = $this->computeCompetitors($results, $task->url);

        $meta = [
            'gpt_score' => $stats['gpt_score'],
            'gemini_score' => $stats['gemini_score'],
            'status' => CitationTask::STATUS_COMPLETED,
            'completed_at' => now()->toIso8601String(),
        ];

        if (isset($stats['dataforseo_score'])) {
            $meta['dataforseo_score'] = $stats['dataforseo_score'];
        }

        return $this->repository->updateCompetitorsAndMeta(
            $task,
            $competitors,
            $meta
        );
    }

    public function recordFailure(CitationTask $task, string $message): CitationTask
    {
        Log::error('Citation task failed', [
            'task_id' => $task->id,
            'error' => $message,
        ]);

        $meta = $task->meta ?? [];
        $errors = $meta['errors'] ?? [];
        $errors[] = [
            'message' => $message,
            'timestamp' => now()->toIso8601String(),
        ];
        $meta['errors'] = $errors;

        return $this->repository->update($task, [
            'status' => CitationTask::STATUS_FAILED,
            'meta' => $meta,
        ]);
    }

    public function computeCompetitors(array $byQuery, string $targetUrl): array
    {
        $targetDomainNormalized = $this->normalizeDomainForGrouping($targetUrl);
        $targetDomainVariations = $this->getDomainVariations($targetUrl);

        Log::debug('Computing competitors', [
            'target_url' => $targetUrl,
            'target_normalized' => $targetDomainNormalized,
            'target_variations' => $targetDomainVariations,
        ]);

        $competitorData = [];
        $totalQueries = count($byQuery);
        $totalCitations = 0;

        foreach ($byQuery as $entry) {
            $query = $entry['query'] ?? '';
            $queryCompetitors = $entry['top_competitors'] ?? [];
            $queryHasCitation = false;

            foreach (['gpt', 'gemini'] as $provider) {
                if (!empty($entry[$provider]['citation_found'])) {
                    $queryHasCitation = true;
                }
                if ($provider === 'gpt' && isset($entry['gpt']['provider']) && $entry['gpt']['provider'] === 'dataforseo') {
                    if (!empty($entry['gpt']['citation_found'])) {
                        $queryHasCitation = true;
                    }
                }
            }

            if ($queryHasCitation) {
                $totalCitations++;
            }

            foreach ($queryCompetitors as $competitor) {
                $domain = $this->normalizeDomainForGrouping($competitor['domain'] ?? '');
                if (!$domain || $this->isTargetDomain($domain, $targetDomainVariations)) {
                    continue;
                }

                if (!isset($competitorData[$domain])) {
                    $competitorData[$domain] = [
                        'domain' => $domain,
                        'citation_count' => 0,
                        'query_count' => 0,
                        'queries' => [],
                        'urls' => [],
                        'providers' => ['gpt' => 0, 'gemini' => 0],
                    ];
                }

                $competitorData[$domain]['citation_count'] += $competitor['mentions'] ?? 1;

                if (!in_array($query, $competitorData[$domain]['queries'], true)) {
                    $competitorData[$domain]['query_count']++;
                    $competitorData[$domain]['queries'][] = $query;
                }

                foreach ($competitor['urls'] ?? [] as $url) {
                    if (!in_array($url, $competitorData[$domain]['urls'], true)) {
                        $competitorData[$domain]['urls'][] = $url;
                    }
                }

                foreach (['gpt', 'gemini'] as $provider) {
                    $providerCompetitors = $entry[$provider]['competitors'] ?? [];
                    if ($provider === 'gpt' && isset($entry['gpt']['provider']) && $entry['gpt']['provider'] === 'dataforseo') {
                        $providerCompetitors = array_merge($providerCompetitors, $entry['gpt']['competitors'] ?? []);
                    }
                    foreach ($providerCompetitors as $providerCompetitor) {
                        $providerDomain = $this->normalizeDomainForGrouping($providerCompetitor['domain'] ?? ($providerCompetitor['url'] ?? ''));
                        if ($providerDomain && $providerDomain === $domain) {
                            $competitorData[$domain]['providers'][$provider]++;
                            break;
                        }
                    }
                }
            }
        }

        $totalCompetitorMentions = array_sum(array_column($competitorData, 'citation_count'));

        foreach ($competitorData as &$competitor) {
            $competitor['percentage'] = $totalCompetitorMentions > 0
                ? round(($competitor['citation_count'] / $totalCompetitorMentions) * 100, 2)
                : 0;
            $competitor['query_percentage'] = $totalQueries > 0
                ? round(($competitor['query_count'] / $totalQueries) * 100, 2)
                : 0;

            $competitor['urls'] = array_slice($competitor['urls'], 0, 10);
            $competitor['queries'] = array_slice($competitor['queries'], 0, 10);
        }
        unset($competitor);

        usort($competitorData, fn($a, $b) => $b['citation_count'] <=> $a['citation_count']);
        $topCompetitors = array_slice($competitorData, 0, 20);

        return [
            'total_citations' => $totalCitations,
            'total_queries' => $totalQueries,
            'competitors' => $topCompetitors,
        ];
    }

    protected function extractDomain(string $url): ?string
    {
        $domain = parse_url($url, PHP_URL_HOST);
        if (!$domain) {
            return null;
        }

        $domain = strtolower($domain);
        $domain = preg_replace('/^www\./', '', $domain);

        return $domain;
    }

    protected function normalizeDomainForGrouping(string $domain): string
    {
        if (empty($domain)) {
            return $domain;
        }

        $normalized = strtolower(trim($domain));

        if (preg_match('/^https?:\/\//', $normalized)) {
            $normalized = parse_url($normalized, PHP_URL_HOST) ?: $normalized;
        }

        $normalized = preg_replace('/^www\./', '', $normalized);

        $parts = explode('.', $normalized);
        if (count($parts) >= 2) {
            $baseDomain = $parts[count($parts) - 2] . '.' . $parts[count($parts) - 1];
            return $baseDomain;
        }

        return $normalized;
    }

    protected function getDomainVariations(string $domainOrUrl): array
    {
        $normalized = $this->normalizeDomainForGrouping($domainOrUrl);
        if (!$normalized) {
            return [];
        }

        $variations = [$normalized];

        if (strpos($normalized, 'cricinfo') !== false) {
            $variations[] = 'cricinfo.com';
            $variations[] = 'espncricinfo.com';
        }

        return array_unique(array_filter($variations));
    }

    protected function isTargetDomain(string $normalizedDomain, array $targetVariations): bool
    {
        if (empty($normalizedDomain)) {
            return false;
        }

        foreach ($targetVariations as $variation) {
            $normalizedVariation = $this->normalizeDomainForGrouping($variation);
            if ($normalizedDomain === $normalizedVariation && !empty($normalizedVariation)) {
                return true;
            }
        }
        return false;
    }

    protected function calculateScores(array $results): array
    {
        $total = max(count($results), 1);
        $gptHits = 0;
        $geminiHits = 0;
        $dataforseoHits = 0;

        foreach ($results as $entry) {
            if (!empty($entry['gpt']['citation_found'])) {
                $gptHits++;
            }
            if (!empty($entry['gemini']['citation_found'])) {
                $geminiHits++;
            }
            if (!empty($entry['gpt']['provider']) && $entry['gpt']['provider'] === 'dataforseo') {
                if (!empty($entry['gpt']['citation_found'])) {
                    $dataforseoHits++;
                }
            }
        }

        if (config('citations.dataforseo.enabled', false) && $dataforseoHits > 0) {
            return [
                'gpt_score' => round(($dataforseoHits / $total) * 100, 2),
                'gemini_score' => 0.0,
                'dataforseo_score' => round(($dataforseoHits / $total) * 100, 2),
            ];
        }

        return [
            'gpt_score' => round(($gptHits / $total) * 100, 2),
            'gemini_score' => round(($geminiHits / $total) * 100, 2),
        ];
    }

    protected function buildTemplateQueries(string $url, int $desiredCount): array
    {
        $host = parse_url($url, PHP_URL_HOST) ?: $url;
        $brand = $this->extractBrand($host);
        $keywords = $this->deriveAudienceKeywords($url) ?: [$brand];
        $themes = [
            'solutions',
            'success stories',
            'pricing',
            'setup',
            'tutorial',
            'best practices',
            'customer reviews',
            'benefits',
            'use cases',
            'faq',
            'case study',
            'benchmark',
            'community',
            'webinar',
            'playbook',
        ];

        $queries = [];
        foreach ($keywords as $keyword) {
            $queries[] = trim("{$brand} {$keyword} overview");
            $queries[] = trim("{$keyword} by {$brand}");
            foreach ($themes as $theme) {
                $queries[] = trim("{$brand} {$keyword} {$theme}");
            }
            $queries[] = trim("{$brand} for {$keyword}");
            $queries[] = trim("{$keyword} powered by {$brand}");
        }

        return array_slice(array_values(array_unique($queries)), 0, max($desiredCount, 30));
    }

    protected function generateGenericQueries(string $url, int $count): array
    {
        $keywords = $this->deriveAudienceKeywords($url);
        if (empty($keywords)) {
            $generic = [
                'How to get started?',
                'What are best practices?',
                'How to optimize performance?',
                'What are common challenges?',
                'How to implement solutions?',
            ];
            return array_slice($generic, 0, $count);
        }

        $questionStarters = [
            'How to',
            'What are',
            'Why is',
            'When should',
            'Where can',
        ];

        $actionWords = [
            'optimize',
            'implement',
            'improve',
            'manage',
            'scale',
        ];

        $fallbacks = [];
        for ($i = 0; $i < $count; $i++) {
            $keyword = $keywords[$i % count($keywords)];
            $starter = $questionStarters[$i % count($questionStarters)];
            $action = $actionWords[$i % count($actionWords)];

            if ($starter === 'How to') {
                $fallbacks[] = trim("{$starter} {$action} {$keyword}?");
            } else {
                $fallbacks[] = trim("{$starter} {$keyword} {$action}?");
            }
        }

        return $fallbacks;
    }

    protected function fallbackQueries(string $url, int $count): array
    {
        return $this->generateGenericQueries($url, $count);
    }

    protected function sanitizeQuery(string $query): ?string
    {
        $clean = trim(preg_replace('/[^a-zA-Z0-9\\s]/', ' ', $query));
        $clean = preg_replace('/\\s+/', ' ', $clean);

        if (empty($clean)) {
            return null;
        }

        return mb_substr($clean, 0, 60);
    }

    protected function extractBrand(string $host): string
    {
        $host = preg_replace('/^www\\./', '', $host);
        $parts = explode('.', $host);
        return $parts[0] ?? $host;
    }

    protected function deriveAudienceKeywords(string $url): array
    {
        $host = parse_url($url, PHP_URL_HOST) ?: $url;
        $host = strtolower(preg_replace('/^www\\./', '', $host));
        $stopWords = ['com', 'net', 'org', 'io', 'co', 'www', 'app', 'site'];
        $tokens = preg_split('/[\\.\\-]/', $host);
        $keywords = array_filter($tokens, function ($token) use ($stopWords) {
            return $token && !in_array($token, $stopWords, true) && strlen($token) > 2;
        });

        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $pathTokens = array_filter(preg_split('/[\\/\\-_]/', trim($path, '/')));
        foreach ($pathTokens as $token) {
            $clean = trim(preg_replace('/[^a-zA-Z0-9 ]/', ' ', $token));
            if ($clean && strlen($clean) > 2) {
                $keywords[] = $clean;
            }
        }

        return array_values(array_unique(array_map(fn ($token) => str_replace('-', ' ', $token), $keywords)));
    }

}
