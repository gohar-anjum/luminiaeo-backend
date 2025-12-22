<?php

namespace App\Repositories;

use App\Interfaces\FaqRepositoryInterface;
use App\Models\Faq;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class FaqRepository implements FaqRepositoryInterface
{
    public function all(): Collection
    {
        return Faq::all();
    }

    public function find(int $id): Faq
    {
        return Faq::findOrFail($id);
    }

    public function findByHash(string $hash): ?Faq
    {
        return Faq::byHash($hash)->first();
    }

    public function create(array $data): Faq
    {
        return Faq::create($data);
    }

    public function update(int $id, array $data): Faq
    {
        $faq = Faq::findOrFail($id);
        $faq->update($data);
        return $faq;
    }

    public function delete(int $id): bool
    {
        $faq = Faq::findOrFail($id);
        $faq->delete();
        return true;
    }

    public function findByUrl(string $url): Collection
    {
        return Faq::forUrl($url)->get();
    }

    public function findByTopic(string $topic): Collection
    {
        return Faq::forTopic($topic)->get();
    }

    public function findByUser(int $userId): Collection
    {
        return Faq::forUser($userId)->get();
    }

    public function incrementApiCallsSaved(int $id): bool
    {
        $faq = Faq::findOrFail($id);
        $faq->incrementApiCallsSaved();
        return true;
    }

    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return Faq::orderBy('created_at', 'desc')->paginate($perPage);
    }

    public function search(string $query): Collection
    {
        return Faq::where(function ($q) use ($query) {
            $q->where('url', 'like', "%{$query}%")
              ->orWhere('topic', 'like', "%{$query}%");
        })->get();
    }

    public function getMostReused(int $limit = 10): Collection
    {
        return Faq::orderBy('api_calls_saved', 'desc')
            ->limit($limit)
            ->get();
    }

    public function getTotalApiCallsSaved(): int
    {
        return (int) Faq::sum('api_calls_saved');
    }

    public function getStatistics(): array
    {
        return [
            'total_faqs' => Faq::count(),
            'total_api_calls_saved' => $this->getTotalApiCallsSaved(),
            'total_users' => Faq::distinct('user_id')->count('user_id'),
            'most_reused_count' => Faq::max('api_calls_saved') ?? 0,
            'average_api_calls_saved' => Faq::avg('api_calls_saved') ?? 0,
            'by_source' => [
                'url_based' => Faq::whereNotNull('url')->count(),
                'topic_based' => Faq::whereNotNull('topic')->count(),
            ],
        ];
    }
}
