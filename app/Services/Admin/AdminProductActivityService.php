<?php

namespace App\Services\Admin;

use App\Models\CitationTask;
use App\Models\ClusterJob;
use App\Models\ContentOutline;
use App\Models\Faq;
use App\Models\FaqTask;
use App\Models\KeywordResearchJob;
use App\Models\MetaAnalysis;
use App\Models\PbnDetection;
use App\Models\SemanticAnalysis;
use App\Support\Iso8601;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator as ConcretePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * User-facing product activity for admins (FAQ, citations, page analysis, etc.).
 * This is separate from {@see ApiRequestLog} rows, which reflect upstream API cache hits/misses.
 */
class AdminProductActivityService
{
    /**
     * Row counts per product table for a single user (admin user detail).
     *
     * @return array<string, int>
     */
    public function countsForUser(int $userId): array
    {
        $uid = $userId;
        $out = [
            'faq_tasks' => Schema::hasTable('faq_tasks')
                ? (int) FaqTask::query()->where('user_id', $uid)->count()
                : 0,
            'faqs_stored' => Schema::hasTable('faqs')
                ? (int) Faq::query()->where('user_id', $uid)->count()
                : 0,
            'citation_tasks' => Schema::hasTable('citation_tasks')
                ? (int) CitationTask::query()->where('user_id', $uid)->count()
                : 0,
            'keyword_research_jobs' => Schema::hasTable('keyword_research_jobs')
                ? (int) KeywordResearchJob::query()->where('user_id', $uid)->count()
                : 0,
            'keyword_cluster_jobs' => Schema::hasTable('cluster_jobs')
                ? (int) ClusterJob::query()->where('user_id', $uid)->count()
                : 0,
            'meta_analyses' => Schema::hasTable('meta_analyses')
                ? (int) MetaAnalysis::query()->where('user_id', $uid)->count()
                : 0,
            'semantic_analyses' => Schema::hasTable('semantic_analyses')
                ? (int) SemanticAnalysis::query()->where('user_id', $uid)->count()
                : 0,
            'content_outlines' => Schema::hasTable('content_outlines')
                ? (int) ContentOutline::query()->where('user_id', $uid)->count()
                : 0,
        ];

        return $out;
    }

    /**
     * @return array{totals: array<string, int>, today: array<string, int>}
     */
    public function aggregateCounts(): array
    {
        $today = now()->startOfDay();
        $keys = [
            'faq_tasks' => 'faq_tasks',
            'faqs_stored' => 'faqs',
            'citation_tasks' => 'citation_tasks',
            'keyword_research_jobs' => 'keyword_research_jobs',
            'keyword_cluster_jobs' => 'cluster_jobs',
            'meta_analyses' => 'meta_analyses',
            'semantic_analyses' => 'semantic_analyses',
            'content_outlines' => 'content_outlines',
            'pbn_detections' => 'pbn_detections',
        ];

        $totals = [];
        $day = [];
        foreach ($keys as $outKey => $table) {
            if (! Schema::hasTable($table)) {
                $totals[$outKey] = 0;
                $day[$outKey] = 0;

                continue;
            }
            $totals[$outKey] = (int) DB::table($table)->count();
            $day[$outKey] = (int) DB::table($table)->where('created_at', '>=', $today)->count();
        }

        return ['totals' => $totals, 'today' => $day];
    }

    /**
     * Map of product features to admin list routes (for dashboards / API clients).
     *
     * @return array<string, mixed>
     */
    public function catalog(): array
    {
        $counts = $this->aggregateCounts();

        return [
            'product_features' => [
                [
                    'id' => 'faq_tasks',
                    'label' => 'FAQ generator',
                    'description' => 'Async FAQ tasks (SERP + AlsoAsked + answers).',
                    'list' => ['method' => 'GET', 'path' => '/api/admin/activity/faq-tasks'],
                    'counts' => [
                        'total' => $counts['totals']['faq_tasks'],
                        'created_today' => $counts['today']['faq_tasks'],
                    ],
                ],
                [
                    'id' => 'faqs_stored',
                    'label' => 'Stored FAQ documents',
                    'description' => 'Persisted FAQ JSON records (cache / completed tasks).',
                    'list' => ['method' => 'GET', 'path' => '/api/admin/activity/faqs'],
                    'counts' => [
                        'total' => $counts['totals']['faqs_stored'],
                        'created_today' => $counts['today']['faqs_stored'],
                    ],
                ],
                [
                    'id' => 'citation_tasks',
                    'label' => 'Citation analysis',
                    'description' => 'AI citation visibility tasks per URL.',
                    'list' => ['method' => 'GET', 'path' => '/api/admin/activity/citation-tasks'],
                    'counts' => [
                        'total' => $counts['totals']['citation_tasks'],
                        'created_today' => $counts['today']['citation_tasks'],
                    ],
                ],
                [
                    'id' => 'keyword_research_jobs',
                    'label' => 'Keyword research jobs',
                    'description' => 'Keyword research pipeline jobs.',
                    'list' => ['method' => 'GET', 'path' => '/api/admin/activity/keyword-research'],
                    'counts' => [
                        'total' => $counts['totals']['keyword_research_jobs'],
                        'created_today' => $counts['today']['keyword_research_jobs'],
                    ],
                ],
                [
                    'id' => 'keyword_cluster_jobs',
                    'label' => 'Keyword cluster jobs',
                    'description' => 'Standalone clustering jobs (keyword-clusters API).',
                    'list' => ['method' => 'GET', 'path' => '/api/admin/activity/cluster-jobs'],
                    'counts' => [
                        'total' => $counts['totals']['keyword_cluster_jobs'],
                        'created_today' => $counts['today']['keyword_cluster_jobs'],
                    ],
                ],
                [
                    'id' => 'meta_analyses',
                    'label' => 'Meta tag optimizer',
                    'description' => 'Meta title/description optimization runs.',
                    'list' => ['method' => 'GET', 'path' => '/api/admin/activity/meta-analyses'],
                    'counts' => [
                        'total' => $counts['totals']['meta_analyses'],
                        'created_today' => $counts['today']['meta_analyses'],
                    ],
                ],
                [
                    'id' => 'semantic_analyses',
                    'label' => 'Semantic score',
                    'description' => 'Semantic relevance analyses.',
                    'list' => ['method' => 'GET', 'path' => '/api/admin/activity/semantic-analyses'],
                    'counts' => [
                        'total' => $counts['totals']['semantic_analyses'],
                        'created_today' => $counts['today']['semantic_analyses'],
                    ],
                ],
                [
                    'id' => 'content_outlines',
                    'label' => 'Content outlines',
                    'description' => 'AI content outline generations.',
                    'list' => ['method' => 'GET', 'path' => '/api/admin/activity/content-outlines'],
                    'counts' => [
                        'total' => $counts['totals']['content_outlines'],
                        'created_today' => $counts['today']['content_outlines'],
                    ],
                ],
                [
                    'id' => 'pbn_detections',
                    'label' => 'PBN detection',
                    'description' => 'PBN / risk scans (domain-level; may not have user_id).',
                    'list' => ['method' => 'GET', 'path' => '/api/admin/activity/pbn-detections'],
                    'counts' => [
                        'total' => $counts['totals']['pbn_detections'],
                        'created_today' => $counts['today']['pbn_detections'],
                    ],
                ],
            ],
            'other_admin_lists' => [
                [
                    'id' => 'backlinks',
                    'label' => 'Backlinks',
                    'list' => ['method' => 'GET', 'path' => '/api/admin/backlinks'],
                ],
                [
                    'id' => 'clusters',
                    'label' => 'Keyword clusters (research output)',
                    'list' => ['method' => 'GET', 'path' => '/api/admin/clusters'],
                ],
                [
                    'id' => 'credit_transactions',
                    'label' => 'Credit ledger',
                    'list' => ['method' => 'GET', 'path' => '/api/admin/credit-transactions'],
                ],
            ],
            'upstream_integration_logs' => [
                'id' => 'api_request_logs',
                'label' => 'Upstream API cache log',
                'description' => 'Internal rows from the shared API cache layer (DataForSEO, SERP providers, etc.): one entry per cache resolve, including cache hits. This is not a 1:1 log of user-facing feature actions.',
                'list' => ['method' => 'GET', 'path' => '/api/admin/api-logs'],
            ],
        ];
    }

    /**
     * @param  array{user_id?: int|null, status?: string|null}  $filters
     * @return LengthAwarePaginator<int, FaqTask>
     */
    public function paginateFaqTasks(int $perPage, array $filters): LengthAwarePaginator
    {
        $q = FaqTask::query()->with('user:id,email,name')->orderByDesc('id');
        $this->applyUserFilter($q, $filters['user_id'] ?? null);
        if (! empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }

        return $q->paginate($perPage);
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeFaqTask(FaqTask $t): array
    {
        $user = $t->user;

        return [
            'id' => $t->id,
            'task_id' => $t->task_id,
            'user_id' => $t->user_id,
            'user_email' => $user?->email,
            'url' => $t->url,
            'topic' => $t->topic,
            'search_keyword' => $t->search_keyword,
            'status' => $t->status,
            'faq_id' => $t->faq_id,
            'serp_question_count' => is_array($t->serp_questions) ? count($t->serp_questions) : 0,
            'paa_question_count' => is_array($t->alsoasked_questions) ? count($t->alsoasked_questions) : 0,
            'error_preview' => $t->error_message ? mb_substr($t->error_message, 0, 200) : null,
            'created_at' => Iso8601::utcZ($t->created_at),
            'completed_at' => Iso8601::utcZ($t->completed_at),
        ];
    }

    /**
     * @param  array{user_id?: int|null, status?: string|null}  $filters
     * @return LengthAwarePaginator<int, CitationTask>
     */
    public function paginateCitationTasks(int $perPage, array $filters): LengthAwarePaginator
    {
        $q = CitationTask::query()->with('user:id,email,name')->orderByDesc('id');
        $this->applyUserFilter($q, $filters['user_id'] ?? null);
        if (! empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }

        return $q->paginate($perPage);
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeCitationTask(CitationTask $t): array
    {
        $queries = $t->queries ?? [];
        $user = $t->user;

        return [
            'id' => $t->id,
            'user_id' => $t->user_id,
            'user_email' => $user?->email,
            'url' => $t->url,
            'status' => $t->status,
            'queries_count' => is_array($queries) ? count($queries) : 0,
            'meta' => $t->meta,
            'created_at' => Iso8601::utcZ($t->created_at),
            'updated_at' => Iso8601::utcZ($t->updated_at),
        ];
    }

    /**
     * @param  array{user_id?: int|null, status?: string|null}  $filters
     * @return LengthAwarePaginator<int, KeywordResearchJob>
     */
    public function paginateKeywordResearch(int $perPage, array $filters): LengthAwarePaginator
    {
        $q = KeywordResearchJob::query()->with('user:id,email,name')->orderByDesc('id');
        $this->applyUserFilter($q, $filters['user_id'] ?? null);
        if (! empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }

        return $q->paginate($perPage);
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeKeywordResearchJob(KeywordResearchJob $j): array
    {
        $user = $j->user;

        return [
            'id' => $j->id,
            'user_id' => $j->user_id,
            'user_email' => $user?->email,
            'query' => $j->query,
            'status' => $j->status,
            'created_at' => Iso8601::utcZ($j->created_at),
            'updated_at' => Iso8601::utcZ($j->updated_at),
        ];
    }

    /**
     * @param  array{user_id?: int|null}  $filters
     * @return LengthAwarePaginator<int, MetaAnalysis>
     */
    public function paginateMetaAnalyses(int $perPage, array $filters): LengthAwarePaginator
    {
        $q = MetaAnalysis::query()->with('user:id,email,name')->orderByDesc('id');
        $this->applyUserFilter($q, $filters['user_id'] ?? null);

        return $q->paginate($perPage);
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeMetaAnalysis(MetaAnalysis $m): array
    {
        $user = $m->user;

        return [
            'id' => $m->id,
            'user_id' => $m->user_id,
            'user_email' => $user?->email,
            'url' => $m->url,
            'target_keyword' => $m->target_keyword,
            'suggested_title' => $m->suggested_title,
            'suggested_description' => $m->suggested_description ? mb_substr($m->suggested_description, 0, 240) : null,
            'intent' => $m->intent,
            'analyzed_at' => Iso8601::utcZ($m->analyzed_at),
            'created_at' => Iso8601::utcZ($m->created_at),
        ];
    }

    /**
     * @param  array{user_id?: int|null}  $filters
     * @return LengthAwarePaginator<int, SemanticAnalysis>
     */
    public function paginateSemanticAnalyses(int $perPage, array $filters): LengthAwarePaginator
    {
        if (! Schema::hasTable('semantic_analyses')) {
            return $this->emptyPaginator($perPage);
        }
        $q = SemanticAnalysis::query()->with('user:id,email,name')->orderByDesc('id');
        $this->applyUserFilter($q, $filters['user_id'] ?? null);

        return $q->paginate($perPage);
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeSemanticAnalysis(SemanticAnalysis $s): array
    {
        $user = $s->user;

        return [
            'id' => $s->id,
            'user_id' => $s->user_id,
            'user_email' => $user?->email,
            'source_url' => $s->source_url,
            'target_keyword' => $s->target_keyword,
            'semantic_score' => $s->semantic_score,
            'analyzed_at' => Iso8601::utcZ($s->analyzed_at),
            'created_at' => Iso8601::utcZ($s->created_at),
        ];
    }

    /**
     * @param  array{user_id?: int|null}  $filters
     * @return LengthAwarePaginator<int, ContentOutline>
     */
    public function paginateContentOutlines(int $perPage, array $filters): LengthAwarePaginator
    {
        if (! Schema::hasTable('content_outlines')) {
            return $this->emptyPaginator($perPage);
        }
        $q = ContentOutline::query()->with('user:id,email,name')->orderByDesc('id');
        $this->applyUserFilter($q, $filters['user_id'] ?? null);

        return $q->paginate($perPage);
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeContentOutline(ContentOutline $c): array
    {
        $user = $c->user;
        $outline = $c->outline ?? [];

        return [
            'id' => $c->id,
            'user_id' => $c->user_id,
            'user_email' => $user?->email,
            'keyword' => $c->keyword,
            'tone' => $c->tone,
            'intent' => $c->intent,
            'outline_sections_count' => is_array($outline) ? count($outline) : 0,
            'generated_at' => Iso8601::utcZ($c->generated_at),
            'created_at' => Iso8601::utcZ($c->created_at),
        ];
    }

    /**
     * @param  array{user_id?: int|null}  $filters
     * @return LengthAwarePaginator<int, Faq>
     */
    public function paginateFaqs(int $perPage, array $filters): LengthAwarePaginator
    {
        $q = Faq::query()->with('user:id,email,name')->orderByDesc('id');
        $this->applyUserFilter($q, $filters['user_id'] ?? null);

        return $q->paginate($perPage);
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeFaq(Faq $f): array
    {
        $user = $f->user;
        $items = $f->faqs ?? [];

        return [
            'id' => $f->id,
            'user_id' => $f->user_id,
            'user_email' => $user?->email,
            'url' => $f->url,
            'topic' => $f->topic,
            'faq_items_count' => is_array($items) ? count($items) : 0,
            'api_calls_saved' => $f->api_calls_saved,
            'created_at' => Iso8601::utcZ($f->created_at),
            'updated_at' => Iso8601::utcZ($f->updated_at),
        ];
    }

    /**
     * @param  array{status?: string|null, domain?: string|null}  $filters
     * @return LengthAwarePaginator<int, PbnDetection>
     */
    public function paginatePbnDetections(int $perPage, array $filters): LengthAwarePaginator
    {
        if (! Schema::hasTable('pbn_detections')) {
            return $this->emptyPaginator($perPage);
        }
        $q = PbnDetection::query()->orderByDesc('id');
        if (! empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }
        if (! empty($filters['domain'])) {
            $q->where('domain', 'like', '%'.$filters['domain'].'%');
        }

        return $q->paginate($perPage);
    }

    /**
     * @return array<string, mixed>
     */
    public function serializePbnDetection(PbnDetection $p): array
    {
        return [
            'id' => $p->id,
            'task_id' => $p->task_id,
            'domain' => $p->domain,
            'status' => $p->status,
            'high_risk_count' => $p->high_risk_count,
            'medium_risk_count' => $p->medium_risk_count,
            'low_risk_count' => $p->low_risk_count,
            'analysis_started_at' => Iso8601::utcZ($p->analysis_started_at),
            'analysis_completed_at' => Iso8601::utcZ($p->analysis_completed_at),
            'created_at' => Iso8601::utcZ($p->created_at),
        ];
    }

    /**
     * @param  array{user_id?: int|null, status?: string|null}  $filters
     * @return LengthAwarePaginator<int, ClusterJob>
     */
    public function paginateClusterJobs(int $perPage, array $filters): LengthAwarePaginator
    {
        if (! Schema::hasTable('cluster_jobs')) {
            return $this->emptyPaginator($perPage);
        }
        $q = ClusterJob::query()->with('user:id,email,name')->orderByDesc('id');
        $this->applyUserFilter($q, $filters['user_id'] ?? null);
        if (! empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }

        return $q->paginate($perPage);
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeClusterJob(ClusterJob $j): array
    {
        $user = $j->user;

        return [
            'id' => $j->id,
            'user_id' => $j->user_id,
            'user_email' => $user?->email,
            'keyword' => $j->keyword,
            'language_code' => $j->language_code,
            'location_code' => $j->location_code,
            'status' => $j->status,
            'snapshot_id' => $j->snapshot_id,
            'error_preview' => $j->error_message ? mb_substr($j->error_message, 0, 200) : null,
            'created_at' => Iso8601::utcZ($j->created_at),
            'completed_at' => Iso8601::utcZ($j->completed_at),
        ];
    }

    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $q
     */
    protected function applyUserFilter(Builder $q, ?int $userId): void
    {
        if (! empty($userId)) {
            $q->where('user_id', (int) $userId);
        }
    }

    /**
     * @return LengthAwarePaginator<int, never>
     */
    protected function emptyPaginator(int $perPage): LengthAwarePaginator
    {
        return new ConcretePaginator([], 0, $perPage, 1, [
            'path' => ConcretePaginator::resolveCurrentPath(),
        ]);
    }
}
