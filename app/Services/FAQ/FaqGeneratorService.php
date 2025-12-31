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
use App\Exceptions\SerpException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Auth;

class FaqGeneratorService
{
    protected LLMClient $llmClient;
    protected FaqRepositoryInterface $faqRepository;
    protected PromptLoader $promptLoader;
    protected PlaceholderReplacer $placeholderReplacer;
    protected ?SerpService $serpService = null;
    protected ?AlsoAskedService $alsoAskedService = null;
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

        $faqs = null;
        $lastException = null;

        if ($this->shouldTryLLM('gemini')) {
            try {
                $faqs = $this->generateFaqsWithGemini($url, $topic, $urlContent, $allQuestions, $options);
                $this->recordLLMSuccess('gemini');
            } catch (\Exception $geminiException) {
                $lastException = $geminiException;
                $this->recordLLMFailure('gemini');

                if ($this->shouldTryLLM('gpt')) {
                    try {
                        $faqs = $this->generateFaqsWithGPT($url, $topic, $urlContent, $allQuestions, $options);
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
                    $faqs = $this->generateFaqsWithGPT($url, $topic, $urlContent, $allQuestions, $options);
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
                // If SerpService can't be instantiated (e.g., missing API key),
                // throw a more descriptive exception
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

    /**
     * Generate FAQs from SERP response by extracting questions and answers from related_questions
     * This is used as a fallback when Gemini API fails
     */
    public function generateFaqsFromSerpResponse(?string $url, ?string $topic, array $serpResponse): array
    {
        $faqs = [];

        // Extract from related_questions (has question and snippet fields)
        if (isset($serpResponse['related_questions']) && is_array($serpResponse['related_questions'])) {
            foreach ($serpResponse['related_questions'] as $item) {
                if (isset($item['question']) && !empty(trim($item['question']))) {
                    $question = trim($item['question']);
                    $answer = '';

                    // Get answer from snippet if available
                    if (isset($item['snippet']) && !empty(trim($item['snippet']))) {
                        $answer = trim($item['snippet']);
                    } elseif (isset($item['answer']) && !empty(trim($item['answer']))) {
                        $answer = trim($item['answer']);
                    } elseif (isset($item['text']) && !empty(trim($item['text']))) {
                        $answer = trim($item['text']);
                    }

                    // Only add if we have both question and answer
                    if (!empty($answer)) {
                        $faqs[] = [
                            'question' => $question,
                            'answer' => $answer,
                        ];
                    }
                }
            }
        }

        // Also check knowledge_graph for AI overview answers
        if (isset($serpResponse['knowledge_graph'])) {
            $kg = $serpResponse['knowledge_graph'];
            
            // Check free section
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
            
            // Check pricing section
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

        // Limit to reasonable number and ensure format
        $faqs = array_slice($faqs, 0, 20);
        
        // Ensure all FAQs have required structure
        return array_map(function ($faq) {
            return [
                'question' => $faq['question'] ?? '',
                'answer' => $faq['answer'] ?? '',
            ];
        }, $faqs);
    }

    /**
     * Fetch full SERP response for fallback FAQ generation
     */
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

        return array_slice($uniqueQuestions, 0, 30);
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

    public function generateFaqsWithGemini(?string $url, ?string $topic, ?string $urlContent, array $serpQuestions, array $options): array
    {
        $config = config('citations.gemini');

        if (empty($config['api'])) {
            throw new \RuntimeException('Gemini API key is not configured. Please set GOOGLE_API_KEY in your .env file.');
        }

        $prompt = $this->buildFaqPrompt($url, $topic, $urlContent, $serpQuestions, $options);

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

            return $this->validateAndFormatFaqs($faqs, count($serpQuestions));

        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to generate FAQs: ' . $e->getMessage());
        }
    }

    protected function buildFaqPrompt(?string $url, ?string $topic, ?string $urlContent, array $serpQuestions, array $options): string
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

    public function generateFaqsWithGPT(?string $url, ?string $topic, ?string $urlContent, array $serpQuestions, array $options): array
    {
        $config = config('citations.openai');

        if (empty($config['api_key'])) {
            throw new \RuntimeException('OpenAI API key is not configured. Please set OPENAI_API_KEY in your .env file.');
        }

        $prompt = $this->buildFaqPrompt($url, $topic, $urlContent, $serpQuestions, $options);

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

            return $this->validateAndFormatFaqs($faqs, count($serpQuestions));

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

    protected function validateAndFormatFaqs(array $faqs, int $sourceQuestionsCount = 0): array
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

            $validated[] = [
                'question' => $question,
                'answer' => $answer,
            ];

            if (count($validated) >= 10) {
                break;
            }
        }

        // Calculate minimum required FAQs dynamically:
        // - At least 3 FAQs (minimum quality threshold)
        // - If we have fewer source questions, accept that many (but still minimum 3)
        // - If we have 5+ source questions, require at least 5 FAQs
        $minRequired = 3;
        if ($sourceQuestionsCount > 0) {
            // If we have fewer than 5 source questions, accept that many (but still minimum 3)
            if ($sourceQuestionsCount < 5) {
                $minRequired = max(3, $sourceQuestionsCount);
            } else {
                // If we have 5+ source questions, require at least 5 FAQs
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

        return array_slice($validated, 0, 10);
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

        return md5(json_encode($hashInput));
    }

    public function storeFaqsInDatabase(?string $url, ?string $topic, array $faqs, array $options, string $sourceHash): \App\Models\Faq
    {
        // Check if FAQ with same source_hash already exists
        $existingFaq = $this->faqRepository->findByHash($sourceHash);
        if ($existingFaq) {
            // Increment API calls saved since we're reusing this FAQ
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
            // Handle duplicate entry error specifically
            if ($e->getCode() === '23000' || str_contains($e->getMessage(), 'Duplicate entry')) {
                // Retry finding the existing FAQ with a small delay to handle race conditions
                $existingFaq = null;
                for ($i = 0; $i < 3; $i++) {
                    $existingFaq = $this->faqRepository->findByHash($sourceHash);
                    if ($existingFaq) {
                        break;
                    }
                    // Small delay to allow for database replication/transaction commit
                    usleep(100000); // 0.1 second
                }
                
                if ($existingFaq) {
                    $this->faqRepository->incrementApiCallsSaved($existingFaq->id);
                    $existingFaq->refresh();
                    return $existingFaq;
                }
                
                // If we still can't find it, throw
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

    /**
     * Check if FAQ exists in database by source hash
     */
    public function findExistingFaq(string $input, array $options = []): ?\App\Models\Faq
    {
        $sourceHash = $this->getSourceHash($input, $options);
        return $this->faqRepository->findByHash($sourceHash);
    }

    /**
     * Increment API calls saved for an existing FAQ
     */
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

        // Request deduplication - check for in-progress tasks
        $lockKey = 'faq:task:lock:' . md5(serialize([$url, $topic, $options]));
        $timeout = config('cache_locks.faq.timeout', 120);
        
        return Cache::lock($lockKey, $timeout)->get(function () use ($url, $topic, $options) {
            // Check for existing in-progress task
            $existingTask = \App\Models\FaqTask::where('url', $url)
                ->where('topic', $topic)
                ->whereIn('status', ['pending', 'processing'])
                ->orderBy('created_at', 'desc')
                ->first();
            
            if ($existingTask) {
                return $existingTask;
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

            $task = \App\Models\FaqTask::create([
                'user_id' => Auth::id(),
                'url' => $url,
                'topic' => $topic,
                'serp_questions' => $serpQuestions,
                'alsoasked_search_id' => $alsoAskedSearchId,
                'options' => $options,
                'status' => $alsoAskedSearchId ? 'pending' : 'processing',
            ]);

            if ($alsoAskedSearchId) {
                \App\Jobs\ProcessFaqTask::dispatch($task->id)->delay(now()->addSeconds(2));
            } else {
                \App\Jobs\ProcessFaqTask::dispatch($task->id);
            }

            return $task;
        });
    }

    /**
     * Map location code to region string for AlsoAsked API
     */
    protected function mapLocationCodeToRegion(int $locationCode): string
    {
        $locationCodeService = app(LocationCodeService::class);
        return $locationCodeService->mapLocationCodeToRegion($locationCode, 'us');
    }

    /**
     * Check if LLM service should be tried (circuit breaker)
     */
    protected function shouldTryLLM(string $provider): bool
    {
        $circuitBreakerKey = "faq:llm:circuit_breaker:{$provider}";
        $failureCount = Cache::get($circuitBreakerKey . ':failures', 0);
        $lastFailure = Cache::get($circuitBreakerKey . ':last_failure');

        // Circuit breaker opens after 5 consecutive failures
        if ($failureCount >= 5) {
            // Check if we should try again (after 10 minutes)
            if ($lastFailure && now()->diffInMinutes($lastFailure) < 10) {
                return false;
            }
            // Reset after cooldown period
            Cache::forget($circuitBreakerKey . ':failures');
        }

        return true;
    }

    /**
     * Record LLM success (reset circuit breaker)
     */
    protected function recordLLMSuccess(string $provider): void
    {
        $circuitBreakerKey = "faq:llm:circuit_breaker:{$provider}";
        Cache::forget($circuitBreakerKey . ':failures');
        Cache::forget($circuitBreakerKey . ':last_failure');
    }

    /**
     * Record LLM failure (increment circuit breaker)
     */
    protected function recordLLMFailure(string $provider): void
    {
        $circuitBreakerKey = "faq:llm:circuit_breaker:{$provider}";
        $failures = Cache::increment($circuitBreakerKey . ':failures', 1);
        Cache::put($circuitBreakerKey . ':last_failure', now(), 600); // 10 minutes
    }
}
