<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArticleDuplicateScan extends Model
{
    protected $fillable = [
        'article_id',
        'status',
        'max_similarity',
        'matched_article_id',
        'matches',
        'content_hash',
        'corpus_hash',
        'algorithm_version',
        'trigger',
        'admin_id',
        'scanned_at',
    ];

    protected $attributes = [
        'max_similarity' => 0,
        'is_overridden' => false,
    ];

    protected function casts(): array
    {
        return [
            'article_id' => 'integer',
            'max_similarity' => 'float',
            'matched_article_id' => 'integer',
            'matches' => 'array',
            'admin_id' => 'integer',
            'is_overridden' => 'boolean',
            'overridden_by_admin_id' => 'integer',
            'overridden_at' => 'datetime',
            'scanned_at' => 'datetime',
        ];
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class, 'article_id');
    }

    public function matchedArticle(): BelongsTo
    {
        return $this->belongsTo(Article::class, 'matched_article_id')->withTrashed();
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'admin_id');
    }

    public function overriddenBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'overridden_by_admin_id');
    }
}
