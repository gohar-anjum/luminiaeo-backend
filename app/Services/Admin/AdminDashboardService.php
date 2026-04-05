<?php

namespace App\Services\Admin;

use App\Domain\Billing\Models\CreditTransaction;
use App\Models\ApiRequestLog;
use App\Models\Backlink;
use App\Models\CitationTask;
use App\Models\FaqTask;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Cashier\Subscription;

/**
 * Computes dashboard aggregates; {@see RefreshAdminDashboardCacheJob} writes to Redis.
 */
class AdminDashboardService
{
    public const CACHE_TTL_SECONDS = 3600;

    /**
     * @return array<string, int|float>
     */
    public function computeStats(): array
    {
        $today = now()->startOfDay();

        $totalUsers = User::query()->count();
        $newUsersToday = User::query()->where('created_at', '>=', $today)->count();

        $totalBacklinks = Backlink::query()->count();
        $newBacklinksToday = Backlink::query()->where('created_at', '>=', $today)->count();

        $apiCallsToday = ApiRequestLog::query()->where('created_at', '>=', $today)->count();
        $cacheHitsToday = ApiRequestLog::query()
            ->where('created_at', '>=', $today)
            ->where('was_cache_hit', true)
            ->count();
        $apiCacheHitRate = $apiCallsToday > 0
            ? round($cacheHitsToday / $apiCallsToday, 2)
            : 0.0;

        $totalCreditsSold = (int) CreditTransaction::query()
            ->where('type', CreditTransaction::TYPE_PURCHASE)
            ->where('amount', '>', 0)
            ->sum('amount');

        $creditsUsedToday = (int) abs((int) CreditTransaction::query()
            ->where('type', CreditTransaction::TYPE_USAGE)
            ->where('created_at', '>=', $today)
            ->where('status', '!=', CreditTransaction::STATUS_REVERSED)
            ->sum('amount'));

        $activeSubscriptions = Schema::hasTable('subscriptions')
            ? Subscription::query()
                ->where('stripe_status', 'active')
                ->count()
            : 0;

        $productActivity = app(AdminProductActivityService::class)->aggregateCounts();

        return [
            'total_users' => $totalUsers,
            'new_users_today' => $newUsersToday,
            'total_backlinks' => $totalBacklinks,
            'new_backlinks_today' => $newBacklinksToday,
            'api_calls_today' => $apiCallsToday,
            'api_cache_hit_rate' => $apiCacheHitRate,
            'total_credits_sold' => $totalCreditsSold,
            'credits_used_today' => $creditsUsedToday,
            'active_subscriptions' => $activeSubscriptions,
            'product_activity' => $productActivity,
            'upstream_api_cache' => [
                'calls_today' => $apiCallsToday,
                'cache_hit_rate' => $apiCacheHitRate,
                'description' => 'Rows in api_request_logs: one per internal upstream/cache resolution (not one per user feature action). See GET /api/admin/activity/catalog.',
            ],
        ];
    }

    /**
     * Daily buckets for the last 30 days (date => count).
     *
     * @return array<string, array<string, int>>
     */
    public function computeCharts(): array
    {
        $start = now()->subDays(29)->startOfDay();

        $charts = [
            'users_by_date' => $this->countByDay(User::query(), 'created_at', $start),
            'backlinks_by_date' => $this->countByDay(Backlink::query(), 'created_at', $start),
            'api_calls_by_date' => $this->countByDay(ApiRequestLog::query(), 'created_at', $start),
            'credits_used_by_date' => $this->sumAbsCreditsUsedByDay($start),
        ];

        if (Schema::hasTable('faq_tasks')) {
            $charts['faq_tasks_by_date'] = $this->countByDay(FaqTask::query(), 'created_at', $start);
        }
        if (Schema::hasTable('citation_tasks')) {
            $charts['citation_tasks_by_date'] = $this->countByDay(CitationTask::query(), 'created_at', $start);
        }

        return $charts;
    }

    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @return array<string, int>
     */
    protected function countByDay(Builder $query, string $column, Carbon $start): array
    {
        $out = [];
        for ($i = 0; $i < 30; $i++) {
            $day = $start->copy()->addDays($i);
            $d = $day->toDateString();
            $out[$d] = (clone $query)->whereDate($column, $d)->count();
        }

        return $out;
    }

    /**
     * @return array<string, int>
     */
    protected function sumAbsCreditsUsedByDay(Carbon $start): array
    {
        $out = [];
        for ($i = 0; $i < 30; $i++) {
            $day = $start->copy()->addDays($i);
            $d = $day->toDateString();
            $sum = CreditTransaction::query()
                ->where('type', CreditTransaction::TYPE_USAGE)
                ->where('status', '!=', CreditTransaction::STATUS_REVERSED)
                ->whereDate('created_at', $d)
                ->sum(DB::raw('ABS(amount)'));
            $out[$d] = (int) $sum;
        }

        return $out;
    }

    /**
     * @return array<string, int|float>
     */
    public function getCachedStats(): array
    {
        $key = config('admin.stats_cache_key');
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $computed = $this->computeStats();
        Cache::put($key, $computed, self::CACHE_TTL_SECONDS);

        return $computed;
    }

    /**
     * @return array<string, array<string, int>>
     */
    public function getCachedCharts(): array
    {
        $key = config('admin.charts_cache_key');
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $computed = $this->computeCharts();
        Cache::put($key, $computed, self::CACHE_TTL_SECONDS);

        return $computed;
    }

    public function refreshCaches(): void
    {
        $statsKey = config('admin.stats_cache_key');
        $chartsKey = config('admin.charts_cache_key');
        Cache::put($statsKey, $this->computeStats(), self::CACHE_TTL_SECONDS);
        Cache::put($chartsKey, $this->computeCharts(), self::CACHE_TTL_SECONDS);
    }
}
