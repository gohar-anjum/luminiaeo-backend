<?php

namespace App\Services\LLM;

use App\Services\LLM\Prompt\PlaceholderReplacer;
use App\Services\LLM\Prompt\PromptLoader;
use App\Services\LLM\Support\JsonExtractor;
use App\Services\LLM\Transformers\KeywordIntentParser;
use App\Services\LLM\Failures\ProviderCircuitBreaker;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class LLMClient
{
    public function __construct(
        protected PromptLoader $prompts,
        protected PlaceholderReplacer $replacer,
        protected ProviderCircuitBreaker $breaker,
        protected KeywordIntentParser $keywordParser,
    ) {
    }

    public function analyzeKeywordIntent(string $keyword): array
    {
        $template = $this->prompts->load('keyword/intent_analysis');
        $messages = [
            ['role' => 'system', 'content' => $template['system'] ?? 'You analyze keyword intent.'],
            [
                'role' => 'user',
                'content' => $this->replacer->replace($template['user'] ?? 'Analyze the following search query: {{ keyword }}', [
                    'keyword' => $keyword,
                ]),
            ],
        ];

        foreach ($this->preferredProviders() as $provider) {
            if (!$this->canUseProvider($provider)) {
                continue;
            }

            try {
                $raw = $this->sendWithProvider($provider, $messages, ['temperature' => 0.1]);
                $text = $this->extractTextFromRaw($raw, $provider);
                $parsed = $this->keywordParser->parse($text);
                $this->breaker->clearFailures($provider);

                return $this->normalizeIntentResponse($parsed);
            } catch (\Throwable $e) {
                $this->breaker->recordFailure($provider);
                Log::error('Keyword intent analysis failed', [
                    'provider' => $provider,
                    'keyword' => $keyword,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->defaultIntentFallback('No provider available');
    }

    public function generateQueries(string $url, int $count): array
    {
        $count = min(max($count, 1), config('citations.max_queries', 5000));
        $batchSize = max(50, config('citations.query_generation.max_per_call', 250));

        $template = $this->prompts->load('citation/query_generation');
        $system = $template['system'] ?? '';
        $userTemplate = $template['user'] ?: "Target URL: {{ url }}\nRequested Queries: {{ N }}\nReturn a JSON array of unique search queries.";

        $collected = [];
        $attempts = 0;
        $maxAttempts = max(ceil($count / $batchSize) * 2, 8);

        while (count($collected) < $count && $attempts < $maxAttempts) {
            $remaining = $count - count($collected);
            $currentBatch = min($batchSize, $remaining);

            $userPrompt = $this->replacer->replace($userTemplate, [
                'url' => $url,
                'N' => (string) $currentBatch,
            ]);

            $messages = [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $userPrompt],
            ];

            $response = $this->generateWithAnyProvider($messages);

            if (!$response) {
                break;
            }

            $text = $this->extractTextFromRaw($response['raw'], $response['provider']);
            $queries = $this->parseQueriesFromText($text);
            $collected = array_values(array_unique(array_merge($collected, $queries)));
            $attempts++;

            if (empty($queries)) {
                Log::warning('Query generation returned empty batch', [
                    'provider' => $response['provider'],
                    'attempt' => $attempts,
                ]);
            }
        }

        return array_slice($collected, 0, $count);
    }

    public function batchValidateCitations(array $queries, string $targetUrl, string $provider): array
    {
        if (empty($queries)) {
            return [];
        }

        if (!$this->canUseProvider($provider)) {
            Log::warning('Provider unavailable for batch validation', ['provider' => $provider]);
            return [];
        }

        $template = $this->prompts->load('citation/batch_validation');
        $system = $template['system'] ?? '';
        $userTemplate = $template['user'] ?? '';

        $targetDomain = $this->normalizeDomain($targetUrl);
        $batchSize = max(1, config('citations.validation.batch_size', 25));
        $alias = $this->providerAlias($provider);

        $results = [];

        foreach (array_chunk($queries, $batchSize, true) as $chunk) {
            $payload = [
                'target_url' => $targetUrl,
                'target_domain' => $targetDomain,
                'queries' => collect($chunk)
                    ->map(fn ($query, $index) => ['index' => $index, 'query' => $query])
                    ->values()
                    ->all(),
            ];

            $userPrompt = $this->replacer->replace($userTemplate, [
                'url' => $targetUrl,
                'domain' => (string) $targetDomain,
            ]);

            $messages = [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => trim($userPrompt . "\n\n" . json_encode($payload, JSON_PRETTY_PRINT))],
            ];

            try {
                $raw = $this->sendWithProvider($provider, $messages, ['temperature' => 0.1]);
                $text = $this->extractTextFromRaw($raw, $provider);
                
                // Log the complete raw response from LLM
                Log::info('Citation batch validation - Complete LLM Response', [
                    'provider' => $provider,
                    'target_url' => $targetUrl,
                    'target_domain' => $targetDomain,
                    'chunk_size' => count($chunk),
                    'queries' => $chunk,
                    'response_length' => strlen($text),
                    'raw_response_text' => $text,
                    'raw_response_json' => $raw,
                ]);
                
                $parsed = $this->parseCitationBatchResponse($text, $chunk, $targetDomain, $alias);
                
                // Log parsed results with URL information
                $totalUrls = 0;
                $queriesWithUrls = 0;
                $queriesWithCitations = 0;
                foreach ($parsed as $index => $result) {
                    $urlCount = count($result['citation_references'] ?? []);
                    $hasCitation = $result['citation_found'] ?? false;
                    
                    if ($urlCount > 0) {
                        $totalUrls += $urlCount;
                        $queriesWithUrls++;
                    }
                    if ($hasCitation) {
                        $queriesWithCitations++;
                    }
                    
                    Log::info('Citation batch validation - Parsed Result', [
                        'provider' => $provider,
                        'query_index' => $index,
                        'query' => $chunk[$index] ?? 'unknown',
                        'citation_found' => $hasCitation,
                        'confidence' => $result['confidence'] ?? 0,
                        'urls_received' => $urlCount,
                        'urls' => $result['citation_references'] ?? [],
                        'has_urls' => $urlCount > 0,
                        'full_result' => $result,
                    ]);
                }
                
                Log::info('Citation batch validation - Summary', [
                    'provider' => $provider,
                    'target_url' => $targetUrl,
                    'chunk_size' => count($chunk),
                    'total_queries' => count($parsed),
                    'queries_with_citations' => $queriesWithCitations,
                    'queries_with_urls' => $queriesWithUrls,
                    'total_urls_received' => $totalUrls,
                    'avg_urls_per_query' => count($chunk) > 0 ? round($totalUrls / count($chunk), 2) : 0,
                ]);
                
                $results = array_replace($results, $parsed);
                $this->breaker->clearFailures($provider);
            } catch (\Throwable $e) {
                $this->breaker->recordFailure($provider);
                Log::error('Citation batch validation failed', [
                    'provider' => $provider,
                    'error' => $e->getMessage(),
                ]);

                foreach ($chunk as $index => $query) {
                    $results[$index] = $this->defaultCitationResult($alias, $query, $e->getMessage());
                }
            }
        }

        ksort($results);

        return $results;
    }

    public function checkCitationOpenAi(string $query, string $targetUrl): array
    {
        $results = $this->batchValidateCitations([$query], $targetUrl, 'openai');
        return $results[0] ?? $this->defaultCitationResult('gpt', $query, 'No response');
    }

    public function checkCitationGemini(string $query, string $targetUrl): array
    {
        $results = $this->batchValidateCitations([$query], $targetUrl, 'gemini');
        return $results[0] ?? $this->defaultCitationResult('gemini', $query, 'No response');
    }

    protected function preferredProviders(): array
    {
        return ['openai', 'gemini'];
    }

    protected function canUseProvider(string $provider): bool
    {
        if ($this->breaker->isBlocked($provider)) {
            return false;
        }

        return match ($provider) {
            'openai' => !empty(config('citations.openai.api_key')),
            'gemini' => !empty(config('citations.gemini.api')),
            default => false,
        };
    }

    protected function sendWithProvider(string $provider, array $messages, array $options = []): array
    {
        return match ($provider) {
            'openai' => $this->callOpenAi($messages, $options),
            'gemini' => $this->callGemini($messages, $options),
            default => throw new \InvalidArgumentException("Unknown provider {$provider}"),
        };
    }

    protected function callOpenAi(array $messages, array $options = []): array
    {
        $config = config('citations.openai');

        $payload = array_filter([
            'model' => $config['model'] ?? 'gpt-4o',
            'temperature' => $options['temperature'] ?? 0.2,
            'response_format' => $options['response_format'] ?? ['type' => 'json_object'],
            'messages' => $messages,
        ]);

        $response = Http::withToken($config['api_key'] ?? '')
            ->timeout($config['timeout'] ?? 60)
            ->retry($config['max_retries'] ?? 3, ($config['backoff_seconds'] ?? 2) * 1000)
            ->post(rtrim($config['base_url'] ?? 'https://api.openai.com/v1', '/') . '/chat/completions', $payload);

        if ($response->failed()) {
            throw new \RuntimeException('OpenAI API error: ' . $response->body());
        }

        return $response->json();
    }

    protected function callGemini(array $messages, array $options = []): array
    {
        $config = config('citations.gemini');
        $prompt = $this->flattenMessagesForGemini($messages);

        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature' => $options['temperature'] ?? 0.2,
            ],
        ];

        $endpoint = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s',
            $config['model'] ?? 'gemini-1.5-pro',
            $config['api']
        );

        $response = Http::timeout($config['timeout'] ?? 60)
            ->retry($config['max_retries'] ?? 3, ($config['backoff_seconds'] ?? 2) * 1000)
            ->post($endpoint, $payload);

        if ($response->failed()) {
            throw new \RuntimeException('Gemini API error: ' . $response->body());
        }

        return $response->json();
    }

    protected function flattenMessagesForGemini(array $messages): string
    {
        return collect($messages)
            ->map(fn ($message) => strtoupper($message['role']) . ': ' . trim($message['content']))
            ->implode("\n\n");
    }

    protected function extractTextFromRaw(mixed $raw, string $providerName): string
    {
        if (is_array($raw)) {
            if (isset($raw['choices'][0]['message']['content'])) {
                return (string) $raw['choices'][0]['message']['content'];
            }

            if (isset($raw['candidates'][0]['content']['parts'][0]['text'])) {
                return (string) $raw['candidates'][0]['content']['parts'][0]['text'];
            }

            if (isset($raw['content']) && is_string($raw['content'])) {
                return $raw['content'];
            }

            return json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        }

        return (string) $raw;
    }

    protected function parseCitationBatchResponse(string $text, array $chunk, ?string $targetDomain, string $providerAlias): array
    {
        $json = JsonExtractor::extract($text) ?? $text;
        $decoded = json_decode($json, true);

        $entries = [];
        if (is_array($decoded)) {
            if (isset($decoded['results']) && is_array($decoded['results'])) {
                $entries = $decoded['results'];
            } elseif (Arr::isList($decoded)) {
                $entries = $decoded;
            }
        }

        Log::info('Parsing citation batch response', [
            'provider' => $providerAlias,
            'target_domain' => $targetDomain,
            'entries_found' => count($entries),
            'chunk_size' => count($chunk),
            'json_extracted' => $json !== $text,
        ]);

        $mapped = [];
        foreach ($entries as $entry) {
            $index = $entry['index'] ?? $this->matchQueryIndex($entry['query'] ?? null, $chunk);
            if ($index === null) {
                Log::warning('Citation batch entry missing index', [
                    'provider' => $providerAlias,
                    'entry' => $entry,
                ]);
                continue;
            }

            // Extract URLs from entry
            $rawUrls = (array) ($entry['target_urls'] ?? $entry['citation_references'] ?? []);
            $refs = array_values(array_filter(
                $rawUrls,
                fn ($url) => $targetDomain ? $this->isTargetDomain($this->normalizeDomain($url), $targetDomain) : true
            ));

            $citationFound = (bool) ($entry['target_cited'] ?? $entry['citation_found'] ?? false);
            // Anti-hallucination: do not trust citation_found when no valid target URL was provided
            if ($citationFound && count($refs) === 0) {
                $citationFound = false;
                Log::info('Citation batch: set citation_found to false (model claimed true but no valid target URLs)', [
                    'provider' => $providerAlias,
                    'query_index' => $index,
                    'query' => $chunk[$index] ?? 'unknown',
                ]);
            }

            // Log each entry's URL information
            Log::info('Citation batch entry parsed', [
                'provider' => $providerAlias,
                'query_index' => $index,
                'query' => $chunk[$index] ?? 'unknown',
                'citation_found' => $citationFound,
                'confidence' => (float) ($entry['confidence'] ?? 0),
                'raw_urls_count' => count($rawUrls),
                'raw_urls' => $rawUrls,
                'filtered_urls_count' => count($refs),
                'filtered_urls' => $refs,
                'target_domain' => $targetDomain,
                'urls_filtered_out' => count($rawUrls) - count($refs),
            ]);

            $mapped[$index] = [
                'provider' => $providerAlias,
                'citation_found' => $citationFound,
                'confidence' => (float) ($entry['confidence'] ?? 0),
                'citation_references' => $refs,
                'competitors' => $this->normalizeCompetitors($entry['competitors'] ?? [], $targetDomain),
                'explanation' => $entry['notes'] ?? ($entry['explanation'] ?? ''),
                'raw_response' => Str::limit($text, 12000, '... [truncated]'),
            ];
        }

        foreach ($chunk as $index => $query) {
            if (!isset($mapped[$index])) {
                Log::warning('Citation batch entry missing for query', [
                    'provider' => $providerAlias,
                    'query_index' => $index,
                    'query' => $query,
                ]);
                $mapped[$index] = $this->defaultCitationResult($providerAlias, $query, 'Unable to parse provider response');
            }
        }

        return $mapped;
    }

    protected function matchQueryIndex(?string $query, array $chunk): ?int
    {
        if (!$query) {
            return null;
        }

        foreach ($chunk as $index => $value) {
            if (Str::lower(trim($value)) === Str::lower(trim($query))) {
                return $index;
            }
        }

        return null;
    }

    protected function normalizeCompetitors(array $competitors, ?string $targetDomain): array
    {
        $normalized = [];

        foreach ($competitors as $competitor) {
            $domain = $this->normalizeDomain($competitor['domain'] ?? ($competitor['url'] ?? null));
            if (!$domain) {
                continue;
            }

            if ($targetDomain && $this->isTargetDomain($domain, $targetDomain)) {
                continue;
            }

            $key = $this->rootDomain($domain);
            if (isset($normalized[$key])) {
                continue;
            }

            $normalized[$key] = [
                'domain' => $key,
                'url' => $competitor['url'] ?? null,
                'reason' => $competitor['reason'] ?? '',
            ];

            if (count($normalized) >= 2) {
                break;
            }
        }

        return array_values($normalized);
    }

    protected function parseQueriesFromText(string $text): array
    {
        $json = JsonExtractor::extract($text);

        if ($json) {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                if (isset($decoded['queries']) && is_array($decoded['queries'])) {
                    $decoded = $decoded['queries'];
                }

                if (Arr::isList($decoded)) {
                    return array_values(array_filter(array_map(
                        fn ($item) => is_string($item) ? trim($item) : null,
                        $decoded
                    )));
                }
            }
        }

        $lines = array_map('trim', preg_split('/\r\n|\r|\n/', $text));
        return array_values(array_filter($lines, fn ($line) => strlen($line) > 2));
    }

    protected function generateWithAnyProvider(array $messages): ?array
    {
        foreach ($this->preferredProviders() as $provider) {
            if (!$this->canUseProvider($provider)) {
                continue;
            }

            try {
                $raw = $this->sendWithProvider($provider, $messages, [
                    'temperature' => 0.4,
                    'response_format' => ['type' => 'json_object'],
                ]);

                $this->breaker->clearFailures($provider);

                return [
                    'provider' => $provider,
                    'raw' => $raw,
                ];
            } catch (\Throwable $e) {
                $this->breaker->recordFailure($provider);
                Log::error('Query generation failed', [
                    'provider' => $provider,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return null;
    }

    protected function normalizeIntentResponse(array $data): array
    {
        return [
            'intent_category' => $data['intent_category'] ?? $data['intentCategory'] ?? 'informational',
            'intent' => $data['intent'] ?? '',
            'difficulty' => $data['difficulty'] ?? 'medium',
            'required_entities' => $data['required_entities'] ?? $data['requiredEntities'] ?? [],
            'competitiveness' => $data['competitiveness'] ?? 'medium',
            'structured_data_helpful' => $data['structured_data_helpful'] ?? $data['structuredDataHelpful'] ?? ['FAQPage', 'Article'],
            'ai_visibility_score' => isset($data['ai_visibility_score'])
                ? (float) $data['ai_visibility_score']
                : (isset($data['score']) ? (float) $data['score'] : 50.0),
            'explanation' => $data['explanation'] ?? ($data['note'] ?? ''),
        ];
    }

    protected function defaultIntentFallback(?string $reason = null): array
    {
        return [
            'intent_category' => 'informational',
            'intent' => 'unknown',
            'difficulty' => 'medium',
            'required_entities' => [],
            'competitiveness' => 'medium',
            'structured_data_helpful' => ['FAQPage', 'Article'],
            'ai_visibility_score' => 0.0,
            'explanation' => $reason ?? 'LLM provider unavailable',
        ];
    }

    protected function defaultCitationResult(string $providerAlias, string $query, string $reason): array
    {
        return [
            'provider' => $providerAlias,
            'citation_found' => false,
            'confidence' => 0.0,
            'citation_references' => [],
            'competitors' => [],
            'explanation' => $reason,
            'raw_response' => null,
            'query' => $query,
        ];
    }

    protected function providerAlias(string $provider): string
    {
        return $provider === 'openai' ? 'gpt' : 'gemini';
    }

    protected function normalizeDomain(?string $value): ?string
    {
        if (!$value) {
            return null;
        }

        $host = parse_url($value, PHP_URL_HOST) ?: $value;
        $host = strtolower(trim($host));
        $host = preg_replace('/^www\./', '', $host);

        return $host ?: null;
    }

    protected function rootDomain(?string $domain): ?string
    {
        if (!$domain) {
            return null;
        }

        $parts = explode('.', $domain);
        if (count($parts) <= 2) {
            return $domain;
        }

        return implode('.', array_slice($parts, -2));
    }

    protected function isTargetDomain(?string $domain, ?string $targetDomain): bool
    {
        if (!$domain || !$targetDomain) {
            return false;
        }

        return $this->rootDomain($domain) === $this->rootDomain($targetDomain);
    }
}
