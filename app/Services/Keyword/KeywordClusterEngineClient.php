<?php

namespace App\Services\Keyword;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class KeywordClusterEngineClient
{
    public function __construct(
        protected ?string $baseUrl = null,
        protected int $timeout = 120
    ) {
        $this->baseUrl = $baseUrl ?? (string) config('services.keyword_clustering.url', '');
        $this->timeout = (int) config('services.keyword_clustering.timeout', 120);
    }

    public static function fromConfig(): self
    {
        return new self;
    }

    /**
     * @return array{schema_version?: int, seed?: string, tree?: array, meta?: array}
     */
    public function fetchKeywordClusterTree(
        string $seed,
        string $languageCode,
        int $locationCode,
        string $gl,
        int $schemaVersion = 1
    ): array {
        if ($this->baseUrl === '') {
            throw new RuntimeException('Keyword clustering service URL is not configured.');
        }

        $url = rtrim($this->baseUrl, '/').'/keyword-cluster';

        $response = Http::timeout($this->timeout)
            ->acceptJson()
            ->post($url, [
                'seed' => $seed,
                'language_code' => $languageCode,
                'location_code' => $locationCode,
                'gl' => strtolower($gl),
                'schema_version' => $schemaVersion,
            ]);

        if (! $response->successful()) {
            Log::error('Keyword cluster engine HTTP error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            $response->throw();
        }

        return $response->json();
    }
}
