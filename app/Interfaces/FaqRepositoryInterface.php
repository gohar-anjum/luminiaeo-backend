<?php

namespace App\Interfaces;

use App\Models\Faq;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface FaqRepositoryInterface
{
    public function all(): Collection;
    public function find(int $id): Faq;
    public function findByHash(string $hash): ?Faq;
    public function create(array $data): Faq;
    public function update(int $id, array $data): Faq;
    public function delete(int $id): bool;
    public function findByUrl(string $url): Collection;
    public function findByTopic(string $topic): Collection;
    public function findByUser(int $userId): Collection;
    public function incrementApiCallsSaved(int $id): bool;
    public function paginate(int $perPage = 15): LengthAwarePaginator;
    public function search(string $query): Collection;
    public function getMostReused(int $limit = 10): Collection;
    public function getTotalApiCallsSaved(): int;
    public function getStatistics(): array;
}
