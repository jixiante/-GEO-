<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiExposureResult extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'ai_exposure_run_id',
        'platform',
        'ai_model_id',
        'status',
        'mentioned',
        'cited',
        'answer_text',
        'cited_urls',
        'matched_sources',
        'response_meta',
        'error_message',
        'checked_at',
    ];

    protected $attributes = [
        'status' => self::STATUS_PENDING,
        'mentioned' => false,
        'cited' => false,
    ];

    protected function casts(): array
    {
        return [
            'ai_exposure_run_id' => 'integer',
            'ai_model_id' => 'integer',
            'mentioned' => 'boolean',
            'cited' => 'boolean',
            'cited_urls' => 'array',
            'matched_sources' => 'array',
            'response_meta' => 'array',
            'checked_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AiExposureRun::class, 'ai_exposure_run_id');
    }

    public function aiModel(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'ai_model_id');
    }
}
