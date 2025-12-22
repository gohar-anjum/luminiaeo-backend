<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KeywordCluster extends Model
{
    use HasFactory;
    protected $fillable = [
        'keyword_research_job_id',
        'topic_name',
        'description',
        'suggested_article_titles',
        'recommended_faq_questions',
        'schema_suggestions',
        'ai_visibility_projection',
        'keyword_count',
    ];

    protected $casts = [
        'suggested_article_titles' => 'array',
        'recommended_faq_questions' => 'array',
        'schema_suggestions' => 'array',
        'ai_visibility_projection' => 'float',
        'keyword_count' => 'integer',
    ];

    public function keywordResearchJob(): BelongsTo
    {
        return $this->belongsTo(KeywordResearchJob::class);
    }

    public function keywords(): HasMany
    {
        return $this->hasMany(Keyword::class);
    }
}
