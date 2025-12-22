<?php

namespace Database\Factories;

use App\Models\KeywordResearchJob;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class KeywordResearchJobFactory extends Factory
{
    protected $model = KeywordResearchJob::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'query' => fake()->words(3, true),
            'status' => KeywordResearchJob::STATUS_PENDING,
            'language_code' => 'en',
            'geoTargetId' => 2840,
            'result' => null,
            'settings' => [],
            'progress' => [],
            'error_message' => null,
            'started_at' => null,
            'completed_at' => null,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => KeywordResearchJob::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);
    }

    public function processing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => KeywordResearchJob::STATUS_PROCESSING,
            'started_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => KeywordResearchJob::STATUS_FAILED,
            'error_message' => 'Processing failed',
        ]);
    }
}

