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
                    // Bare array of strings: do not assume citation_found (may be unverified URLs)
                    $parsedResult = [
                        'citation_found' => false,
                        'confidence' => 0.0,
                        'citation_references' => [],
                        'explanation' => 'List of URLs returned without citation_found; not treated as verified citations',
                    ];
                }
            }
        }

        // Fallback parsing if JSON parsing didn't work: do not treat prose URLs as citations (anti-hallucination)
        if ($parsedResult === null) {
            $lower = strtolower($text);
            $found = (bool) (str_contains($lower, '"citation_found": true') || str_contains($lower, '"citation_found":true'));
            // Do not set citation_found from loose keywords or from URLs extracted from prose
            if (!$found) {
                $found = (bool) (preg_match('/\b(yes|true)\b.*(?:citation|cited|reference)/i', $text) || preg_match('/(?:citation|cited|reference).*(?:yes|true)\b/i', $text));
            }

            $parsedResult = [
                'citation_found' => $found,
                'confidence' => $found ? 0.5 : 0.0,
                'citation_references' => [], // Never use regex-extracted URLs as citations in fallback
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
