<?php

namespace Database\Factories;

use App\Models\KeywordCluster;
use App\Models\KeywordResearchJob;
use Illuminate\Database\Eloquent\Factories\Factory;

class KeywordClusterFactory extends Factory
{
    protected $model = KeywordCluster::class;

    public function definition(): array
    {
        return [
            'keyword_research_job_id' => KeywordResearchJob::factory(),
            'topic_name' => fake()->words(2, true),
            'description' => fake()->sentence(),
            'suggested_article_titles' => [
                fake()->sentence(),
                fake()->sentence(),
            ],
            'recommended_faq_questions' => [
                fake()->sentence() . '?',
            ],
            'schema_suggestions' => [],
            'ai_visibility_projection' => fake()->randomFloat(2, 0, 100),
            'keyword_count' => fake()->numberBetween(5, 20),
        ];
    }
}

