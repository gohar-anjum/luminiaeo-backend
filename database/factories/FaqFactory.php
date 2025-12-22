<?php

namespace Database\Factories;

use App\Models\Faq;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class FaqFactory extends Factory
{
    protected $model = Faq::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'topic' => fake()->sentence(),
            'faqs' => [
                [
                    'question' => fake()->sentence() . '?',
                    'answer' => fake()->paragraph(),
                ],
            ],
        ];
    }
}

