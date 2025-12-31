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
    public function findCitations(
        string $query, 
        string $targetUrl, 
        int $limit = null,
        int $locationCode = null,
        string $languageCode = null,
        string $device = 'desktop',
        string $os = 'windows'
    ): array
    {
        $targetDomain = $this->extractDomain($targetUrl);
        
        $limit = $limit ?? config('services.dataforseo.citation.default_depth', 10);
        $locationCode = $locationCode ?? config('services.citations.default_location_code', 2840);
        $languageCode = $languageCode ?? config('services.citations.default_language_code', 'en');
        
        $payload = [
            'data' => [
                [
                    'keyword' => $query,
                    'location_code' => $locationCode,
                    'language_code' => $languageCode,
                    'device' => $device,
                    'os' => $os,
                    'depth' => min($limit, config('services.dataforseo.citation.max_depth', 100)),
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
     * Batch find citations for multiple queries using parallel requests
     */
    public function batchFindCitations(array $queries, string $targetUrl, int $limitPerQuery = null): array
    {
        if (empty($queries)) {
            return [];
        }
        
        $limitPerQuery = $limitPerQuery ?? config('services.dataforseo.citation.default_depth', 10);

        $maxConcurrency = config('services.dataforseo.max_concurrent_requests', 5);
        $chunks = array_chunk($queries, $maxConcurrency, true);
        $results = [];

        foreach ($chunks as $chunk) {
            $promises = [];
            
            foreach ($chunk as $query) {
                $promises[$query] = Http::withBasicAuth($this->login, $this->password)
                    ->acceptJson()
                    ->baseUrl($this->baseUrl)
                    ->timeout(config('services.dataforseo.timeout', 60))
                    ->async()
                    ->post('/serp/google/organic/live/advanced', $this->buildPayload($query, $targetUrl, $limitPerQuery));
            }

            $responses = Http::pool($promises);

            foreach ($chunk as $query) {
                try {
                    $response = $responses[$query];
                    
                    if ($response->successful()) {
                        $result = $this->processCitationResponse($response->json(), $targetUrl, $limitPerQuery);
                        $results[$query] = $result;
                    } else {
                        throw new DataForSEOException(
                            'API request failed: ' . $response->status(),
                            $response->status(),
                            'API_REQUEST_FAILED'
                        );
                    }
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
        }

        return $results;
    }

    protected function buildPayload(string $query, string $targetUrl, int $limit): array
    {
        $locationCode = config('services.citations.default_location_code', 2840);
        $languageCode = config('services.citations.default_language_code', 'en');
        
        return [
            'data' => [
                [
                    'keyword' => $query,
                    'location_code' => $locationCode,
                    'language_code' => $languageCode,
                    'device' => 'desktop',
                    'os' => 'windows',
                    'depth' => min($limit, config('services.dataforseo.citation.max_depth', 100)),
                    'calculate_rectangles' => false,
                ]
            ]
        ];
    }

    protected function processCitationResponse(array $response, string $targetUrl, int $limit): array
    {
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

        $targetDomain = $this->extractDomain($targetUrl);
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

    /**
     * Get LLM Mentions for a target domain/URL
     * Uses DataForSEO's LLM Mentions API to find mentions in AI-generated content
     * 
     * @param string $targetUrl Target URL or domain to check
     * @param string $platform Platform to check: 'google' or 'chat_gpt'
     * @param int|null $locationCode Location code (default: 2840 for US)
     * @param string|null $languageCode Language code (default: 'en')
     * @param array $options Additional options (limit, filters, etc.)
     * @return array LLM Mentions data
     */
    public function getLLMMentions(
        string $targetUrl,
        string $platform = 'google',
        ?int $locationCode = null,
        ?string $languageCode = null,
        array $options = []
    ): array {
        $targetDomain = $this->extractDomain($targetUrl);
        $locationCode = $locationCode ?? config('services.citations.default_location_code', 2840);
        $languageCode = $languageCode ?? config('services.citations.default_language_code', 'en');
        
        // DataForSEO LLM Mentions API requires 'target' to be an array of objects
        // Each object must have exactly one of 'domain' or 'keyword'
        // Since we're working with URLs, we use 'domain'
        $payload = [
            [
                'platform' => $platform,
                'location_code' => $locationCode,
                'language_code' => $languageCode,
                'target' => [
                    [
                        'domain' => $targetDomain,
                    ]
                ],
            ]
        ];

        // Add filters if provided
        if (!empty($options['filters'])) {
            $payload[0]['filters'] = $options['filters'];
        }

        // Add order_by if provided
        if (!empty($options['order_by'])) {
            $payload[0]['order_by'] = $options['order_by'];
        }

        // Add limit if provided
        if (isset($options['limit'])) {
            $payload[0]['limit'] = min((int) $options['limit'], 1000);
        }

        try {
            // DataForSEO LLM Mentions API endpoint
            $endpoint = '/ai_optimization/llm_mentions/search/live';
            
            \Illuminate\Support\Facades\Log::info('DataForSEO LLM Mentions API Request', [
                'endpoint' => $endpoint,
                'full_url' => $this->baseUrl . $endpoint,
                'payload' => $payload,
                'target_domain' => $targetDomain,
            ]);

            $httpResponse = $this->client()->post($endpoint, $payload);
            
            // Log the raw response for debugging
            $responseBody = $httpResponse->body();
            \Illuminate\Support\Facades\Log::info('DataForSEO LLM Mentions API Raw Response', [
                'status_code' => $httpResponse->status(),
                'response_body' => $responseBody,
            ]);

            $response = $httpResponse->throw()->json();

            // Log the full parsed response
            \Illuminate\Support\Facades\Log::info('DataForSEO LLM Mentions API Response (Full)', [
                'endpoint_used' => $endpoint,
                'full_response' => $response,
            ]);

            \Illuminate\Support\Facades\Log::info('DataForSEO LLM Mentions API Response (Summary)', [
                'endpoint_used' => $endpoint,
                'status_code' => $response['tasks'][0]['status_code'] ?? null,
                'status_message' => $response['tasks'][0]['status_message'] ?? null,
                'has_result' => isset($response['tasks'][0]['result']),
                'result_keys' => isset($response['tasks'][0]['result']) ? array_keys($response['tasks'][0]['result']) : [],
                'result_count' => isset($response['tasks'][0]['result']) && is_array($response['tasks'][0]['result']) ? count($response['tasks'][0]['result']) : 0,
            ]);

            if (!isset($response['tasks']) || !is_array($response['tasks']) || empty($response['tasks'])) {
                throw new DataForSEOException('Invalid API response: missing tasks', 500, 'INVALID_RESPONSE');
            }

            $task = $response['tasks'][0];

            if (isset($task['status_code']) && $task['status_code'] !== 20000) {
                throw new DataForSEOException(
                    'DataForSEO LLM Mentions API error: ' . ($task['status_message'] ?? 'Unknown error'),
                    $task['status_code'] ?? 500,
                    'API_ERROR'
                );
            }

            $result = $task['result'] ?? [];

            // Log the actual result data structure
            if (!empty($result)) {
                \Illuminate\Support\Facades\Log::info('DataForSEO LLM Mentions API Result Data', [
                    'result_structure' => $result,
                    'result_type' => gettype($result),
                    'is_array' => is_array($result),
                    'array_count' => is_array($result) ? count($result) : 0,
                    'first_item' => is_array($result) && isset($result[0]) ? $result[0] : null,
                ]);
            } else {
                \Illuminate\Support\Facades\Log::warning('DataForSEO LLM Mentions API returned empty result', [
                    'task_id' => $task['id'] ?? null,
                    'task_data' => $task['data'] ?? null,
                ]);
            }

            return $result;
        } catch (RequestException $e) {
            $errorMessage = $e->getMessage();
            $responseBody = $e->response?->body() ?? 'No response body';
            
            \Illuminate\Support\Facades\Log::error('DataForSEO LLM Mentions API Request Exception', [
                'message' => $errorMessage,
                'response_body' => $responseBody,
                'status_code' => $e->response?->status(),
            ]);
            
            throw new DataForSEOException(
                'Failed to get LLM mentions: ' . $errorMessage . ' | Response: ' . $responseBody,
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
}

