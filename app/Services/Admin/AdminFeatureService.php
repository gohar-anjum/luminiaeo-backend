<?php

namespace App\Services\Admin;

use App\Domain\Billing\Models\Feature;
use App\Support\Iso8601;
use Illuminate\Database\Eloquent\Collection;

/**
 * Admin CRU for billable features (credit costs). Deletes are not supported.
 */
class AdminFeatureService
{
    /**
     * @return Collection<int, Feature>
     */
    public function allOrdered(): Collection
    {
        return Feature::query()->orderBy('key')->get();
    }

    /**
     * @param  array{key: string, name: string, credit_cost: int, is_active: bool}  $data
     */
    public function create(array $data): Feature
    {
        return Feature::query()->create([
            'key' => $data['key'],
            'name' => $data['name'],
            'credit_cost' => $data['credit_cost'],
            'is_active' => $data['is_active'],
        ]);
    }

    /**
     * @param  array{name?: string, credit_cost?: int, is_active?: bool}  $data
     */
    public function update(Feature $feature, array $data): Feature
    {
        if (array_key_exists('name', $data)) {
            $feature->name = $data['name'];
        }
        if (array_key_exists('credit_cost', $data)) {
            $feature->credit_cost = (int) $data['credit_cost'];
        }
        if (array_key_exists('is_active', $data)) {
            $feature->is_active = (bool) $data['is_active'];
        }
        $feature->save();

        return $feature->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    public function serialize(Feature $feature): array
    {
        return [
            'id' => $feature->id,
            'key' => $feature->key,
            'name' => $feature->name,
            'credit_cost' => (int) $feature->credit_cost,
            'is_active' => (bool) $feature->is_active,
            'created_at' => Iso8601::utcZ($feature->created_at),
            'updated_at' => Iso8601::utcZ($feature->updated_at),
        ];
    }
}
