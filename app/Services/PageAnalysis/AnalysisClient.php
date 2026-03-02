<?php

namespace App\Services\PageAnalysis;

use Illuminate\Support\Facades\Http;

class AnalysisClient
{
    public function __construct(
        protected string $baseUrl,
        protected int $timeout = 15
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public static function fromConfig(): self
    {
        $config = config('services.page_analysis', []);
        return new self(
            $config['url'] ?? 'http://localhost:8004',
            $config['timeout'] ?? 15
        );
    }

    /**
     * Call the page analysis microservice POST /analyze endpoint.
     *
     * @param  array{url: string, analysis: string[], compare_to?: string, compare_url?: string}  $payload
     * @return array{url: string, meta: array, content: array, analysis: array, cached?: bool}
     */
    public function analyze(array $payload): array
    {
        $response = Http::timeout($this->timeout)
            ->acceptJson()
            ->post($this->baseUrl . '/analyze', $payload)
            ->throw()
            ->json();

        return $response;
    }
}
