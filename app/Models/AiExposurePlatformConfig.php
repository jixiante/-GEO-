<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiExposurePlatformConfig extends Model
{
    protected $fillable = [
        'platform',
        'ai_model_id',
        'enabled',
        'updated_by_admin_id',
    ];

    protected function casts(): array
    {
        return [
            'ai_model_id' => 'integer',
            'enabled' => 'boolean',
            'updated_by_admin_id' => 'integer',
        ];
    }

    public function aiModel(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'ai_model_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'updated_by_admin_id');
    }
}
