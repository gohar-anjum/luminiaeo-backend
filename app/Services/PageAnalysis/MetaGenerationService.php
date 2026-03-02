<?php

namespace App\Services\PageAnalysis;

use Illuminate\Support\Str;

class MetaGenerationService
{
    /**
     * Generate optimized title and description from analysis.
     * Rules: Title 50-60 chars, primary keyword front-loaded.
     * Commercial intent → CTA. Informational → "Guide", "Learn".
     *
     * @param  array{meta: array, content: array, analysis: array}  $analysis
     * @return array{title: string, description: string}
     */
    public function generate(array $analysis): array
    {
        $keywords = $analysis['analysis']['keywords'] ?? [];
        $intent = $analysis['analysis']['intent'] ?? 'informational';
        $primaryKeyword = $this->getPrimaryKeyword($keywords);
        $existingTitle = $analysis['meta']['title'] ?? '';
        $existingDescription = $analysis['meta']['description'] ?? '';

        $title = $this->optimizeTitle($primaryKeyword, $intent, $existingTitle);
        $description = $this->optimizeDescription(
            $primaryKeyword,
            $intent,
            $keywords,
            $existingDescription
        );

        return [
            'title' => Str::limit($title, 60, ''),
            'description' => Str::limit($description, 160, ''),
            'primary_keyword' => $primaryKeyword,
            'intent' => $intent,
        ];
    }

    protected function getPrimaryKeyword(array $keywords): string
    {
        if (empty($keywords)) {
            return 'Page';
        }
        $first = $keywords[0];
        return is_array($first) ? ($first['phrase'] ?? 'Page') : (string) $first;
    }

    protected function optimizeTitle(string $keyword, string $intent, string $existingTitle): string
    {
        if ($intent === 'commercial') {
            return "{$keyword} – Best Solutions & Pricing";
        }
        if ($intent === 'comparative') {
            return "{$keyword} – Comparison & Review";
        }
        return "{$keyword} – Complete Guide";
    }

    protected function optimizeDescription(
        string $keyword,
        string $intent,
        array $keywords,
        string $existingDescription
    ): string {
        $phrases = array_slice(
            array_map(fn ($k) => is_array($k) ? $k['phrase'] : $k, $keywords),
            0,
            3
        );
        $keywordPhrase = implode(', ', $phrases ?: [$keyword]);

        if ($intent === 'commercial') {
            return "Discover the best {$keyword}. Compare prices, features & get the best deal. {$keywordPhrase}.";
        }
        if ($intent === 'comparative') {
            return "In-depth comparison of {$keyword}. See side-by-side analysis. {$keywordPhrase}.";
        }
        return "Learn everything about {$keyword}. Complete guide with expert tips. {$keywordPhrase}.";
    }
}
