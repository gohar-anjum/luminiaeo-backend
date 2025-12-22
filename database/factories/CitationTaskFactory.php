<?php

namespace Database\Factories;

use App\Models\CitationTask;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class CitationTaskFactory extends Factory
{
    protected $model = CitationTask::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'url' => fake()->url(),
            'status' => 'pending',
            'results' => null,
            'error_message' => null,
            'started_at' => null,
            'completed_at' => null,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'results' => ['queries' => ['query1', 'query2']],
            'completed_at' => now(),
        ]);
    }
}

