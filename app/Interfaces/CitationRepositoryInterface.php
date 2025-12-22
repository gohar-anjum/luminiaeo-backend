<?php

namespace App\Interfaces;

use App\Models\CitationTask;

interface CitationRepositoryInterface
{
    public function create(array $attributes): CitationTask;

    public function find(int $id): ?CitationTask;

    public function update(CitationTask $task, array $attributes): CitationTask;

    public function appendResults(CitationTask $task, array $payload): CitationTask;

    public function updateCompetitorsAndMeta(CitationTask $task, array $competitors, array $meta): CitationTask;

    public function findCompletedByUrl(string $url, ?int $cacheDays = null): ?CitationTask;
}
