<?php

namespace Database\Factories;

use App\Models\SeoTask;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class SeoTaskFactory extends Factory
{
    protected $model = SeoTask::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'task_id' => fake()->uuid(),
            'type' => 'backlinks',
            'status' => 'pending',
            'target' => fake()->domainName(),
            'results' => null,
            'error_message' => null,
            'started_at' => null,
            'completed_at' => null,
        ];
    }
}

