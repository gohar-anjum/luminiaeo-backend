<?php

namespace App\Services\LLM\Transformers;

use App\Services\LLM\Support\JsonExtractor;

class CitationParser
{
    public function parse(string $text): array
    {
        $json = JsonExtractor::extract($text);

        if ($json) {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {

                if (isset($decoded['citation_found']) || isset($decoded['citation_references'])) {
                    return [
                        'citation_found' => (bool) ($decoded['citation_found'] ?? false),
                        'confidence' => (float) ($decoded['confidence'] ?? ($decoded['score'] ?? 0.0)),
                        'citation_references' => array_values($decoded['citation_references'] ?? ($decoded['references'] ?? [])),
                        'explanation' => $decoded['explanation'] ?? '',
                    ];
                }

                if (array_values($decoded) === $decoded && count($decoded) > 0) {
                    return [
                        'citation_found' => true,
                        'confidence' => 0.8,
                        'citation_references' => array_map('strval', $decoded),
                        'explanation' => 'List of references returned',
                    ];
                }
            }
        }

        $lower = strtolower($text);
        $found = (bool) (str_contains($lower, 'yes') || str_contains($lower, 'found') || str_contains($lower, 'cited') || str_contains($lower, 'references'));

        preg_match_all('/https?:\/\/[^\s,;]+/i', $text, $m);
        $refs = $m[0] ?? [];

        return [
            'citation_found' => $found || count($refs) > 0,
            'confidence' => $found ? 0.6 : (count($refs) > 0 ? 0.7 : 0.0),
            'citation_references' => array_values($refs),
            'explanation' => $found ? 'Detected citation language' : 'No structured citations found',
        ];
    }
}
