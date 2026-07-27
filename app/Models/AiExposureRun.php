<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiExposureRun extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'ai_exposure_monitor_id',
        'dispatch_key',
        'status',
        'platform_total',
        'platform_succeeded',
        'mentioned_count',
        'cited_count',
        'error_message',
        'scheduled_for',
        'started_at',
        'completed_at',
    ];

    protected $attributes = [
        'status' => self::STATUS_QUEUED,
        'platform_total' => 0,
        'platform_succeeded' => 0,
        'mentioned_count' => 0,
        'cited_count' => 0,
    ];

    protected function casts(): array
    {
        return [
            'ai_exposure_monitor_id' => 'integer',
            'platform_total' => 'integer',
            'platform_succeeded' => 'integer',
            'mentioned_count' => 'integer',
            'cited_count' => 'integer',
            'scheduled_for' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(AiExposureMonitor::class, 'ai_exposure_monitor_id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(AiExposureResult::class, 'ai_exposure_run_id');
    }
}
