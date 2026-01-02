<?php

namespace App\Services\LLM\Transformers;

use App\Services\LLM\Support\JsonExtractor;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CitationParser
{
    public function parse(string $text): array
    {
        $json = JsonExtractor::extract($text);
        $parsedResult = null;

        if ($json) {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {

                if (isset($decoded['citation_found']) || isset($decoded['citation_references'])) {
                    $parsedResult = [
                        'citation_found' => (bool) ($decoded['citation_found'] ?? false),
                        'confidence' => (float) ($decoded['confidence'] ?? ($decoded['score'] ?? 0.0)),
                        'citation_references' => array_values($decoded['citation_references'] ?? ($decoded['references'] ?? [])),
                        'explanation' => $decoded['explanation'] ?? '',
                    ];
                } elseif (array_values($decoded) === $decoded && count($decoded) > 0) {
                    $parsedResult = [
                        'citation_found' => true,
                        'confidence' => 0.8,
                        'citation_references' => array_map('strval', $decoded),
                        'explanation' => 'List of references returned',
                    ];
                }
            }
        }

        // Fallback parsing if JSON parsing didn't work
        if ($parsedResult === null) {
            $lower = strtolower($text);
            $found = (bool) (str_contains($lower, 'yes') || str_contains($lower, 'found') || str_contains($lower, 'cited') || str_contains($lower, 'references'));

            preg_match_all('/https?:\/\/[^\s,;]+/i', $text, $m);
            $refs = $m[0] ?? [];

            $parsedResult = [
                'citation_found' => $found || count($refs) > 0,
                'confidence' => $found ? 0.6 : (count($refs) > 0 ? 0.7 : 0.0),
                'citation_references' => array_values($refs),
                'explanation' => $found ? 'Detected citation language' : 'No structured citations found',
            ];
        }

        // Log parsing results with complete input
        $urlCount = count($parsedResult['citation_references'] ?? []);
        Log::info('Citation parser - Complete Input and Result', [
            'parsing_method' => $json ? 'json' : 'fallback',
            'input_text' => $text,
            'extracted_json' => $json ?: null,
            'citation_found' => $parsedResult['citation_found'] ?? false,
            'confidence' => $parsedResult['confidence'] ?? 0.0,
            'urls_extracted' => $urlCount,
            'urls' => $parsedResult['citation_references'] ?? [],
            'has_urls' => $urlCount > 0,
            'explanation' => $parsedResult['explanation'] ?? '',
            'parsed_result' => $parsedResult,
        ]);

        return $parsedResult;
    }
}
