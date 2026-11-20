<?php

namespace App\Models;

use App\Enums\GenerationStatus;
use App\Enums\Quality;
use App\Enums\Size;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Generation extends Model
{
    use HasFactory, HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'user_id',
        'pixelforge_id',
        'idempotency_key',
        'prompt',
        'size',
        'quality',
        'reference_images',
        'status',
        'image_url',
        'image_url_expires_at',
        'usage',
        'rates',
        'estimated_provider_cost',
        'estimated_user_cost',
        'actual_provider_cost',
        'actual_user_cost',
        'error_code',
        'error_message',
        'submitted_at',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => GenerationStatus::class,
            'size' => Size::class,
            'quality' => Quality::class,
            'reference_images' => 'array',
            'usage' => 'array',
            'rates' => 'array',
            'estimated_provider_cost' => 'decimal:8',
            'estimated_user_cost' => 'decimal:8',
            'actual_provider_cost' => 'decimal:8',
            'actual_user_cost' => 'decimal:8',
            'submitted_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'image_url_expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->image_url_expires_at && $this->image_url_expires_at->isPast();
    }
}
