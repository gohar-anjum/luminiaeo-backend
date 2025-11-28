<?php

namespace App\Services\Keyword;

use App\DTOs\KeywordDataDTO;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class KeywordScraperService
{
    protected const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
    protected const TIMEOUT = 30;

    /**
     * Scrape Google Autocomplete suggestions
     *
     * @param string $seedKeyword
     * @param string $languageCode
     * @return array<KeywordDataDTO>
     */
    public function scrapeAutocomplete(string $seedKeyword, string $languageCode = 'en'): array
    {
        try {
            $url = 'https://www.google.com/complete/search';
            $params = [
                'client' => 'chrome',
                'q' => $seedKeyword,
                'hl' => $languageCode,
            ];

            $response = Http::withHeaders([
                'User-Agent' => self::USER_AGENT,
            ])
                ->timeout(self::TIMEOUT)
                ->get($url, $params);

            if (!$response->successful()) {
                Log::warning('Google Autocomplete API failed', [
                    'keyword' => $seedKeyword,
                    'status' => $response->status(),
                ]);
                return [];
            }

            $data = $response->json();
            if (!is_array($data) || !isset($data[1]) || !is_array($data[1])) {
                return [];
            }

            $keywords = [];
            foreach ($data[1] as $suggestion) {
                if (is_string($suggestion)) {
                    $keywords[] = new KeywordDataDTO(
                        keyword: $suggestion,
                        source: 'scraper_autocomplete',
                    );
                }
            }

            Log::info('Google Autocomplete scraped', [
                'seed_keyword' => $seedKeyword,
                'suggestions_found' => count($keywords),
            ]);

            return $keywords;
        } catch (\Throwable $e) {
            Log::error('Google Autocomplete scraping error', [
                'keyword' => $seedKeyword,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Scrape People Also Ask (PAA) questions
     *
     * @param string $seedKeyword
     * @return array<KeywordDataDTO>
     */
    public function scrapePeopleAlsoAsk(string $seedKeyword): array
    {
        try {
            $url = 'https://www.google.com/search';
            $params = [
                'q' => $seedKeyword,
                'hl' => 'en',
            ];

            $response = Http::withHeaders([
                'User-Agent' => self::USER_AGENT,
            ])
                ->timeout(self::TIMEOUT)
                ->get($url, $params);

            if (!$response->successful()) {
                return [];
            }

            $html = $response->body();
            
            preg_match_all('/<div[^>]*class="[^"]*related-question[^"]*"[^>]*>.*?<span[^>]*>(.*?)<\/span>/is', $html, $matches);
            
            $questions = [];
            if (!empty($matches[1])) {
                foreach ($matches[1] as $question) {
                    $question = strip_tags($question);
                    $question = html_entity_decode($question, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $question = trim($question);
                    
                    if (!empty($question) && strlen($question) > 10) {
                        $questions[] = new KeywordDataDTO(
                            keyword: $question,
                            source: 'scraper_paa',
                            questionVariations: [$question],
                        );
                    }
                }
            }

            if (empty($questions)) {
                $questions = $this->generateQuestionVariations($seedKeyword);
            }

            Log::info('People Also Ask scraped', [
                'seed_keyword' => $seedKeyword,
                'questions_found' => count($questions),
            ]);

            return $questions;
        } catch (\Throwable $e) {
            Log::error('People Also Ask scraping error', [
                'keyword' => $seedKeyword,
                'error' => $e->getMessage(),
            ]);
            
            return $this->generateQuestionVariations($seedKeyword);
        }
    }

    /**
     * Scrape Related Searches
     *
     * @param string $seedKeyword
     * @return array<KeywordDataDTO>
     */
    public function scrapeRelatedSearches(string $seedKeyword): array
    {
        try {
            $url = 'https://www.google.com/search';
            $params = [
                'q' => $seedKeyword,
                'hl' => 'en',
            ];

            $response = Http::withHeaders([
                'User-Agent' => self::USER_AGENT,
            ])
                ->timeout(self::TIMEOUT)
                ->get($url, $params);

            if (!$response->successful()) {
                return [];
            }

            $html = $response->body();
            
            // Extract related searches
            preg_match_all('/<a[^>]*class="[^"]*related-question[^"]*"[^>]*>.*?<span[^>]*>(.*?)<\/span>/is', $html, $matches);
            
            $keywords = [];
            if (!empty($matches[1])) {
                foreach ($matches[1] as $related) {
                    $related = strip_tags($related);
                    $related = html_entity_decode($related, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $related = trim($related);
                    
                    if (!empty($related)) {
                        $keywords[] = new KeywordDataDTO(
                            keyword: $related,
                            source: 'scraper_related',
                        );
                    }
                }
            }

            Log::info('Related Searches scraped', [
                'seed_keyword' => $seedKeyword,
                'related_found' => count($keywords),
            ]);

            return $keywords;
        } catch (\Throwable $e) {
            Log::error('Related Searches scraping error', [
                'keyword' => $seedKeyword,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Generate question variations as fallback
     *
     * @param string $keyword
     * @return array<KeywordDataDTO>
     */
    protected function generateQuestionVariations(string $keyword): array
    {
        $questionStarters = [
            'what is',
            'how to',
            'what are',
            'why is',
            'when is',
            'where is',
            'who is',
            'can you',
            'should I',
            'is it',
        ];

        $questions = [];
        foreach ($questionStarters as $starter) {
            $questions[] = new KeywordDataDTO(
                keyword: "$starter $keyword",
                source: 'scraper_paa_generated',
                questionVariations: ["$starter $keyword"],
            );
        }

        return $questions;
    }

    /**
     * Scrape all sources and combine results
     *
     * @param string $seedKeyword
     * @param string $languageCode
     * @return array<KeywordDataDTO>
     */
    public function scrapeAll(string $seedKeyword, string $languageCode = 'en'): array
    {
        $allKeywords = [];

        $autocomplete = $this->scrapeAutocomplete($seedKeyword, $languageCode);
        $allKeywords = array_merge($allKeywords, $autocomplete);

        $paa = $this->scrapePeopleAlsoAsk($seedKeyword);
        $allKeywords = array_merge($allKeywords, $paa);

        $related = $this->scrapeRelatedSearches($seedKeyword);
        $allKeywords = array_merge($allKeywords, $related);

        $unique = [];
        $seen = [];
        foreach ($allKeywords as $keyword) {
            $normalized = strtolower(trim($keyword->keyword));
            if (!isset($seen[$normalized])) {
                $seen[$normalized] = true;
                $unique[] = $keyword;
            }
        }

        return $unique;
    }
}

