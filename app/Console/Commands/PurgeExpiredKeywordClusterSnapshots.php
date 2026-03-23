<?php

namespace App\Console\Commands;

use App\Models\KeywordClusterSnapshot;
use Illuminate\Console\Command;

class PurgeExpiredKeywordClusterSnapshots extends Command
{
    protected $signature = 'keyword-cluster:purge-snapshots';

    protected $description = 'Delete expired keyword cluster snapshot rows';

    public function handle(): int
    {
        $deleted = KeywordClusterSnapshot::query()
            ->where('expires_at', '<=', now())
            ->delete();

        $this->info("Deleted {$deleted} expired keyword cluster snapshot(s).");

        return self::SUCCESS;
    }
}
