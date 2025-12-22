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
use App\Exceptions\SerpException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

        $urlContent = null;
        if ($url) {
            $urlContent = $this->fetchUrlContent($url);
        }

        $serpQuestions = $this->fetchSerpQuestions($url, $topic);
        $alsoAskedQuestions = $this->fetchAlsoAskedQuestions($url, $topic);

        $allQuestions = $this->combineQuestions($serpQuestions, $alsoAskedQuestions);

        if (empty($allQuestions)) {
            Log::warning('No questions found from SERP or AlsoAsked', [
                'url' => $url,
                'topic' => $topic,
            ]);
            throw new \RuntimeException(
                'No questions found from SERP or AlsoAsked. Please ensure at least one of these services is configured and returns questions.'
            );
        }

        $faqs = $this->generateFaqsWithGemini($url, $topic, $urlContent, $allQuestions, $options);
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

    protected function fetchUrlContent(string $url): ?string
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

    protected function fetchSerpQuestions(?string $url, ?string $topic): array
    {
        try {
            Log::info('Starting SERP questions fetch', [
                'url' => $url,
                'topic' => $topic,
            ]);

            if (!$this->isSerpServiceAvailable()) {
                Log::info('SERP service not available, skipping');
                return [];
            }

            $searchQuery = $this->buildSearchQuery($url, $topic);

            Log::info('SERP search query built', [
                'search_query' => $searchQuery,
            ]);

            if (empty($searchQuery)) {
                Log::info('SERP search query is empty, skipping');
                return [];
            }

            $serpService = $this->getSerpService();

            Log::info('Calling SERP API', [
                'search_query' => $searchQuery,
            ]);

            $serpResults = $serpService->getSerpResults(
                $searchQuery,
                'en',
                2840
            );

            Log::info('SERP API call completed', [
                'results_type' => gettype($serpResults),
                'results_keys' => is_array($serpResults) ? array_keys($serpResults) : [],
            ]);

            $questions = $this->extractPeopleAlsoAskQuestions($serpResults);

            Log::info('SERP questions extracted', [
                'questions_count' => count($questions),
                'questions' => $questions,
            ]);

            return $questions;
        } catch (SerpException $e) {
            Log::warning('SERP API error while fetching questions', [
                'error' => $e->getMessage(),
                'url' => $url,
                'topic' => $topic,
            ]);
            return [];
        } catch (\Exception $e) {
            Log::warning('Error fetching SERP questions', [
                'error' => $e->getMessage(),
                'url' => $url,
                'topic' => $topic,
            ]);
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
            $this->serpService = app(SerpService::class);
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

    protected function fetchAlsoAskedQuestions(?string $url, ?string $topic): array
    {
        try {
            Log::info('Starting AlsoAsked questions fetch', [
                'url' => $url,
                'topic' => $topic,
            ]);

            $alsoAskedService = $this->getAlsoAskedService();

            if (!$alsoAskedService->isAvailable()) {
                Log::info('AlsoAsked service not available, skipping');
                return [];
            }

            $searchQuery = $this->buildSearchQuery($url, $topic);

            if (empty($searchQuery)) {
                Log::info('AlsoAsked search query is empty, skipping');
                return [];
            }

            Log::info('Calling AlsoAsked API', [
                'search_query' => $searchQuery,
            ]);

            $questions = $alsoAskedService->search(
                $searchQuery,
                'en',
                'us',
                2,
                false
            );

            Log::info('AlsoAsked API call completed', [
                'questions_count' => count($questions),
                'questions' => array_slice($questions, 0, 10),
            ]);

            return $questions;
        } catch (\Exception $e) {
            Log::warning('Error fetching AlsoAsked questions', [
                'error' => $e->getMessage(),
                'url' => $url,
                'topic' => $topic,
            ]);
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

    protected function combineQuestions(array $serpQuestions, array $alsoAskedQuestions): array
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

        Log::info('Combined questions from multiple sources', [
            'serp_questions_count' => count($serpQuestions),
            'alsoasked_questions_count' => count($alsoAskedQuestions),
            'total_combined' => count($allQuestions),
            'unique_questions_count' => count($uniqueQuestions),
        ]);

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

        Log::info('Source questions usage validation', [
            'total_source_questions' => count($sourceQuestions),
            'used_questions' => $usedQuestions,
            'usage_percentage' => count($faqs) > 0 ? ($usedQuestions / count($faqs)) * 100 : 0,
        ]);

        if ($usedQuestions < 7 && count($sourceQuestions) >= 10) {
            Log::warning('Low source questions usage', [
                'used' => $usedQuestions,
                'expected_minimum' => 7,
                'total_source_questions' => count($sourceQuestions),
            ]);
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

    protected function generateFaqsWithGemini(?string $url, ?string $topic, ?string $urlContent, array $serpQuestions, array $options): array
    {
        $config = config('citations.gemini');

        if (empty($config['api'])) {
            Log::error('Gemini API key is not configured');
            throw new \RuntimeException('Gemini API key is not configured. Please set GOOGLE_API_KEY in your .env file.');
        }

        Log::info('Generating FAQs with Gemini', [
            'has_questions' => !empty($serpQuestions),
            'questions_count' => count($serpQuestions),
            'questions_sample' => array_slice($serpQuestions, 0, 5),
        ]);

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
                Log::error('Gemini API error for FAQ generation', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                throw new \RuntimeException('Gemini API error: ' . $response->body());
            }

            $responseData = $response->json();
            $text = $this->extractTextFromGeminiResponse($responseData);
            $faqs = $this->parseFaqResponse($text);

            if (!empty($serpQuestions)) {
                $this->validateQuestionsUsage($faqs, $serpQuestions);
            }

            return $this->validateAndFormatFaqs($faqs);

        } catch (\Exception $e) {
            Log::error('Error generating FAQs with Gemini', [
                'error' => $e->getMessage(),
                'url' => $url,
                'topic' => $topic,
            ]);
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
            Log::error('JSON decode error in FAQ response', [
                'error' => json_last_error_msg(),
                'json_preview' => mb_substr($json, 0, 500),
            ]);
            throw new \RuntimeException('Invalid JSON in FAQ response: ' . json_last_error_msg());
        }

        if (!is_array($data)) {
            Log::error('FAQ response is not an array', [
                'type' => gettype($data),
            ]);
            throw new \RuntimeException('FAQ response must be an array, got: ' . gettype($data));
        }

        if (isset($data['question']) && isset($data['answer'])) {
            $data = [$data];
        } elseif (array_values($data) !== $data) {
            $data = array_values($data);
        }

        if (!empty($data) && is_string($data[0])) {
            Log::error('FAQ items are strings instead of objects');
            throw new \RuntimeException('FAQ items are strings instead of objects. JSON parsing failed.');
        }

        return $data;
    }

    protected function validateAndFormatFaqs(array $faqs): array
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

        if (count($validated) < 5) {
            Log::warning('Gemini returned fewer than 5 FAQs', [
                'validated_count' => count($validated),
                'total_received' => count($faqs),
            ]);
            throw new \RuntimeException(
                sprintf(
                    'Expected at least 5 FAQs but received only %d (out of %d received). Please ensure questions are provided from SERP or AlsoAsked.',
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

    protected function storeFaqsInDatabase(?string $url, ?string $topic, array $faqs, array $options, string $sourceHash): \App\Models\Faq
    {
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
        } catch (\Exception $e) {
            Log::error('Failed to store FAQs in database', [
                'error' => $e->getMessage(),
            ]);

            return new \App\Models\Faq();
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
}
