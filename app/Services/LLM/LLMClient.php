<?php

namespace App\Services\LLM;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class LLMClient
{
    protected const RAW_RESPONSE_LIMIT = 10000;

    protected ?string $openAiKey;
    protected string $openAiBase;
    protected ?string $googleApiKey;

    public function __construct()
    {
        $this->openAiKey = config('citations.openai.api_key', '');
        $this->openAiBase = config('citations.openai.base_url', 'https://api.openai.com/v1');
        $this->googleApiKey = config('citations.gemini.api') ??'';
    }

    public function canUseOpenAi(): bool
    {
        return !empty($this->openAiKey) && $this->providerAvailable('openai');
    }

    public function canUseGemini(): bool
    {
        return !empty($this->googleApiKey) && $this->providerAvailable('gemini');
    }

    public function generateQueries(string $url, int $count): array
    {
        if (!$this->canUseOpenAi()) {
            Log::info('OpenAI query generation skipped - provider unavailable', [
                'url' => $url,
                'count' => $count,
            ]);
            return [];
        }

        $template = $this->promptSections('query_generation');
        $messages = [
            [
                'role' => 'system',
                'content' => $this->replacePlaceholders($template['system'] ?? '', [
                    '{url}' => $url,
                    '{N}' => (string) $count,
                ]),
            ],
            [
                'role' => 'user',
                'content' => $this->replacePlaceholders($template['user'] ?? '', [
                    '{url}' => $url,
                    '{N}' => (string) $count,
                ]),
            ],
        ];

        Log::info('Calling OpenAI for query generation', [
            'provider' => 'openai',
            'url' => $url,
            'requested_count' => $count,
            'model' => config('citations.openai.model'),
            'messages_count' => count($messages),
        ]);

        try {
            $startTime = microtime(true);
            $response = $this->callOpenAi($messages);
            $duration = round((microtime(true) - $startTime) * 1000, 2); // milliseconds
            
            Log::info('OpenAI query generation response received', [
                'provider' => 'openai',
                'url' => $url,
                'duration_ms' => $duration,
                'response_structure' => [
                    'has_choices' => isset($response['choices']),
                    'choices_count' => isset($response['choices']) ? count($response['choices']) : 0,
                    'response_keys' => array_keys($response),
                ],
                'full_response' => $response,
            ]);
            
            $content = $response['choices'][0]['message']['content'] ?? '[]';
            
            Log::info('OpenAI query generation raw content', [
                'provider' => 'openai',
                'url' => $url,
                'raw_content' => $content,
                'content_length' => strlen($content),
            ]);
            
            $content = $this->extractJsonFromMarkdown($content);
            
            Log::info('OpenAI query generation extracted JSON', [
                'provider' => 'openai',
                'url' => $url,
                'extracted_content' => $content,
            ]);
            
            $decoded = json_decode($content, true);

            if (!is_array($decoded)) {
                Log::error('OpenAI query generation JSON decode failed', [
                    'provider' => 'openai',
                    'url' => $url,
                    'content' => $content,
                    'json_error' => json_last_error_msg(),
                ]);
                throw new \RuntimeException('OpenAI query generation did not return valid JSON: ' . json_last_error_msg());
            }

            $this->resetProviderFailures('openai');

            $list = array_values(array_filter(array_map('trim', $decoded), fn ($q) => is_string($q) && $q !== ''));

            $result = array_slice($list, 0, $count);

            Log::info('OpenAI query generation successful', [
                'provider' => 'openai',
                'url' => $url,
                'requested_count' => $count,
                'generated_count' => count($result),
                'duration_ms' => $duration,
                'queries' => $result,
            ]);

            return $result;
        } catch (\Throwable $e) {
            $this->recordProviderFailure('openai');
            Log::error('OpenAI query generation failed', [
                'provider' => 'openai',
                'url' => $url,
                'count' => $count,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return [];
        }
    }

    public function checkCitationOpenAi(string $query, string $targetUrl): array
    {
        if (!$this->canUseOpenAi()) {
            Log::info('OpenAI citation check skipped - provider unavailable', [
                'provider' => 'openai',
                'query' => $query,
                'target_url' => $targetUrl,
            ]);
            return $this->providerUnavailablePayload('gpt', 'openai_unavailable');
        }

        $template = $this->promptSections('citation_openai');
        $messages = [
            ['role' => 'system', 'content' => $template['system'] ?? ''],
            [
                'role' => 'user',
                'content' => $this->replacePlaceholders($template['user'] ?? '', [
                    '{query}' => $query,
                    '{url}' => $targetUrl,
                ]),
            ],
        ];

        Log::info('Calling OpenAI for citation check', [
            'provider' => 'openai',
            'query' => $query,
            'target_url' => $targetUrl,
            'model' => config('citations.openai.model'),
        ]);

        try {
            $startTime = microtime(true);
            $response = $this->callOpenAi($messages, 0.3);
            $duration = round((microtime(true) - $startTime) * 1000, 2); // milliseconds
            
            Log::info('OpenAI citation check response received', [
                'provider' => 'openai',
                'query' => $query,
                'target_url' => $targetUrl,
                'duration_ms' => $duration,
                'response_structure' => [
                    'has_choices' => isset($response['choices']),
                    'choices_count' => isset($response['choices']) ? count($response['choices']) : 0,
                    'response_keys' => array_keys($response),
                ],
                'full_response' => $response,
            ]);
            
            $content = $response['choices'][0]['message']['content'] ?? '{}';
            
            Log::info('OpenAI citation check raw content', [
                'provider' => 'openai',
                'query' => $query,
                'target_url' => $targetUrl,
                'raw_content' => $content,
                'content_length' => strlen($content),
            ]);
            
            $parsed = $this->parseCitationResponse($content);
            $parsed['raw_response'] = $this->truncateRaw($response);
            $this->resetProviderFailures('openai');

            Log::info('OpenAI citation check successful', [
                'provider' => 'openai',
                'query' => $query,
                'target_url' => $targetUrl,
                'citation_found' => $parsed['citation_found'],
                'confidence' => $parsed['confidence'],
                'references_count' => count($parsed['citation_references']),
                'duration_ms' => $duration,
                'response' => [
                    'citation_found' => $parsed['citation_found'],
                    'confidence' => $parsed['confidence'],
                    'citation_references' => $parsed['citation_references'],
                    'explanation' => $parsed['explanation'],
                ],
            ]);

            return $parsed;
        } catch (\Throwable $e) {
            $this->recordProviderFailure('openai');
            Log::error('OpenAI citation check failed', [
                'provider' => 'openai',
                'query' => $query,
                'target_url' => $targetUrl,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorPayload('gpt', $e->getMessage());
        }
    }

    public function checkCitationGemini(string $query, string $targetUrl): array
    {
        if (!$this->canUseGemini()) {
            Log::info('Gemini citation check skipped - provider unavailable', [
                'provider' => 'gemini',
                'query' => $query,
                'target_url' => $targetUrl,
            ]);
            return $this->providerUnavailablePayload('gemini', 'gemini_unavailable');
        }

        $template = $this->promptSections('citation_gemini');
        $prompt = $this->replacePlaceholders($template['user'] ?? '', [
            '{query}' => $query,
            '{url}' => $targetUrl,
        ]);

        Log::info('Calling Gemini for citation check', [
            'provider' => 'gemini',
            'query' => $query,
            'target_url' => $targetUrl,
            'model' => config('citations.gemini.model'),
        ]);

        try {
            $startTime = microtime(true);
            $response = $this->callGemini(
                $template['system'] ?? '',
                $prompt
            );
            $duration = round((microtime(true) - $startTime) * 1000, 2); // milliseconds

            Log::info('Gemini citation check response received', [
                'provider' => 'gemini',
                'query' => $query,
                'target_url' => $targetUrl,
                'duration_ms' => $duration,
                'response_structure' => [
                    'has_candidates' => isset($response['candidates']),
                    'candidates_count' => isset($response['candidates']) ? count($response['candidates']) : 0,
                    'response_keys' => array_keys($response),
                ],
                'full_response' => $response,
            ]);

            $text = $response['candidates'][0]['content']['parts'][0]['text'] ?? '{}';
            
            Log::info('Gemini citation check raw content', [
                'provider' => 'gemini',
                'query' => $query,
                'target_url' => $targetUrl,
                'raw_content' => $text,
                'content_length' => strlen($text),
            ]);
            
            $parsed = $this->parseCitationResponse($text);
            $parsed['raw_response'] = $this->truncateRaw($response);
            $this->resetProviderFailures('gemini');

            Log::info('Gemini citation check successful', [
                'provider' => 'gemini',
                'query' => $query,
                'target_url' => $targetUrl,
                'citation_found' => $parsed['citation_found'],
                'confidence' => $parsed['confidence'],
                'references_count' => count($parsed['citation_references']),
                'duration_ms' => $duration,
                'response' => [
                    'citation_found' => $parsed['citation_found'],
                    'confidence' => $parsed['confidence'],
                    'citation_references' => $parsed['citation_references'],
                    'explanation' => $parsed['explanation'],
                ],
            ]);

            return $parsed;
        } catch (\Throwable $e) {
            $this->recordProviderFailure('gemini');
            Log::error('Gemini citation check failed', [
                'provider' => 'gemini',
                'query' => $query,
                'target_url' => $targetUrl,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorPayload('gemini', $e->getMessage());
        }
    }

    protected function callOpenAi(array $messages, float $temperature = 0.2): array
    {
        $config = config('citations.openai');
        $client = $this->openAiClient();

        $response = $client->post('/chat/completions', [
            'model' => $config['model'],
            'messages' => $messages,
            'temperature' => $temperature,
        ])->throw()->json();

        return $response;
    }

    protected function callGemini(string $systemPrompt, string $userPrompt): array
    {
        $config = config('citations.gemini');

        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => trim($systemPrompt . "\n\n" . $userPrompt)],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature' => 0.3,
            ],
        ];

        $endpoint = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s',
            $config['model'],
            $this->googleApiKey
        );

        return Http::withHeaders([
            'Content-Type' => 'application/json',
        ])
            ->timeout($config['timeout'])
            ->retry($config['max_retries'], $config['backoff_seconds']) // correct: seconds, not ms
            ->post($endpoint, $payload)
            ->throw()
            ->json();
    }

    protected function openAiClient(): PendingRequest
    {
        $config = config('citations.openai');

        return Http::withToken($this->openAiKey)
            ->acceptJson()
            ->baseUrl($this->openAiBase)
            ->timeout($config['timeout'])
            ->retry($config['max_retries'], $config['backoff_seconds'] * 1000);
    }

    protected function promptSections(string $name): array
    {
        if (str_starts_with($name, 'keyword/')) {
            $path = resource_path("prompts/{$name}.md");
        } else {
            $path = resource_path("prompts/citation/{$name}.md");
        }
        
        if (!file_exists($path)) {
            return ['system' => '', 'user' => ''];
        }

        $content = file_get_contents($path);
        $parts = preg_split('/^User:/mi', $content, 2);
        $system = '';
        $user = '';

        if (count($parts) === 2) {
            $system = trim(preg_replace('/^System:\s*/mi', '', $parts[0]));
            $user = trim($parts[1]);
        } else {
            $user = trim($content);
        }

        return ['system' => $system, 'user' => $user];
    }

    protected function replacePlaceholders(string $text, array $replacements): string
    {
        return str_replace(array_keys($replacements), array_values($replacements), $text);
    }

    protected function extractJsonFromMarkdown(string $content): string
    {
        $content = trim($content);
        
        if (preg_match('/```(?:json)?\s*(.*?)\s*```/s', $content, $matches)) {
            return trim($matches[1]);
        }
        
        return $content;
    }

    protected function parseCitationResponse(string $content): array
    {
        $content = $this->extractJsonFromMarkdown($content);
        
        $decoded = json_decode($content, true);

        if (!is_array($decoded)) {
            return [
                'citation_found' => false,
                'confidence' => 0,
                'citation_references' => [],
                'explanation' => 'Model returned non-JSON payload',
                'raw_response' => $this->truncateRaw($content),
            ];
        }

        return [
            'citation_found' => (bool) ($decoded['citation_found'] ?? false),
            'confidence' => (int) ($decoded['confidence'] ?? 0),
            'citation_references' => array_values(array_filter(
                $decoded['citation_references'] ?? [],
                fn ($item) => is_string($item) && $item !== ''
            )),
            'explanation' => $decoded['explanation'] ?? '',
        ];
    }

    protected function truncateRaw(mixed $raw): string
    {
        $serialized = is_string($raw) ? $raw : json_encode($raw, JSON_PRETTY_PRINT);
        if (!$serialized) {
            return '';
        }

        return Str::limit($serialized, self::RAW_RESPONSE_LIMIT, '... [truncated]');
    }

    protected function providerUnavailablePayload(string $providerKey, string $reason): array
    {
        return [
            'citation_found' => false,
            'confidence' => 0,
            'citation_references' => [],
            'explanation' => $reason,
            'raw_response' => null,
            'provider' => $providerKey,
        ];
    }

    protected function errorPayload(string $providerKey, string $message): array
    {
        return [
            'citation_found' => false,
            'confidence' => 0,
            'citation_references' => [],
            'explanation' => $message,
            'raw_response' => null,
            'provider' => $providerKey,
        ];
    }

    protected function providerAvailable(string $provider): bool
    {
        $failures = Cache::get($this->providerCacheKey($provider), 0);
        $limit = config("citations.{$provider}.circuit_breaker", 5);

        return $failures < $limit;
    }

    protected function recordProviderFailure(string $provider): void
    {
        $key = $this->providerCacheKey($provider);
        $count = Cache::increment($key);
        if ($count === 1) {
            Cache::put($key, 1, now()->addMinutes(15));
        }
    }

    protected function resetProviderFailures(string $provider): void
    {
        Cache::forget($this->providerCacheKey($provider));
    }

    protected function providerCacheKey(string $provider): string
    {
        return sprintf('citations:%s:failures', $provider);
    }

    /**
     * Analyze keyword intent and AI visibility
     *
     * @param string $keyword
     * @return array{intent_category: string, intent: string, difficulty: string, required_entities: array, competitiveness: string, structured_data_helpful: array, ai_visibility_score: float, explanation: string}
     */
    public function analyzeKeywordIntent(string $keyword): array
    {
        if (!$this->canUseOpenAi() && !$this->canUseGemini()) {
            Log::info('Keyword intent analysis skipped - no LLM provider available');
            return $this->defaultIntentAnalysis($keyword);
        }

        $template = $this->promptSections('keyword/intent_analysis');
        $messages = [
            [
                'role' => 'system',
                'content' => $template['system'] ?? '',
            ],
            [
                'role' => 'user',
                'content' => $this->replacePlaceholders($template['user'] ?? '', [
                    '{keyword}' => $keyword,
                ]),
            ],
        ];

        Log::info('Calling LLM for keyword intent analysis', [
            'keyword' => $keyword,
            'provider' => $this->canUseOpenAi() ? 'openai' : 'gemini',
        ]);

        try {
            $startTime = microtime(true);
            
            if ($this->canUseOpenAi()) {
                $response = $this->callOpenAi($messages, 0.3);
                $content = $response['choices'][0]['message']['content'] ?? '{}';
            } else {
                $response = $this->callGemini($template['system'] ?? '', $messages[1]['content']);
                $content = $response['candidates'][0]['content']['parts'][0]['text'] ?? '{}';
            }
            
            $duration = round((microtime(true) - $startTime) * 1000, 2);

            $parsed = $this->parseIntentResponse($content);
            
            Log::info('Keyword intent analysis successful', [
                'keyword' => $keyword,
                'intent_category' => $parsed['intent_category'],
                'ai_visibility_score' => $parsed['ai_visibility_score'],
                'duration_ms' => $duration,
            ]);

            return $parsed;
        } catch (\Throwable $e) {
            Log::error('Keyword intent analysis failed', [
                'keyword' => $keyword,
                'error' => $e->getMessage(),
            ]);
            return $this->defaultIntentAnalysis($keyword);
        }
    }

    /**
     * Analyze multiple keywords in batch
     *
     * @param array<string> $keywords
     * @return array<string, array>
     */
    public function analyzeKeywordIntents(array $keywords): array
    {
        $results = [];
        
        foreach ($keywords as $keyword) {
            $results[$keyword] = $this->analyzeKeywordIntent($keyword);
            usleep(100000);
        }

        return $results;
    }

    /**
     * Parse intent analysis response
     */
    protected function parseIntentResponse(string $content): array
    {
        $jsonMatch = [];
        if (preg_match('/\{[^}]+\}/s', $content, $jsonMatch)) {
            $decoded = json_decode($jsonMatch[0], true);
            if (is_array($decoded)) {
                return $this->normalizeIntentResponse($decoded);
            }
        }

        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            return $this->normalizeIntentResponse($decoded);
        }

        return $this->defaultIntentAnalysis('');
    }

    /**
     * Normalize intent response to ensure all fields are present
     */
    protected function normalizeIntentResponse(array $data): array
    {
        return [
            'intent_category' => $data['intent_category'] ?? 'informational',
            'intent' => $data['intent'] ?? '',
            'difficulty' => $data['difficulty'] ?? 'medium',
            'required_entities' => $data['required_entities'] ?? [],
            'competitiveness' => $data['competitiveness'] ?? 'medium',
            'structured_data_helpful' => $data['structured_data_helpful'] ?? [],
            'ai_visibility_score' => (float) ($data['ai_visibility_score'] ?? 50.0),
            'explanation' => $data['explanation'] ?? '',
        ];
    }

    /**
     * Default intent analysis when LLM is unavailable
     */
    protected function defaultIntentAnalysis(string $keyword): array
    {
        $isQuestion = preg_match('/^(what|how|why|when|where|who|can|should|is|are|do|does)/i', $keyword);
        $isCommercial = preg_match('/(buy|price|cost|cheap|best|review|compare)/i', $keyword);
        
        $intentCategory = 'informational';
        if ($isCommercial) {
            $intentCategory = 'commercial';
        } elseif (preg_match('/(login|sign in|website|official)/i', $keyword)) {
            $intentCategory = 'navigational';
        } elseif (preg_match('/(buy|purchase|order)/i', $keyword)) {
            $intentCategory = 'transactional';
        }

        $aiVisibilityScore = 50.0;
        if ($isQuestion) {
            $aiVisibilityScore = 75.0;
        }
        if ($isCommercial) {
            $aiVisibilityScore = 40.0;
        }

        return [
            'intent_category' => $intentCategory,
            'intent' => 'Automated analysis',
            'difficulty' => 'medium',
            'required_entities' => [],
            'competitiveness' => 'medium',
            'structured_data_helpful' => ['FAQPage', 'Article'],
            'ai_visibility_score' => $aiVisibilityScore,
            'explanation' => 'Default analysis (LLM unavailable)',
        ];
    }

}

