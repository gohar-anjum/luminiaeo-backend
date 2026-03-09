<?php

namespace App\Services\ApiCache;

class QueryHasher
{
    /**
     * Produce a deterministic SHA-256 hash for a given API query.
     *
     * The hash is built from the provider name, feature name, and a recursively
     * sorted, JSON-encoded representation of the query parameters. This ensures
     * that two structurally identical requests always produce the same hash
     * regardless of the original key ordering.
     */
    public function hash(string $apiProvider, string $feature, array $parameters): string
    {
        $normalized = $this->normalize($parameters);

        $canonical = mb_strtolower($apiProvider)
            .':'
            .mb_strtolower($feature)
            .':'
            .json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return hash('sha256', $canonical);
    }

    /**
     * Recursively sort array keys and lowercase string values so that
     * semantically identical parameters always serialize identically.
     */
    private function normalize(array $parameters): array
    {
        $filtered = $this->stripVolatileKeys($parameters);

        ksort($filtered);

        foreach ($filtered as $key => $value) {
            if (is_array($value)) {
                $filtered[$key] = $this->normalize($value);
            } elseif (is_string($value)) {
                $filtered[$key] = mb_strtolower(trim($value));
            }
        }

        return $filtered;
    }

    /**
     * Remove keys that should not participate in deduplication (timestamps,
     * request IDs, nonces, etc.).
     */
    private function stripVolatileKeys(array $parameters): array
    {
        $volatile = [
            'timestamp',
            'request_id',
            'nonce',
            'idempotency_key',
            'trace_id',
            'session_id',
        ];

        return array_diff_key($parameters, array_flip($volatile));
    }
}
