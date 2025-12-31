<?php

namespace Database\Factories;

use App\Models\Keyword;
use App\Models\KeywordResearchJob;
use Illuminate\Database\Eloquent\Factories\Factory;

class KeywordFactory extends Factory
{
    protected $model = Keyword::class;

    public function definition(): array
    {
        return [
            'keyword_research_job_id' => KeywordResearchJob::factory(),
            'keyword' => fake()->words(2, true),
            'source' => 'test',
            'search_volume' => fake()->numberBetween(100, 10000),
            'competition' => fake()->randomFloat(2, 0, 1),
            'cpc' => fake()->randomFloat(2, 0.5, 5.0),
            'ai_visibility_score' => fake()->randomFloat(2, 0, 100),
            'intent' => null,
            'intent_category' => null,
            'intent_metadata' => null,
            'long_tail_versions' => null,
            'semantic_data' => null,
            'location' => null,
            'language_code' => 'en',
            'geoTargetId' => 2840,
        ];
    }
}

