<?php

namespace App\Services\LLM\Citation;

use App\Services\LLM\Prompt\PromptLoader;
use App\Services\LLM\Prompt\PlaceholderReplacer;
use App\Services\LLM\Transformers\CitationParser;
use App\Services\LLM\Drivers\Contracts\LLMProviderInterface;
use App\Services\LLM\Failures\ProviderCircuitBreaker;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CitationValidator
{
    public function __construct(
        protected PromptLoader $prompts,
        protected PlaceholderReplacer $replacer,
        protected CitationParser $parser,
        protected ProviderCircuitBreaker $breaker
    ) {}

    public function validate(LLMProviderInterface $provider, string $query, string $targetUrl): array
    {
        $name = $provider->name();

        if (!$provider->isAvailable() || $this->breaker->isBlocked($name)) {
            Log::info('Provider unavailable for validation', ['provider' => $name]);
            return [
                'provider' => $name,
                'citation_found' => false,
                'confidence' => 0.0,
                'references' => [],
                'raw' => null,
                'error' => 'provider_unavailable',
            ];
        }

        $template = $this->prompts->load('citation_validation');
        $userPrompt = $this->replacer->replace($template['user'] ?? '', [
            'query' => $query,
            'url' => $targetUrl,
        ]);

        $messages = [
            ['role' => 'system', 'content' => $template['system'] ?? ''],
            ['role' => 'user', 'content' => $userPrompt],
        ];

        try {
            $raw = $provider->send($messages);

            $text = $this->extractTextFromRaw($raw, $name);
            
            // Log the complete raw response from LLM
            Log::info('Citation validation - Complete LLM Response', [
                'provider' => $name,
                'query' => $query,
                'target_url' => $targetUrl,
                'raw_response_text' => $text,
                'raw_response_json' => $raw,
            ]);
            
            $parsed = $this->parser->parse($text);

            // Log parsed results
            $citationFound = (bool) ($parsed['citation_found'] ?? false);
            $references = array_values($parsed['citation_references'] ?? []);
            $urlCount = count($references);
            
            Log::info('Citation validation - Parsed Result', [
                'provider' => $name,
                'query' => $query,
                'target_url' => $targetUrl,
                'citation_found' => $citationFound,
                'confidence' => (float) ($parsed['confidence'] ?? 0.0),
                'urls_received' => $urlCount,
                'urls' => $references,
                'has_urls' => $urlCount > 0,
                'parsed_data' => $parsed,
            ]);

            // Log warning if citation_found is true but no URLs provided
            if ($citationFound && $urlCount === 0) {
                Log::warning('Citation found but no URLs provided', [
                    'provider' => $name,
                    'query' => $query,
                    'target_url' => $targetUrl,
                    'parsed_data' => $parsed,
                ]);
            }

            // Log warning if URLs provided but citation_found is false
            if (!$citationFound && $urlCount > 0) {
                Log::warning('URLs provided but citation_found is false', [
                    'provider' => $name,
                    'query' => $query,
                    'target_url' => $targetUrl,
                    'url_count' => $urlCount,
                    'urls' => $references,
                ]);
            }

            $this->breaker->clearFailures($name);

            return [
                'provider' => $name,
                'citation_found' => $citationFound,
                'confidence' => (float) ($parsed['confidence'] ?? 0.0),
                'references' => $references,
                'raw' => $this->truncateRaw($raw),
            ];
        } catch (\Throwable $e) {
            $this->breaker->recordFailure($name);
            Log::error('Citation validation failed for provider', [
                'provider' => $name,
                'error' => $e->getMessage(),
            ]);

            return [
                'provider' => $name,
                'citation_found' => false,
                'confidence' => 0.0,
                'references' => [],
                'raw' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    protected function extractTextFromRaw(array $raw, string $provider): string
    {

        if (isset($raw['choices'][0]['message']['content'])) {
            return $raw['choices'][0]['message']['content'];
        }

        if (isset($raw['candidates'][0]['content']['parts'][0]['text'])) {
            return $raw['candidates'][0]['content']['parts'][0]['text'];
        }

        return json_encode($raw);
    }

    protected function truncateRaw(mixed $raw): ?string
    {
        try {
            $serialized = is_string($raw) ? $raw : json_encode($raw, JSON_PRETTY_PRINT);
            return \Illuminate\Support\Str::limit($serialized, 10000, '... [truncated]');
        } catch (\Throwable $e) {
            return null;
        }
    }
}
