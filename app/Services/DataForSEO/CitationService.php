<?php

namespace App\Services\DataForSEO;

use App\Exceptions\DataForSEOException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class CitationService
{
    protected string $baseUrl;
    protected string $login;
    protected string $password;

    public function __construct()
    {
        $this->baseUrl = config('services.dataforseo.base_url');
        $this->login = config('services.dataforseo.login');
        $this->password = config('services.dataforseo.password');

        if (empty($this->baseUrl) || empty($this->login) || empty($this->password)) {
            throw new DataForSEOException('DataForSEO configuration is incomplete', 500, 'CONFIG_ERROR');
        }
    }

    protected function client()
    {
        return Http::withBasicAuth($this->login, $this->password)
            ->acceptJson()
            ->baseUrl($this->baseUrl)
            ->timeout(config('services.dataforseo.timeout', 60))
            ->retry(3, 100);
    }

    /**
     * Find citations for a target URL based on a search query
     * Uses DataForSEO's SERP API to find pages that mention the target URL
     */
    public function findCitations(string $query, string $targetUrl, int $limit = 10): array
    {
        $targetDomain = $this->extractDomain($targetUrl);
        
        $payload = [
            'data' => [
                [
                    'keyword' => $query,
                    'location_code' => 2840,
                    'language_code' => 'en',
                    'device' => 'desktop',
                    'os' => 'windows',
                    'depth' => min($limit, 100),
                    'calculate_rectangles' => false,
                ]
            ]
        ];

        try {
            $response = $this->client()
                ->post('/serp/google/organic/live/advanced', $payload)
                ->throw()
                ->json();

            if (!isset($response['tasks']) || !is_array($response['tasks']) || empty($response['tasks'])) {
                throw new DataForSEOException('Invalid API response: missing tasks', 500, 'INVALID_RESPONSE');
            }

            $task = $response['tasks'][0];

            if (isset($task['status_code']) && $task['status_code'] !== 20000) {
                throw new DataForSEOException(
                    'DataForSEO API error: ' . ($task['status_message'] ?? 'Unknown error'),
                    $task['status_code'] ?? 500,
                    'API_ERROR'
                );
            }

            if (!isset($task['result']) || !is_array($task['result']) || empty($task['result'])) {
                return [
                    'citation_found' => false,
                    'confidence' => 0.0,
                    'references' => [],
                    'competitors' => [],
                ];
            }

            $resultData = $task['result'][0] ?? [];
            $items = $resultData['items'] ?? [];
            $citations = [];
            $competitors = [];

            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                
                $url = $item['url'] ?? '';
                if (empty($url)) {
                    continue;
                }
                
                $domain = $this->extractDomain($url);
                
                if ($this->isTargetDomain($domain, $targetDomain)) {
                    $citations[] = $url;
                } else {
                    $competitors[] = [
                        'domain' => $domain,
                        'url' => $url,
                        'title' => $item['title'] ?? $item['text'] ?? '',
                    ];
                }
            }

            $citationFound = count($citations) > 0;
            $confidence = $citationFound ? min(1.0, count($citations) / 10.0) : 0.0;

            return [
                'citation_found' => $citationFound,
                'confidence' => $confidence,
                'references' => array_slice($citations, 0, $limit),
                'competitors' => array_slice($competitors, 0, 5),
            ];
        } catch (RequestException $e) {
            throw new DataForSEOException(
                'Failed to find citations: ' . $e->getMessage(),
                500,
                'API_REQUEST_FAILED',
                $e
            );
        } catch (DataForSEOException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new DataForSEOException(
                'An unexpected error occurred: ' . $e->getMessage(),
                500,
                'UNEXPECTED_ERROR',
                $e
            );
        }
    }

    /**
     * Batch find citations for multiple queries
     */
    public function batchFindCitations(array $queries, string $targetUrl, int $limitPerQuery = 10): array
    {
        $results = [];
        
        foreach ($queries as $query) {
            try {
                $result = $this->findCitations($query, $targetUrl, $limitPerQuery);
                $results[$query] = $result;
            } catch (\Exception $e) {
                $results[$query] = [
                    'citation_found' => false,
                    'confidence' => 0.0,
                    'references' => [],
                    'competitors' => [],
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    protected function extractDomain(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST) ?: $url;
        $host = strtolower(trim($host));
        $host = preg_replace('/^www\./', '', $host);
        return $host;
    }

    protected function isTargetDomain(string $domain, string $targetDomain): bool
    {
        if (empty($domain) || empty($targetDomain)) {
            return false;
        }

        $domain = strtolower(trim($domain));
        $targetDomain = strtolower(trim($targetDomain));

        return $domain === $targetDomain || str_ends_with($domain, '.' . $targetDomain);
    }
}

