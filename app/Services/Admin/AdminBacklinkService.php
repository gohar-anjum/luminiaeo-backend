<?php

namespace App\Services\Admin;

use App\Models\Backlink;
use App\Services\SafeBrowsing\SafeBrowsingService;
use App\Support\Iso8601;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Admin backlink listing, removal, and re-verification (Safe Browsing).
 */
class AdminBacklinkService
{
    public function __construct(
        protected SafeBrowsingService $safeBrowsing
    ) {}

    /**
     * @param  array{status?: string|null, domain?: string|null}  $filters
     * @return LengthAwarePaginator<int, Backlink>
     */
    public function paginate(int $perPage = 50, array $filters = []): LengthAwarePaginator
    {
        $q = Backlink::query()
            ->with(['seoTask.user'])
            ->orderByDesc('id');

        if (! empty($filters['status'])) {
            $q->where('verification_status', $filters['status']);
        }

        if (! empty($filters['domain'])) {
            $d = $filters['domain'];
            $q->where(function ($w) use ($d) {
                $w->where('domain', 'like', '%'.$d.'%')
                    ->orWhere('source_domain', 'like', '%'.$d.'%');
            });
        }

        return $q->paginate($perPage);
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeBacklink(Backlink $b): array
    {
        $user = $b->seoTask?->user;

        return [
            'id' => $b->id,
            'target_url' => 'https://'.$b->domain,
            'source_url' => $b->source_url,
            'status' => $b->verification_status,
            'verified_at' => Iso8601::utcZ($b->verified_at),
            'user' => $user ? [
                'id' => $user->id,
                'name' => $user->name,
            ] : null,
        ];
    }

    public function delete(Backlink $backlink): void
    {
        $backlink->delete();
    }

    public function verify(Backlink $backlink): Backlink
    {
        if (! $this->safeBrowsing->enabled()) {
            $backlink->forceFill([
                'verification_status' => 'pending',
                'verified_at' => null,
            ])->save();

            return $backlink->fresh();
        }

        $url = $backlink->source_url;
        if (! str_starts_with($url, 'http')) {
            $url = 'https://'.$url;
        }

        $raw = $this->safeBrowsing->checkUrl($url);
        $signals = $this->safeBrowsing->extractSignals($raw);
        $status = $signals['status'] ?? 'unknown';
        $now = now();

        if ($status === 'clean') {
            $backlink->forceFill([
                'verification_status' => 'verified',
                'verified_at' => $now,
                'safe_browsing_status' => 'clean',
                'safe_browsing_threats' => [],
                'safe_browsing_checked_at' => $now,
            ])->save();
        } elseif ($status === 'flagged') {
            $backlink->forceFill([
                'verification_status' => 'failed',
                'verified_at' => $now,
                'safe_browsing_status' => 'flagged',
                'safe_browsing_threats' => $signals['threats'] ?? [],
                'safe_browsing_checked_at' => $now,
            ])->save();
        } else {
            $backlink->forceFill([
                'verification_status' => 'pending',
                'verified_at' => null,
            ])->save();
        }

        return $backlink->fresh();
    }
}
