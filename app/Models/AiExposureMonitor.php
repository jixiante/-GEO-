<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

class AiExposureMonitor extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAUSED = 'paused';

    public const FREQUENCY_MANUAL = 'manual';

    public const FREQUENCY_FIVE_MINUTES = '5min';

    public const FREQUENCY_DAILY = 'daily';

    public const FREQUENCY_WEEKLY = 'weekly';

    protected $fillable = [
        'article_id',
        'query',
        'frequency',
        'status',
        'last_queued_at',
        'last_completed_at',
        'next_run_at',
        'created_by_admin_id',
    ];

    protected $attributes = [
        'frequency' => self::FREQUENCY_DAILY,
        'status' => self::STATUS_ACTIVE,
    ];

    protected function casts(): array
    {
        return [
            'article_id' => 'integer',
            'created_by_admin_id' => 'integer',
            'last_queued_at' => 'datetime',
            'last_completed_at' => 'datetime',
            'next_run_at' => 'datetime',
        ];
    }

    /** @return list<string> */
    public static function frequencies(): array
    {
        return [self::FREQUENCY_MANUAL, self::FREQUENCY_FIVE_MINUTES, self::FREQUENCY_DAILY, self::FREQUENCY_WEEKLY];
    }

    public function nextScheduledAt(?Carbon $from = null): ?Carbon
    {
        $from ??= now();

        return match ($this->frequency) {
            self::FREQUENCY_FIVE_MINUTES => $from->copy()->addMinutes(5),
            self::FREQUENCY_DAILY => $from->copy()->addDay(),
            self::FREQUENCY_WEEKLY => $from->copy()->addWeek(),
            default => null,
        };
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class, 'article_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AiExposureRun::class, 'ai_exposure_monitor_id');
    }

    public function latestRun(): HasOne
    {
        return $this->hasOne(AiExposureRun::class, 'ai_exposure_monitor_id')->latestOfMany();
    }
}
