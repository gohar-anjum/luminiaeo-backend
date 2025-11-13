<?php

namespace App\Jobs;

use App\Interfaces\DataForSEO\BacklinksRepositoryInterface;
use App\Models\Backlink;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FetchBacklinksResultsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected string $taskId;
    protected string $domain;

    public function __construct(string $taskId, string $domain)
    {
        $this->taskId = $taskId;
        $this->domain = $domain;
    }

    public function handle(BacklinksRepositoryInterface $repo): void
    {
        Log::info("Fetching backlinks for {$this->domain}, task: {$this->taskId}");

        $results = $repo->fetchResults($this->taskId);

        if (isset($results['error'])) {
            Log::error("Backlink fetch failed: " . $results['message']);
            $this->release(60); // Retry after 1 minute
            return;
        }

        foreach ($results as $item) {
            Backlink::updateOrCreate(
                [
                    'domain'      => $this->domain,
                    'source_url'  => $item['source_url'] ?? null,
                    'task_id'     => $this->taskId,
                ],
                [
                    'anchor'         => $item['anchor'] ?? null,
                    'link_type'      => $item['link_type'] ?? null,
                    'source_domain'  => $item['source_domain'] ?? null,
                    'domain_rank'    => $item['domain_rank'] ?? null,
                ]
            );
        }

        Log::info("Stored " . count($results) . " backlinks for {$this->domain}");
    }
}
