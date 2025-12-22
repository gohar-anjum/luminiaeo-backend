<?php

namespace Database\Factories;

use App\Models\KeywordCache;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

class KeywordCacheFactory extends Factory
{
    protected $model = KeywordCache::class;

    public function definition(): array
    {
        return [
            'keyword' => fake()->words(2, true),
            'language_code' => 'en',
            'location_code' => 2840,
            'source' => 'test',
            'search_volume' => fake()->numberBetween(100, 10000),
            'competition' => fake()->randomFloat(2, 0, 1),
            'cpc' => fake()->randomFloat(2, 0.5, 5.0),
            'expires_at' => Carbon::now()->addDays(7),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => Carbon::now()->subDay(),
        ]);
    }
}

