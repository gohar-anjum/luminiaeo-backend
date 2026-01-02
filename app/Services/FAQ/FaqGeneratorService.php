<?php

namespace App\Services\FAQ;

use App\DTOs\FaqGenerationDTO;
use App\DTOs\FaqResponseDTO;
use App\Interfaces\FaqRepositoryInterface;
use App\Services\LLM\LLMClient;
use App\Services\LLM\Prompt\PlaceholderReplacer;
use App\Services\LLM\Prompt\PromptLoader;
use App\Services\LLM\Support\JsonExtractor;
use App\Services\Serp\SerpService;
use App\Services\FAQ\AlsoAskedService;
use App\Services\LocationCodeService;
use App\Services\DataForSEO\DataForSEOService;
use App\Exceptions\SerpException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class FaqGeneratorService
{
    protected LLMClient $llmClient;
    protected FaqRepositoryInterface $faqRepository;
    protected PromptLoader $promptLoader;
    protected PlaceholderReplacer $placeholderReplacer;
    protected ?SerpService $serpService = null;
    protected ?AlsoAskedService $alsoAskedService = null;
    protected ?DataForSEOService $dataForSEOService = null;
    protected int $cacheTTL;
    protected int $timeout;

    public function __construct(
        LLMClient $llmClient,
        FaqRepositoryInterface $faqRepository,
        PromptLoader $promptLoader,
        PlaceholderReplacer $placeholderReplacer
    ) {
        $this->llmClient = $llmClient;
        $this->faqRepository = $faqRepository;
        $this->promptLoader = $promptLoader;
        $this->placeholderReplacer = $placeholderReplacer;
        $this->cacheTTL = config('services.faq.cache_ttl', 86400);
        $this->timeout = config('services.faq.timeout', 60);
    }

    public function generateFaqs(string $input, array $options = []): FaqResponseDTO
    {
        if (empty($input)) {
            throw new \InvalidArgumentException('Input field is required (URL or topic)');
        }

        $isUrl = $this->isUrl($input);
        $url = $isUrl ? $this->normalizeUrl($input) : null;
        $topic = $isUrl ? null : $input;

        $lockKey = 'faq:lock:' . md5(serialize([$url, $topic, $options]));
        $timeout = config('cache_locks.faq.timeout', 120);
        
        return Cache::lock($lockKey, $timeout)->get(function () use ($url, $topic, $options) {
            $faqRecord = null;

            if ($topic) {
                $normalizedTopic = strtolower(trim($topic));
                $faqRecords = \App\Models\Faq::whereNotNull('topic')
                    ->whereRaw('LOWER(TRIM(topic)) = ?', [$normalizedTopic])
                    ->get();
                
                if ($faqRecords->isNotEmpty()) {
                    $faqRecord = $faqRecords->sortByDesc('created_at')->first();
                }
            } elseif ($url) {
                $normalizedUrl = $this->normalizeUrl($url);
                $faqRecords = $this->faqRepository->findByUrl($normalizedUrl);
                if ($faqRecords->isNotEmpty()) {
                    $faqRecord = $faqRecords->sortByDesc('created_at')->first();
                }
            }

            if ($faqRecord) {
                $this->faqRepository->incrementApiCallsSaved($faqRecord->id);
                $faqRecord->refresh();

                return new FaqResponseDTO(
                    faqs: $faqRecord->faqs,
                    count: count($faqRecord->faqs),
                    url: $faqRecord->url,
                    topic: $faqRecord->topic,
                    fromDatabase: true,
                    apiCallsSaved: $faqRecord->api_calls_saved,
                    createdAt: $faqRecord->created_at?->toIso8601String(),
                );
            }

            $sourceHash = $this->generateSourceHash($url, $topic, $options);

            $faqRecord = $this->faqRepository->findByHash($sourceHash);
            if ($faqRecord) {
                $this->faqRepository->incrementApiCallsSaved($faqRecord->id);
                $faqRecord->refresh();

                return new FaqResponseDTO(
                    faqs: $faqRecord->faqs,
                    count: count($faqRecord->faqs),
                    url: $faqRecord->url,
                    topic: $faqRecord->topic,
                    fromDatabase: true,
                    apiCallsSaved: $faqRecord->api_calls_saved,
                    createdAt: $faqRecord->created_at?->toIso8601String(),
                );
            }

            $cacheKey = $this->getCacheKey($url, $topic, $options);

            if (Cache::has($cacheKey)) {
                $faqs = Cache::get($cacheKey);
                $faqRecord = $this->storeFaqsInDatabase($url, $topic, $faqs, $options, $sourceHash);

                return new FaqResponseDTO(
                    faqs: $faqs,
                    count: count($faqs),
                    url: $url,
                    topic: $topic,
                    fromDatabase: false,
                    apiCallsSaved: 0,
                    createdAt: $faqRecord->created_at?->toIso8601String(),
                );
            }

            return $this->generateFaqsInternal($url, $topic, $options, $sourceHash, $cacheKey);
        });
    }
    
    protected function generateFaqsInternal(string $url, ?string $topic, array $options, string $sourceHash, string $cacheKey): FaqResponseDTO
    {
        $urlContent = null;
        if ($url) {
            $urlContentCacheKey = 'faq:url_content:' . md5($url);
            $urlContent = Cache::remember($urlContentCacheKey, 3600, function () use ($url) {
                return $this->fetchUrlContent($url);
            });
        }

        $languageCode = $options['language_code'] ?? config('services.faq.default_language', 'en');
        $locationCode = $options['location_code'] ?? config('services.faq.default_location', 2840);
        $region = $this->mapLocationCodeToRegion($locationCode);

        $serpQuestions = $this->fetchSerpQuestions($url, $topic, $languageCode, $locationCode);
        $alsoAskedQuestions = $this->fetchAlsoAskedQuestions($url, $topic, $languageCode, $region);

        $allQuestions = $this->combineQuestions($serpQuestions, $alsoAskedQuestions);

        if (empty($allQuestions)) {
            throw new \RuntimeException(
                'No questions found from SERP or AlsoAsked. Please ensure at least one of these services is configured and returns questions.'
            );
        }

        $topKeywords = [];
        if (!empty($options['enable_keywords']) && $options['enable_keywords'] === true) {
            $languageCode = $options['language_code'] ?? config('services.faq.default_language', 'en');
            $locationCode = $options['location_code'] ?? config('services.faq.default_location', 2840);
            
            $topKeywords = $this->fetchKeywordsForTopic(
                $topic,
                $languageCode,
                $locationCode
            );
        }

        $faqs = null;
        $lastException = null;

        if ($this->shouldTryLLM('gemini')) {
            try {
                $faqs = $this->generateFaqsWithGemini($url, $topic, $urlContent, $allQuestions, $options, $topKeywords);
                $this->recordLLMSuccess('gemini');
            } catch (\Exception $geminiException) {
                $lastException = $geminiException;
                $this->recordLLMFailure('gemini');

                if ($this->shouldTryLLM('gpt')) {
                    try {
                        $faqs = $this->generateFaqsWithGPT($url, $topic, $urlContent, $allQuestions, $options, $topKeywords);
                        $this->recordLLMSuccess('gpt');
                    } catch (\Exception $gptException) {
                        $lastException = $gptException;
                        $this->recordLLMFailure('gpt');

                        $serpResponse = $this->fetchSerpResponse($url, $topic);

                        if ($serpResponse && !empty($serpResponse)) {
                            $faqs = $this->generateFaqsFromSerpResponse($url, $topic, $serpResponse);

                            if (empty($faqs)) {
                                throw new \RuntimeException('Failed to generate FAQs from SERP response: No FAQs extracted');
                            }
                        } else {
                            // If all fallbacks fail, throw the last exception
                            throw new \RuntimeException('All LLM APIs failed and SERP fallback unavailable. Last error: ' . $lastException->getMessage());
                        }
                    }
                } else {
                    // Circuit breaker open for GPT, try SERP fallback directly
                    $serpResponse = $this->fetchSerpResponse($url, $topic);
                    if ($serpResponse && !empty($serpResponse)) {
                        $faqs = $this->generateFaqsFromSerpResponse($url, $topic, $serpResponse);
                    } else {
                        throw new \RuntimeException('LLM circuit breaker open and SERP fallback unavailable. Last error: ' . $lastException->getMessage());
                    }
                }
            }
        } else {
            // Circuit breaker open for Gemini, try GPT or SERP
            if ($this->shouldTryLLM('gpt')) {
                try {
                    $faqs = $this->generateFaqsWithGPT($url, $topic, $urlContent, $allQuestions, $options, $topKeywords);
                    $this->recordLLMSuccess('gpt');
                } catch (\Exception $gptException) {
                    $lastException = $gptException;
                    $this->recordLLMFailure('gpt');
                    $serpResponse = $this->fetchSerpResponse($url, $topic);
                    if ($serpResponse && !empty($serpResponse)) {
                        $faqs = $this->generateFaqsFromSerpResponse($url, $topic, $serpResponse);
                    } else {
                        throw new \RuntimeException('All LLM APIs circuit breaker open. Last error: ' . $gptException->getMessage());
                    }
                }
            } else {
                // Both circuit breakers open, use SERP fallback
                $serpResponse = $this->fetchSerpResponse($url, $topic);
                if ($serpResponse && !empty($serpResponse)) {
                    $faqs = $this->generateFaqsFromSerpResponse($url, $topic, $serpResponse);
                } else {
                    throw new \RuntimeException('All LLM APIs circuit breaker open and SERP fallback unavailable.');
                }
            }
        }

        if (empty($faqs)) {
            throw new \RuntimeException('Failed to generate FAQs: No FAQs generated from any source');
        }

        $faqRecord = $this->storeFaqsInDatabase($url, $topic, $faqs, $options, $sourceHash);
        Cache::put($cacheKey, $faqs, now()->addSeconds($this->cacheTTL));

        return new FaqResponseDTO(
            faqs: $faqs,
            count: count($faqs),
            url: $url,
            topic: $topic,
            fromDatabase: false,
            apiCallsSaved: 0,
            createdAt: $faqRecord->created_at?->toIso8601String(),
        );
    }

    public function fetchUrlContent(string $url): ?string
    {
        try {
            $response = Http::timeout($this->timeout)
                ->retry(2, 1000)
                ->get($url);

            if ($response->failed()) {
                return null;
            }

            $html = $response->body();
            $text = $this->extractTextFromHtml($html);
            return mb_substr($text, 0, 10000);

        } catch (\Exception $e) {
            return null;
        }
    }

    protected function extractTextFromHtml(string $html): string
    {
        $html = preg_replace('/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/mi', '', $html);
        $html = preg_replace('/<style\b[^<]*(?:(?!<\/style>)<[^<]*)*<\/style>/mi', '', $html);
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = strip_tags($html);
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);

        return $text;
    }

    protected function fetchSerpQuestions(?string $url, ?string $topic, string $languageCode = 'en', int $locationCode = 2840): array
    {
        try {
            if (!$this->isSerpServiceAvailable()) {
                return [];
            }

            $searchQuery = $this->buildSearchQuery($url, $topic);

            if (empty($searchQuery)) {
                return [];
            }

            $serpService = $this->getSerpService();

            $serpResults = $serpService->getSerpResults(
                $searchQuery,
                $languageCode,
                $locationCode
            );

            $questions = $this->extractPeopleAlsoAskQuestions($serpResults);

            return $questions;
        } catch (SerpException $e) {
            return [];
        } catch (\Exception $e) {
            return [];
        }
    }

    protected function isSerpServiceAvailable(): bool
    {
        $baseUrl = config('services.serp.base_url');
        $apiKey = config('services.serp.api_key');

        return !empty($baseUrl) && !empty($apiKey);
    }

    protected function getSerpService(): SerpService
    {
        if ($this->serpService === null) {
            try {
                $this->serpService = app(SerpService::class);
            } catch (\Exception $e) {
                throw new \RuntimeException(
                    'SERP service is not available. Please configure SERP_API_BASE_URL and SERP_API_KEY in your .env file.',
                    0,
                    $e
                );
            }
        }

        return $this->serpService;
    }

    protected function buildSearchQuery(?string $url, ?string $topic): string
    {
        if ($topic) {
            return $topic;
        }

        if ($url) {
            $domain = parse_url($url, PHP_URL_HOST);
            if ($domain) {
                $domain = str_replace('www.', '', $domain);
                return $domain;
            }
        }

        return '';
    }

    protected function extractPeopleAlsoAskQuestions(array $serpResults): array
    {
        $questions = [];

        if (isset($serpResults['people_also_ask']) && is_array($serpResults['people_also_ask'])) {
            foreach ($serpResults['people_also_ask'] as $item) {
                if (isset($item['question'])) {
                    $question = trim($item['question']);
                    if (!empty($question)) {
                        $questions[] = $question;
                    }
                } elseif (is_string($item)) {
                    $question = trim($item);
                    if (!empty($question)) {
                        $questions[] = $question;
                    }
                }
            }
        }

        if (isset($serpResults['related_questions']) && is_array($serpResults['related_questions'])) {
            foreach ($serpResults['related_questions'] as $item) {
                if (isset($item['question'])) {
                    $question = trim($item['question']);
                    if (!empty($question) && !in_array($question, $questions)) {
                        $questions[] = $question;
                    }
                } elseif (is_string($item)) {
                    $question = trim($item);
                    if (!empty($question) && !in_array($question, $questions)) {
                        $questions[] = $question;
                    }
                }
            }
        }

        if (empty($questions) && isset($serpResults['organic_results']) && is_array($serpResults['organic_results'])) {
            foreach ($serpResults['organic_results'] as $result) {
                if (isset($result['title'])) {
                    $title = trim($result['title']);
                    if (!empty($title) && preg_match('/\?/', $title)) {
                        $questions[] = $title;
                    }
                }
            }
        }

        return array_slice(array_unique($questions), 0, 20);
    }

    public function generateFaqsFromSerpResponse(?string $url, ?string $topic, array $serpResponse): array
    {
        $faqs = [];

        if (isset($serpResponse['related_questions']) && is_array($serpResponse['related_questions'])) {
            foreach ($serpResponse['related_questions'] as $item) {
                if (isset($item['question']) && !empty(trim($item['question']))) {
                    $question = trim($item['question']);
                    $answer = '';

                    if (isset($item['snippet']) && !empty(trim($item['snippet']))) {
                        $answer = trim($item['snippet']);
                    } elseif (isset($item['answer']) && !empty(trim($item['answer']))) {
                        $answer = trim($item['answer']);
                    } elseif (isset($item['text']) && !empty(trim($item['text']))) {
                        $answer = trim($item['text']);
                    }

                    if (!empty($answer)) {
                        $faqs[] = [
                            'question' => $question,
                            'answer' => $answer,
                        ];
                    }
                }
            }
        }

        if (isset($serpResponse['knowledge_graph'])) {
            $kg = $serpResponse['knowledge_graph'];
            
            if (isset($kg['free']['ai_overview']['text_blocks']) && is_array($kg['free']['ai_overview']['text_blocks'])) {
                $question = $kg['free']['subtitle'] ?? 'Is ' . ($topic ?? 'it') . ' free?';
                $answerParts = [];
                
                foreach ($kg['free']['ai_overview']['text_blocks'] as $block) {
                    if (isset($block['snippet']) && !empty(trim($block['snippet']))) {
                        $answerParts[] = trim($block['snippet']);
                    }
                }
                
                if (!empty($answerParts)) {
                    $faqs[] = [
                        'question' => $question,
                        'answer' => implode(' ', $answerParts),
                    ];
                }
            }
            
            if (isset($kg['pricing']['ai_overview']['text_blocks']) && is_array($kg['pricing']['ai_overview']['text_blocks'])) {
                $question = $kg['pricing']['subtitle'] ?? ($topic ?? 'Product') . ' pricing';
                $answerParts = [];
                
                foreach ($kg['pricing']['ai_overview']['text_blocks'] as $block) {
                    if (isset($block['snippet']) && !empty(trim($block['snippet']))) {
                        $answerParts[] = trim($block['snippet']);
                    }
                }
                
                if (!empty($answerParts)) {
                    $faqs[] = [
                        'question' => $question,
                        'answer' => implode(' ', $answerParts),
                    ];
                }
            }
        }

        $faqs = array_slice($faqs, 0, 20);
        
        return array_map(function ($faq) {
            return [
                'question' => $faq['question'] ?? '',
                'answer' => $faq['answer'] ?? '',
            ];
        }, $faqs);
    }

    public function fetchSerpResponse(?string $url, ?string $topic): ?array
    {
        try {
            if (!$this->isSerpServiceAvailable()) {
                return null;
            }

            $searchQuery = $this->buildSearchQuery($url, $topic);

            if (empty($searchQuery)) {
                return null;
            }

            $serpService = $this->getSerpService();

            $serpResults = $serpService->getSerpResults(
                $searchQuery,
                'en',
                2840
            );

            return $serpResults;
        } catch (SerpException $e) {
            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    protected function fetchAlsoAskedQuestions(?string $url, ?string $topic, string $languageCode = 'en', string $region = 'us'): array
    {
        try {
            $alsoAskedService = $this->getAlsoAskedService();

            if (!$alsoAskedService->isAvailable()) {
                return [];
            }

            $searchQuery = $this->buildSearchQuery($url, $topic);

            if (empty($searchQuery)) {
                return [];
            }

            $questions = $alsoAskedService->search(
                $searchQuery,
                $languageCode,
                $region,
                2,
                false
            );

            return $questions;
        } catch (\Exception $e) {
            return [];
        }
    }

    protected function getAlsoAskedService(): AlsoAskedService
    {
        if ($this->alsoAskedService === null) {
            $this->alsoAskedService = app(AlsoAskedService::class);
        }

        return $this->alsoAskedService;
    }

    protected function getDataForSEOService(): ?DataForSEOService
    {
        if ($this->dataForSEOService === null) {
            try {
                $this->dataForSEOService = app(DataForSEOService::class);
            } catch (\Exception $e) {
                return null;
            }
        }

        return $this->dataForSEOService;
    }

    public function fetchKeywordsForTopic(?string $topic, string $languageCode = 'en', int $locationCode = 2840): array
    {
        $dataForSEOService = $this->getDataForSEOService();
        
        if (!$dataForSEOService) {
            return [];
        }

        if (empty($topic)) {
            return [];
        }

        $normalizedTopic = strtolower(trim($topic));

        try {
            $cacheRepository = app(\App\Interfaces\KeywordCacheRepositoryInterface::class);
            $cachedKeywords = $cacheRepository->findByTopic($normalizedTopic, $languageCode, $locationCode);

            if ($cachedKeywords->isNotEmpty()) {
                $keywordStrings = $cachedKeywords->pluck('keyword')->toArray();
                return $keywordStrings;
            }

            $allKeywords = [];
            $keywordsFromPlanner = [];
            $keywordsFromLabs = [];

            try {
                $keywordsFromPlanner = $dataForSEOService->getKeywordIdeas(
                    $topic,
                    $languageCode,
                    $locationCode,
                    20
                );
            } catch (\Exception $e) {
            }

            try {
                $keywordsFromLabs = $dataForSEOService->getKeywordIdeasFromLabs(
                    $topic,
                    $languageCode,
                    $locationCode,
                    20,
                    true
                );
            } catch (\Exception $e) {
            }

            $allKeywords = array_merge($keywordsFromPlanner, $keywordsFromLabs);

            if (empty($allKeywords)) {
                return [];
            }

            // $keywordsWithVolume = [];
            // $seenKeywords = [];
            // 
            // foreach ($allKeywords as $keywordData) {
            //     if ($keywordData instanceof \App\DTOs\KeywordDataDTO) {
            //         $keywordLower = strtolower(trim($keywordData->keyword));
            //         
            //         if (!isset($seenKeywords[$keywordLower])) {
            //             $keywordsWithVolume[] = $keywordData;
            //             $seenKeywords[$keywordLower] = true;
            //         }
            //     }
            // }
            // 
            // usort($keywordsWithVolume, function ($a, $b) {
            //     $volumeA = $a->searchVolume ?? 0;
            //     $volumeB = $b->searchVolume ?? 0;
            //     return $volumeB <=> $volumeA;
            // });
            // 
            // $topKeywords = array_slice($keywordsWithVolume, 0, 10);
            // $keywordStrings = array_map(fn($kw) => $kw->keyword, $topKeywords);

            $keywordStrings = array_map(fn($kw) => $kw->keyword, $allKeywords);

            if (!empty($allKeywords) && $dataForSEOService) {
                try {
                    $cacheRepository = app(\App\Interfaces\KeywordCacheRepositoryInterface::class);
                    $cacheData = [];

                    foreach ($allKeywords as $keywordData) {
                        if ($keywordData instanceof \App\DTOs\KeywordDataDTO) {
                            $existingMetadata = [];
                            $existingCache = $cacheRepository->find($keywordData->keyword, $languageCode, $locationCode);
                            
                            if ($existingCache && $existingCache->metadata) {
                                $existingMetadata = is_array($existingCache->metadata) 
                                    ? $existingCache->metadata 
                                    : json_decode($existingCache->metadata, true) ?? [];
                            }

                            $metadata = array_merge($existingMetadata, [
                                'topic' => $normalizedTopic,
                                'topics' => array_unique(array_merge(
                                    $existingMetadata['topics'] ?? [],
                                    [$normalizedTopic]
                                )),
                                'faq_keyword' => true,
                                'cached_at' => now()->toIso8601String(),
                            ]);

                            $cacheData[] = [
                                'keyword' => $keywordData->keyword,
                                'language_code' => $languageCode,
                                'location_code' => $locationCode,
                                'search_volume' => $keywordData->searchVolume,
                                'competition' => $keywordData->competition,
                                'cpc' => $keywordData->cpc,
                                'source' => $keywordData->source ?? 'faq_keyword_combined',
                                'metadata' => $metadata,
                            ];
                        }
                    }

                    if (!empty($cacheData)) {
                        $cacheRepository->bulkUpdate($cacheData);
                    }
                } catch (\Exception $e) {
                }
            }

            return $keywordStrings;

        } catch (\Exception $e) {
            return [];
        }
    }

    public function fetchKeywordsForQuestions(array $questions, string $languageCode = 'en', int $locationCode = 2840, int $keywordsPerQuestion = 10): array
    {
        $dataForSEOService = $this->getDataForSEOService();
        
        if (!$dataForSEOService) {
            return [];
        }

        $questionKeywords = [];
        $totalQuestions = count($questions);

        foreach ($questions as $index => $question) {
            try {

                $keywords = $dataForSEOService->getKeywordIdeas(
                    $question,
                    $languageCode,
                    $locationCode,
                    $keywordsPerQuestion
                );

                $keywordStrings = [];
                foreach ($keywords as $keywordData) {
                    $keywordValue = null;
                    
                    if (is_object($keywordData)) {
                        if ($keywordData instanceof \App\DTOs\KeywordDataDTO) {
                            $keywordValue = $keywordData->keyword;
                        } elseif (property_exists($keywordData, 'keyword')) {
                            $keywordValue = $keywordData->keyword;
                        }
                    } elseif (is_string($keywordData)) {
                        $keywordValue = $keywordData;
                    } elseif (is_array($keywordData)) {
                        $keywordValue = $keywordData['keyword'] ?? null;
                    }
                    
                    if ($keywordValue !== null && !empty(trim($keywordValue))) {
                        $keywordStrings[] = trim($keywordValue);
                    }
                }

                $keywordStrings = array_slice($keywordStrings, 0, $keywordsPerQuestion);
                
                $questionKeywords[$question] = $keywordStrings;

                usleep(100000);

            } catch (\Exception $e) {
                $questionKeywords[$question] = [];
            }
        }

        return $questionKeywords;
    }

    public function combineQuestions(array $serpQuestions, array $alsoAskedQuestions): array
    {
        $allQuestions = array_merge($serpQuestions, $alsoAskedQuestions);

        $uniqueQuestions = [];
        $seenQuestions = [];

        foreach ($allQuestions as $question) {
            $questionLower = strtolower(trim($question));

            if (empty($questionLower)) {
                continue;
            }

            if (isset($seenQuestions[$questionLower])) {
                continue;
            }

            $isDuplicate = false;
            foreach ($seenQuestions as $seenQuestion => $value) {
                $similarity = $this->calculateQuestionSimilarity($questionLower, $seenQuestion);
                if ($similarity > 0.8) {
                    $isDuplicate = true;
                    break;
                }
            }

            if (!$isDuplicate) {
                $uniqueQuestions[] = $question;
                $seenQuestions[$questionLower] = true;
            }
        }

        return array_slice($uniqueQuestions, 0, 10);
    }

    protected function validateQuestionsUsage(array $faqs, array $sourceQuestions): void
    {
        $usedQuestions = 0;
        $sourceQuestionsLower = array_map('strtolower', $sourceQuestions);

        foreach ($faqs as $faq) {
            if (!isset($faq['question'])) {
                continue;
            }

            $faqQuestionLower = strtolower($faq['question']);

            foreach ($sourceQuestionsLower as $sourceQuestion) {
                $similarity = $this->calculateQuestionSimilarity($faqQuestionLower, $sourceQuestion);
                if ($similarity > 0.6) {
                    $usedQuestions++;
                    break;
                }
            }
        }

    }

    protected function calculateQuestionSimilarity(string $question1, string $question2): float
    {
        $words1 = array_filter(explode(' ', $question1));
        $words2 = array_filter(explode(' ', $question2));

        if (empty($words1) || empty($words2)) {
            return 0.0;
        }

        $commonWords = array_intersect($words1, $words2);
        $totalWords = count(array_unique(array_merge($words1, $words2)));

        if ($totalWords === 0) {
            return 0.0;
        }

        return count($commonWords) / $totalWords;
    }

    public function generateFaqsWithGemini(?string $url, ?string $topic, ?string $urlContent, array $serpQuestions, array $options, array $topKeywords = []): array
    {
        $config = config('citations.gemini');

        if (empty($config['api'])) {
            throw new \RuntimeException('Gemini API key is not configured. Please set GOOGLE_API_KEY in your .env file.');
        }

        $prompt = $this->buildFaqPrompt($url, $topic, $urlContent, $serpQuestions, $options, $topKeywords);

        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature' => $options['temperature'] ?? 0.9,
                'responseMimeType' => 'application/json',
                'maxOutputTokens' => 8000,
            ],
        ];

        $model = $config['model'] ?? 'gemini-1.5-pro';
        $apiKey = $config['api'];
        $endpoint = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s',
            $model,
            $apiKey
        );

        try {
            $response = Http::timeout($config['timeout'] ?? 60)
                ->retry($config['max_retries'] ?? 3, ($config['backoff_seconds'] ?? 2) * 1000)
                ->post($endpoint, $payload);

            if ($response->failed()) {
                throw new \RuntimeException('Gemini API error: ' . $response->body());
            }

            $responseData = $response->json();
            $text = $this->extractTextFromGeminiResponse($responseData);
            
            $faqs = $this->parseFaqResponse($text);

            if (!empty($serpQuestions)) {
                $this->validateQuestionsUsage($faqs, $serpQuestions);
            }

            return $this->validateAndFormatFaqs($faqs, count($serpQuestions), $topKeywords);

        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to generate FAQs: ' . $e->getMessage());
        }
    }

    protected function buildFaqPrompt(?string $url, ?string $topic, ?string $urlContent, array $serpQuestions, array $options, array $topKeywords = []): string
    {
        $template = $this->promptLoader->load('faq/generation');

        $urlSection = '';
        if ($url) {
            if ($urlContent) {
                $urlContent = mb_substr($urlContent, 0, 5000);
                $urlSection = "Target URL: {$url}\n\nWebsite Content (for context):\n{$urlContent}\n";
            } else {
                $urlSection = "Target URL: {$url}\n\nNote: Unable to fetch full content from URL. Generate FAQs based on the domain and common questions for this type of website.\n";
            }
        }

        $topicSection = '';
        if ($topic) {
            $topicSection = "Topic/Subject: {$topic}\n";
        }

        $questionsSection = '';
        if (!empty($serpQuestions)) {
            $questionsList = implode("\n", array_map(function ($question, $index) {
                return ($index + 1) . ". " . $question;
            }, $serpQuestions, array_keys($serpQuestions)));

            $questionsSection = "*** CRITICAL: QUESTIONS TO ANSWER (from SERP and AlsoAsked.io) ***\n";
            $questionsSection .= "You MUST answer these questions. DO NOT create your own questions. ";
            $questionsSection .= "These are real questions that users are actively searching for. ";
            $questionsSection .= "You must provide comprehensive answers for at least 8-10 of these questions.\n\n";
            
            $questionsSection .= "QUESTIONS TO ANSWER:\n";
            $questionsSection .= "{$questionsList}\n\n";
            
            if (!empty($topKeywords) && is_array($topKeywords)) {
                $keywordsList = implode(', ', $topKeywords);
                $questionsSection .= "*** SEO KEYWORDS (Top 10 by Audience) ***\n";
                $questionsSection .= "The following keywords are highly relevant to this topic (sorted by search volume):\n";
                $questionsSection .= "{$keywordsList}\n\n";
                $questionsSection .= "When answering the questions above, naturally incorporate these keywords into your answers ";
                $questionsSection .= "where relevant to improve search engine optimization. Use keywords organically and contextually.\n";
                $questionsSection .= "After each answer, indicate which keywords (from the list above) you used in that answer.\n\n";
            }
            
            $questionsSection .= "*** YOUR TASK: Answer these questions based on the provided content. ";
            $questionsSection .= "DO NOT generate new questions - only answer the questions provided above. ***\n\n";
        }

        $replacements = [
            'url_section' => $urlSection,
            'topic_section' => $topicSection,
            'serp_section' => $questionsSection,
        ];

        $systemPrompt = $template['system'] ?? '';
        $userTemplate = $template['user'] ?? '';
        $userPrompt = $this->placeholderReplacer->replace($userTemplate, $replacements);
        $fullPrompt = trim($systemPrompt) . "\n\n" . trim($userPrompt);

        return $fullPrompt;
    }

    protected function extractTextFromGeminiResponse(array $responseData): string
    {
        if (isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
            return (string) $responseData['candidates'][0]['content']['parts'][0]['text'];
        }

        throw new \RuntimeException('Invalid Gemini API response structure');
    }

    public function generateFaqsWithGPT(?string $url, ?string $topic, ?string $urlContent, array $serpQuestions, array $options, array $topKeywords = []): array
    {
        $config = config('citations.openai');

        if (empty($config['api_key'])) {
            throw new \RuntimeException('OpenAI API key is not configured. Please set OPENAI_API_KEY in your .env file.');
        }

        $prompt = $this->buildFaqPrompt($url, $topic, $urlContent, $serpQuestions, $options, $topKeywords);

        $messages = [
            [
                'role' => 'system',
                'content' => 'You are an expert at creating comprehensive FAQ content. Always respond with valid JSON array format.',
            ],
            [
                'role' => 'user',
                'content' => $prompt,
            ],
        ];

        $payload = [
            'model' => $config['model'] ?? 'gpt-4o',
            'temperature' => $options['temperature'] ?? 0.9,
            'response_format' => ['type' => 'json_object'],
            'messages' => $messages,
        ];

        $endpoint = rtrim($config['base_url'] ?? 'https://api.openai.com/v1', '/') . '/chat/completions';

        try {
            $response = Http::withToken($config['api_key'])
                ->timeout($config['timeout'] ?? 60)
                ->retry($config['max_retries'] ?? 3, ($config['backoff_seconds'] ?? 2) * 1000)
                ->post($endpoint, $payload);

            if ($response->failed()) {
                throw new \RuntimeException('OpenAI API error: ' . $response->body());
            }

            $responseData = $response->json();
            $text = $this->extractTextFromGPTResponse($responseData);
            
            $faqs = $this->parseFaqResponse($text);

            if (!empty($serpQuestions)) {
                $this->validateQuestionsUsage($faqs, $serpQuestions);
            }

            return $this->validateAndFormatFaqs($faqs, count($serpQuestions), $topKeywords);

        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to generate FAQs: ' . $e->getMessage());
        }
    }

    protected function extractTextFromGPTResponse(array $responseData): string
    {
        if (isset($responseData['choices'][0]['message']['content'])) {
            return (string) $responseData['choices'][0]['message']['content'];
        }

        throw new \RuntimeException('Invalid OpenAI API response structure');
    }

    protected function parseFaqResponse(string $text): array
    {
        $json = null;

        if (preg_match('/\[[\s\S]*\]/s', $text, $m)) {
            $potentialJson = trim($m[0]);
            $testDecode = json_decode($potentialJson, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($testDecode)) {
                $json = $potentialJson;
            }
        }

        if (empty($json)) {
            $extracted = JsonExtractor::extract($text);
            if (!empty($extracted)) {
                $testDecode = json_decode($extracted, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($testDecode)) {
                    if (array_values($testDecode) === $testDecode) {
                        $json = $extracted;
                    }
                }
            }
        }

        if (empty($json)) {
            $json = trim($text);
        }

        $data = json_decode($json, true);
        $lastError = json_last_error();

        if ($lastError === JSON_ERROR_NONE && is_string($data) && !empty($data)) {
            $data = json_decode($data, true);
            $lastError = json_last_error();
        }

        if ($lastError !== JSON_ERROR_NONE) {
            $cleaned = preg_replace('/```\s*$/', '', $cleaned);
            $cleaned = trim($cleaned);
            $data = json_decode($cleaned, true);
            $lastError = json_last_error();
        }

        if ($lastError !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Invalid JSON in FAQ response: ' . json_last_error_msg());
        }

        if (!is_array($data)) {
            throw new \RuntimeException('FAQ response must be an array, got: ' . gettype($data));
        }

        if (isset($data['question']) && isset($data['answer'])) {
            $data = [$data];
        } elseif (array_values($data) !== $data) {
            $data = array_values($data);
        }

        if (!empty($data) && is_string($data[0])) {
            throw new \RuntimeException('FAQ items are strings instead of objects. JSON parsing failed.');
        }

        return $data;
    }

    protected function validateAndFormatFaqs(array $faqs, int $sourceQuestionsCount = 0, array $topKeywords = []): array
    {
        $validated = [];
        $seenQuestions = [];

        foreach ($faqs as $index => $faq) {
            if (!is_array($faq)) {
                continue;
            }

            $question = trim($faq['question'] ?? $faq['q'] ?? '');
            $answer = trim($faq['answer'] ?? $faq['a'] ?? '');

            if (empty($question) || empty($answer)) {
                continue;
            }

            $questionKey = strtolower($question);

            if (isset($seenQuestions[$questionKey])) {
                continue;
            }

            $seenQuestions[$questionKey] = true;

            $faqItem = [
                'question' => $question,
                'answer' => $answer,
            ];

            $detectedKeywords = [];

            if (isset($faq['keywords']) && is_array($faq['keywords']) && !empty($faq['keywords'])) {
                $detectedKeywords = array_values(array_filter(array_map('trim', $faq['keywords'])));
            } elseif (isset($faq['used_keywords']) && is_array($faq['used_keywords']) && !empty($faq['used_keywords'])) {
                $detectedKeywords = array_values(array_filter(array_map('trim', $faq['used_keywords'])));
            }

            if (empty($detectedKeywords) && !empty($topKeywords)) {
                $answerText = strtolower($answer);
                foreach ($topKeywords as $keyword) {
                    if (stripos($answerText, strtolower($keyword)) !== false) {
                        $detectedKeywords[] = $keyword;
                    }
                }
            }

            if (!empty($detectedKeywords)) {
                $faqItem['keywords'] = array_unique($detectedKeywords);
            }

            $validated[] = $faqItem;

            if (count($validated) >= 10) {
                break;
            }
        }

        $minRequired = 3;
        if ($sourceQuestionsCount > 0) {
            if ($sourceQuestionsCount < 5) {
                $minRequired = max(3, $sourceQuestionsCount);
            } else {
                $minRequired = 5;
            }
        }

        if (count($validated) < $minRequired) {
            throw new \RuntimeException(
                sprintf(
                    'Expected at least %d FAQs but received only %d (out of %d received). Please ensure questions are provided from SERP or AlsoAsked.',
                    $minRequired,
                    count($validated),
                    count($faqs)
                )
            );
        }

        $finalFaqs = array_slice($validated, 0, 10);
        
        return $finalFaqs;
    }

    protected function getCacheKey(?string $url, ?string $topic, array $options): string
    {
        $key = 'faq_generator:' . md5(($url ?? '') . '|' . ($topic ?? '') . '|' . serialize($options));
        return $key;
    }

    protected function generateSourceHash(?string $url, ?string $topic, array $options): string
    {
        ksort($options);

        $hashInput = [
            'url' => $url ? strtolower(trim($url)) : null,
            'topic' => $topic ? strtolower(trim($topic)) : null,
            'options' => $options,
        ];

        $hash = md5(json_encode($hashInput));

        return $hash;
    }

    public function storeFaqsInDatabase(?string $url, ?string $topic, array $faqs, array $options, string $sourceHash): \App\Models\Faq
    {
        $originalCount = count($faqs);
        if ($originalCount > 10) {
            $faqs = array_slice($faqs, 0, 10);
        }

        $existingFaq = $this->faqRepository->findByHash($sourceHash);
        if ($existingFaq) {
            $this->faqRepository->incrementApiCallsSaved($existingFaq->id);
            $existingFaq->refresh();
            return $existingFaq;
        }

        try {
            $faqRecord = $this->faqRepository->create([
                'user_id' => Auth::id(),
                'url' => $url,
                'topic' => $topic,
                'faqs' => $faqs,
                'options' => $options,
                'source_hash' => $sourceHash,
                'api_calls_saved' => 0,
            ]);

            return $faqRecord;
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->getCode() === '23000' || str_contains($e->getMessage(), 'Duplicate entry')) {
                $existingFaq = null;
                for ($i = 0; $i < 3; $i++) {
                    $existingFaq = $this->faqRepository->findByHash($sourceHash);
                    if ($existingFaq) {
                        break;
                    }
                    usleep(100000);
                }
                
                if ($existingFaq) {
                    $this->faqRepository->incrementApiCallsSaved($existingFaq->id);
                    $existingFaq->refresh();
                    return $existingFaq;
                }
                
                throw new \RuntimeException('Duplicate entry detected but existing FAQ not found. This may indicate a database consistency issue.');
            }
            
            throw new \RuntimeException('Failed to store FAQs in database: ' . $e->getMessage());
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to store FAQs in database: ' . $e->getMessage());
        }
    }

    protected function isUrl(string $input): bool
    {
        $input = trim($input);

        if (empty($input)) {
            return false;
        }

        if (filter_var($input, FILTER_VALIDATE_URL)) {
            return true;
        }

        if (preg_match('/^https?:\/\//', $input)) {
            return true;
        }

        if (preg_match('/^([a-z0-9]+(-[a-z0-9]+)*\.)+[a-z]{2,}(\/.*)?$/i', $input)) {
            if (strpos($input, ' ') === false) {
                return true;
            }
        }

        return false;
    }

    protected function normalizeUrl(string $url): string
    {
        $url = trim($url);

        if (preg_match('/^https?:\/\//', $url)) {
            return $url;
        }

        if (preg_match('/^([a-z0-9]+(-[a-z0-9]+)*\.)+[a-z]{2,}/i', $url)) {
            return 'https://' . $url;
        }

        return $url;
    }

    public function getSourceHash(string $input, array $options = []): string
    {
        $isUrl = $this->isUrl($input);
        $url = $isUrl ? $input : null;
        $topic = $isUrl ? null : $input;

        return $this->generateSourceHash($url, $topic, $options);
    }

    public function findExistingFaq(string $input, array $options = []): ?\App\Models\Faq
    {
        $sourceHash = $this->getSourceHash($input, $options);
        return $this->faqRepository->findByHash($sourceHash);
    }

    public function incrementApiCallsSaved(int $faqId): void
    {
        $this->faqRepository->incrementApiCallsSaved($faqId);
    }

    public function createFaqTask(string $input, array $options = []): \App\Models\FaqTask
    {
        if (empty($input)) {
            throw new \InvalidArgumentException('Input field is required (URL or topic)');
        }

        $isUrl = $this->isUrl($input);
        $url = $isUrl ? $this->normalizeUrl($input) : null;
        $topic = $isUrl ? null : $input;

        $lockKey = 'faq:task:lock:' . md5(serialize([$url, $topic, $options]));
        $timeout = config('cache_locks.faq.timeout', 120);
        
        return Cache::lock($lockKey, $timeout)->get(function () use ($url, $topic, $options) {
            $normalizedTopic = $topic ? strtolower(trim($topic)) : null;
            $normalizedUrl = $url ? $this->normalizeUrl($url) : null;

            $query = \App\Models\FaqTask::whereIn('status', ['pending', 'processing']);
            
            if ($normalizedTopic) {
                $query->whereNotNull('topic')
                      ->whereRaw('LOWER(TRIM(topic)) = ?', [$normalizedTopic]);
                if ($normalizedUrl) {
                    $query->where(function($q) use ($normalizedUrl) {
                        $q->where('url', $normalizedUrl)->orWhereNull('url');
                    });
                } else {
                    $query->whereNull('url');
                }
            } elseif ($normalizedUrl) {
                $query->where('url', $normalizedUrl)->whereNull('topic');
            } else {
                $query->whereNull('url')->whereNull('topic');
            }

            $existingTask = $query->orderBy('created_at', 'desc')->first();
            
            if ($existingTask) {
                return $existingTask;
            }
            
            $failedQuery = \App\Models\FaqTask::where('status', 'failed')
                ->whereNotNull('serp_questions');
            
            if ($normalizedTopic) {
                $failedQuery->whereNotNull('topic')
                      ->whereRaw('LOWER(TRIM(topic)) = ?', [$normalizedTopic]);
                if ($normalizedUrl) {
                    $failedQuery->where(function($q) use ($normalizedUrl) {
                        $q->where('url', $normalizedUrl)->orWhereNull('url');
                    });
                } else {
                    $failedQuery->whereNull('url');
                }
            } elseif ($normalizedUrl) {
                $failedQuery->where('url', $normalizedUrl)->whereNull('topic');
            } else {
                $failedQuery->whereNull('url')->whereNull('topic');
            }

            $failedTask = $failedQuery->orderBy('created_at', 'desc')->first();
            
            if ($failedTask) {
                $hasKeywords = false;
                if (!empty($failedTask->question_keywords) && is_array($failedTask->question_keywords)) {
                    $totalKeywords = 0;
                    foreach ($failedTask->question_keywords as $keywords) {
                        if (is_array($keywords) && !empty($keywords)) {
                            $totalKeywords += count($keywords);
                        }
                    }
                    $hasKeywords = $totalKeywords > 0;
                }
                
                if (!$hasKeywords && !empty($failedTask->serp_questions)) {
                    $failedTask->update([
                        'status' => 'pending',
                        'error_message' => null,
                    ]);
                    \App\Jobs\ProcessFaqTask::dispatch($failedTask->id);
                    return $failedTask;
                }
            }

            $serpQuestions = $this->fetchSerpQuestions($url, $topic);
            
            if (empty($serpQuestions)) {
                throw new \RuntimeException('No questions found from SERP. Please ensure SERP service is configured.');
            }

            $alsoAskedService = $this->getAlsoAskedService();
            $alsoAskedSearchId = null;

            if ($alsoAskedService->isAvailable()) {
                $searchQuery = $this->buildSearchQuery($url, $topic);
                
                if (!empty($searchQuery)) {
                    $termsArray = [$searchQuery];
                    $alsoAskedSearchId = $alsoAskedService->createAsyncSearchJob(
                        $termsArray,
                        'en',
                        'us',
                        2
                    );
                }
            }

            // Extract search keyword for context
            $searchKeyword = $topic ?? ($url ? parse_url($url, PHP_URL_HOST) : '');
            if ($searchKeyword && strpos($searchKeyword, 'www.') === 0) {
                $searchKeyword = str_replace('www.', '', $searchKeyword);
            }

            $task = \App\Models\FaqTask::create([
                'user_id' => Auth::id(),
                'url' => $url,
                'topic' => $topic,
                'search_keyword' => $searchKeyword,
                'serp_questions' => $serpQuestions,
                'alsoasked_search_id' => $alsoAskedSearchId,
                'options' => $options,
                'status' => $alsoAskedSearchId ? 'pending' : 'processing',
            ]);

            \App\Jobs\ProcessFaqTask::dispatch($task->id);

            return $task;
        });
    }

    protected function mapLocationCodeToRegion(int $locationCode): string
    {
        $locationCodeService = app(LocationCodeService::class);
        return $locationCodeService->mapLocationCodeToRegion($locationCode, 'us');
    }

    protected function shouldTryLLM(string $provider): bool
    {
        $circuitBreakerKey = "faq:llm:circuit_breaker:{$provider}";
        $failureCount = Cache::get($circuitBreakerKey . ':failures', 0);
        $lastFailure = Cache::get($circuitBreakerKey . ':last_failure');

        if ($failureCount >= 5) {
            if ($lastFailure && now()->diffInMinutes($lastFailure) < 10) {
                return false;
            }
            Cache::forget($circuitBreakerKey . ':failures');
        }

        return true;
    }

    protected function recordLLMSuccess(string $provider): void
    {
        $circuitBreakerKey = "faq:llm:circuit_breaker:{$provider}";
        Cache::forget($circuitBreakerKey . ':failures');
        Cache::forget($circuitBreakerKey . ':last_failure');
    }

    protected function recordLLMFailure(string $provider): void
    {
        $circuitBreakerKey = "faq:llm:circuit_breaker:{$provider}";
        $failures = Cache::increment($circuitBreakerKey . ':failures', 1);
        Cache::put($circuitBreakerKey . ':last_failure', now(), 600);
    }

    /**
     * Generate answers for a specific set of questions with keyword context.
     * Used for progressive answer generation (SERP and PAA separately).
     */
    public function generateAnswersForQuestions(
        array $questions,
        string $keyword,
        ?string $url = null,
        ?string $topic = null,
        array $options = [],
        array $topKeywords = []
    ): array {
        if (empty($questions)) {
            return [];
        }

        $urlContent = null;
        if ($url) {
            $urlContentCacheKey = 'faq:url_content:' . md5($url);
            $urlContent = Cache::remember($urlContentCacheKey, 3600, function () use ($url) {
                return $this->fetchUrlContent($url);
            });
        }

        $faqs = null;
        $lastException = null;

        // Try Gemini first
        if ($this->shouldTryLLM('gemini')) {
            try {
                $faqs = $this->generateAnswersWithGemini(
                    $questions,
                    $keyword,
                    $url,
                    $topic,
                    $urlContent,
                    $options,
                    $topKeywords
                );
                $this->recordLLMSuccess('gemini');
            } catch (\Exception $geminiException) {
                $lastException = $geminiException;
                $this->recordLLMFailure('gemini');

                // Try GPT fallback
                if ($this->shouldTryLLM('gpt')) {
                    try {
                        $faqs = $this->generateAnswersWithGPT(
                            $questions,
                            $keyword,
                            $url,
                            $topic,
                            $urlContent,
                            $options,
                            $topKeywords
                        );
                        $this->recordLLMSuccess('gpt');
                    } catch (\Exception $gptException) {
                        $lastException = $gptException;
                        $this->recordLLMFailure('gpt');
                        throw new \RuntimeException('Failed to generate answers: ' . $lastException->getMessage());
                    }
                } else {
                    throw new \RuntimeException('Failed to generate answers: ' . $lastException->getMessage());
                }
            }
        } else {
            // Circuit breaker open for Gemini, try GPT
            if ($this->shouldTryLLM('gpt')) {
                try {
                    $faqs = $this->generateAnswersWithGPT(
                        $questions,
                        $keyword,
                        $url,
                        $topic,
                        $urlContent,
                        $options,
                        $topKeywords
                    );
                    $this->recordLLMSuccess('gpt');
                } catch (\Exception $gptException) {
                    $lastException = $gptException;
                    $this->recordLLMFailure('gpt');
                    throw new \RuntimeException('Failed to generate answers: ' . $lastException->getMessage());
                }
            } else {
                throw new \RuntimeException('All LLM APIs circuit breaker open.');
            }
        }

        if (empty($faqs)) {
            throw new \RuntimeException('Failed to generate answers: No FAQs generated');
        }

        return $faqs;
    }

    /**
     * Generate answers using Gemini with keyword-focused prompt.
     */
    protected function generateAnswersWithGemini(
        array $questions,
        string $keyword,
        ?string $url,
        ?string $topic,
        ?string $urlContent,
        array $options,
        array $topKeywords = []
    ): array {
        $config = config('citations.gemini');

        if (empty($config['api'])) {
            throw new \RuntimeException('Gemini API key is not configured.');
        }

        $prompt = $this->buildKeywordFocusedPrompt($questions, $keyword, $url, $topic, $urlContent, $topKeywords);

        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature' => $options['temperature'] ?? 0.9,
                'responseMimeType' => 'application/json',
                'maxOutputTokens' => 8000,
            ],
        ];

        $model = $config['model'] ?? 'gemini-1.5-pro';
        $apiKey = $config['api'];
        $endpoint = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s',
            $model,
            $apiKey
        );

        try {
            $response = Http::timeout($config['timeout'] ?? 60)
                ->retry($config['max_retries'] ?? 3, ($config['backoff_seconds'] ?? 2) * 1000)
                ->post($endpoint, $payload);

            if ($response->failed()) {
                throw new \RuntimeException('Gemini API error: ' . $response->body());
            }

            $responseData = $response->json();
            $text = $this->extractTextFromGeminiResponse($responseData);
            
            $faqs = $this->parseFaqResponse($text);

            return $this->validateAndFormatFaqs($faqs, count($questions), $topKeywords);

        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to generate answers with Gemini: ' . $e->getMessage());
        }
    }

    /**
     * Generate answers using GPT with keyword-focused prompt.
     */
    protected function generateAnswersWithGPT(
        array $questions,
        string $keyword,
        ?string $url,
        ?string $topic,
        ?string $urlContent,
        array $options,
        array $topKeywords = []
    ): array {
        $config = config('citations.openai');

        if (empty($config['api_key'])) {
            throw new \RuntimeException('OpenAI API key is not configured.');
        }

        $prompt = $this->buildKeywordFocusedPrompt($questions, $keyword, $url, $topic, $urlContent, $topKeywords);

        $messages = [
            [
                'role' => 'system',
                'content' => 'You are an expert at creating comprehensive FAQ content. Always respond with valid JSON array format.',
            ],
            [
                'role' => 'user',
                'content' => $prompt,
            ],
        ];

        $payload = [
            'model' => $config['model'] ?? 'gpt-4o',
            'temperature' => $options['temperature'] ?? 0.9,
            'response_format' => ['type' => 'json_object'],
            'messages' => $messages,
        ];

        $endpoint = rtrim($config['base_url'] ?? 'https://api.openai.com/v1', '/') . '/chat/completions';

        try {
            $response = Http::withToken($config['api_key'])
                ->timeout($config['timeout'] ?? 60)
                ->retry($config['max_retries'] ?? 3, ($config['backoff_seconds'] ?? 2) * 1000)
                ->post($endpoint, $payload);

            if ($response->failed()) {
                throw new \RuntimeException('OpenAI API error: ' . $response->body());
            }

            $responseData = $response->json();
            $text = $this->extractTextFromGPTResponse($responseData);
            
            $faqs = $this->parseFaqResponse($text);

            return $this->validateAndFormatFaqs($faqs, count($questions), $topKeywords);

        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to generate answers with GPT: ' . $e->getMessage());
        }
    }

    /**
     * Build a keyword-focused prompt for answering questions.
     */
    protected function buildKeywordFocusedPrompt(
        array $questions,
        string $keyword,
        ?string $url,
        ?string $topic,
        ?string $urlContent,
        array $topKeywords = []
    ): string {
        $contextSection = "Context: Generate answers for the following questions related to the keyword: \"{$keyword}\"\n\n";

        if ($topic) {
            $contextSection .= "Topic/Subject: {$topic}\n\n";
        }

        if ($url) {
            $contextSection .= "Target URL: {$url}\n";
            if ($urlContent) {
                $urlContent = mb_substr($urlContent, 0, 3000);
                $contextSection .= "Website Content (for context):\n{$urlContent}\n\n";
            }
        }

        $questionsList = implode("\n", array_map(function ($question, $index) {
            return ($index + 1) . ". " . $question;
        }, $questions, array_keys($questions)));

        $prompt = $contextSection;
        $prompt .= "Questions:\n{$questionsList}\n\n";
        $prompt .= "Requirements:\n";
        $prompt .= "- Answers should be keyword-focused and SEO-optimized\n";
        $prompt .= "- Each answer should be comprehensive but concise\n";
        $prompt .= "- Answers should be suitable for FAQ schema markup\n";
        $prompt .= "- Maintain consistency in tone and style\n";
        $prompt .= "- Focus on the keyword: \"{$keyword}\" when relevant\n";

        if (!empty($topKeywords) && is_array($topKeywords)) {
            $keywordsList = implode(', ', array_slice($topKeywords, 0, 10));
            $prompt .= "\nSEO Keywords to incorporate naturally: {$keywordsList}\n";
        }

        $prompt .= "\nPlease provide answers in JSON format as an array of objects with 'question' and 'answer' fields.\n";
        $prompt .= "Example format: [{\"question\": \"Question text\", \"answer\": \"Answer text\"}, ...]\n";

        return $prompt;
    }
}
