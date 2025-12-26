<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Keyword extends Model
{
    use HasFactory;
    protected $fillable = [
        'keyword',
        'search_volume',
        'competition',
        'cpc',
        'intent',
        'location',
    ];

    // Note: The following columns are conditionally handled - only included if they exist in database:
    // keyword_research_job_id, keyword_cluster_id, source, ai_visibility_score, intent_category,
    // intent_metadata, question_variations, long_tail_versions, semantic_data, language_code, geoTargetId

    protected $casts = [
        'search_volume' => 'integer',
        'competition' => 'float',
        'cpc' => 'float',
        'ai_visibility_score' => 'float',
        'question_variations' => 'array',
        'long_tail_versions' => 'array',
        'intent_metadata' => 'array',
        'semantic_data' => 'array',
        'geoTargetId' => 'integer',
    ];

    public function keywordResearchJob(): BelongsTo
    {
        return $this->belongsTo(KeywordResearchJob::class);
    }

    public function cluster(): BelongsTo
    {
        return $this->belongsTo(KeywordCluster::class, 'keyword_cluster_id');
    }

    public function scopeFromSource($query, string $source)
    {
        return $query->where('source', $source);
    }

    public function scopeWithIntent($query, string $intent)
    {
        return $query->where('intent', $intent);
    }

    public function scopeWithIntentCategory($query, string $category)
    {
        return $query->where('intent_category', $category);
    }

    public function scopeInCluster($query, int $clusterId)
    {
        return $query->where('keyword_cluster_id', $clusterId);
    }

    public function scopeHighVisibility($query, float $minScore = 70.0)
    {
        return $query->where('ai_visibility_score', '>=', $minScore);
    }
}
