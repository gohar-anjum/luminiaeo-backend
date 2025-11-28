<?php

namespace App\Services\Keyword;

use App\DTOs\KeywordDataDTO;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AnswerThePublicService
{
    protected ?string $apiKey;
    protected string $baseUrl = 'https://api.answerthepublic.com';

    public function __construct()
    {
        $this->apiKey = config('services.answerthepublic.api_key');
    }

    /**
     * Check if ATP service is available
     */
    public function isAvailable(): bool
    {
        return !empty($this->apiKey);
    }

    /**
     * Fetch keyword data from AnswerThePublic
     *
     * @param string $seedKeyword
     * @param string $languageCode
     * @return array<KeywordDataDTO>
     */
    public function getKeywordData(string $seedKeyword, string $languageCode = 'en'): array
    {
        if (!$this->isAvailable()) {
            Log::info('AnswerThePublic skipped - API key not configured');
            return [];
        }

        try {
            if ($this->hasApiAccess()) {
                return $this->fetchFromApi($seedKeyword, $languageCode);
            }

            return $this->scrapeData($seedKeyword, $languageCode);
        } catch (\Throwable $e) {
            Log::error('AnswerThePublic error', [
                'keyword' => $seedKeyword,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Fetch from official API
     */
    protected function fetchFromApi(string $seedKeyword, string $languageCode): array
    {
        $url = "{$this->baseUrl}/api/v1/search";
        
        $response = Http::withHeaders([
            'Authorization' => "Bearer {$this->apiKey}",
        ])
            ->timeout(60)
            ->get($url, [
                'query' => $seedKeyword,
                'language' => $languageCode,
            ]);

        if (!$response->successful()) {
            Log::warning('AnswerThePublic API failed', [
                'keyword' => $seedKeyword,
                'status' => $response->status(),
            ]);
            return [];
        }

        $data = $response->json();
        return $this->parseApiResponse($data, $seedKeyword);
    }

    /**
     * Scrape data from ATP website
     */
    protected function scrapeData(string $seedKeyword, string $languageCode): array
    {
        $url = "https://answerthepublic.com/reports/{$seedKeyword}";
        
        try {
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            ])
                ->timeout(60)
                ->get($url);

            if (!$response->successful()) {
                return [];
            }

            $html = $response->body();
            return $this->parseHtmlResponse($html, $seedKeyword);
        } catch (\Throwable $e) {
            Log::error('AnswerThePublic scraping error', [
                'keyword' => $seedKeyword,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Parse API response
     */
    protected function parseApiResponse(array $data, string $seedKeyword): array
    {
        $keywords = [];

        // Questions
        if (isset($data['questions']) && is_array($data['questions'])) {
            foreach ($data['questions'] as $question) {
                if (is_string($question)) {
                    $keywords[] = new KeywordDataDTO(
                        keyword: $question,
                        source: 'answerthepublic_questions',
                        questionVariations: [$question],
                    );
                }
            }
        }

        // Prepositions
        if (isset($data['prepositions']) && is_array($data['prepositions'])) {
            foreach ($data['prepositions'] as $preposition) {
                if (is_string($preposition)) {
                    $keywords[] = new KeywordDataDTO(
                        keyword: $preposition,
                        source: 'answerthepublic_prepositions',
                    );
                }
            }
        }

        // Comparisons
        if (isset($data['comparisons']) && is_array($data['comparisons'])) {
            foreach ($data['comparisons'] as $comparison) {
                if (is_string($comparison)) {
                    $keywords[] = new KeywordDataDTO(
                        keyword: $comparison,
                        source: 'answerthepublic_comparisons',
                    );
                }
            }
        }

        // Related
        if (isset($data['related']) && is_array($data['related'])) {
            foreach ($data['related'] as $related) {
                if (is_string($related)) {
                    $keywords[] = new KeywordDataDTO(
                        keyword: $related,
                        source: 'answerthepublic_related',
                    );
                }
            }
        }

        return $keywords;
    }

    /**
     * Parse HTML response (simplified)
     */
    protected function parseHtmlResponse(string $html, string $seedKeyword): array
    {
        $keywords = [];
        
        // Extract questions (simplified regex)
        preg_match_all('/<div[^>]*class="[^"]*question[^"]*"[^>]*>(.*?)<\/div>/is', $html, $questionMatches);
        if (!empty($questionMatches[1])) {
            foreach ($questionMatches[1] as $question) {
                $question = strip_tags($question);
                $question = html_entity_decode($question, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $question = trim($question);
                
                if (!empty($question)) {
                    $keywords[] = new KeywordDataDTO(
                        keyword: $question,
                        source: 'answerthepublic_questions',
                        questionVariations: [$question],
                    );
                }
            }
        }

        return $keywords;
    }

    /**
     * Check if API access is available
     */
    protected function hasApiAccess(): bool
    {
        return !empty($this->apiKey) && strlen($this->apiKey) > 20;
    }
}

