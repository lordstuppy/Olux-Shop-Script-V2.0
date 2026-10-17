<?php

namespace App\Models;

use App\Enums\WebhookEventStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class WebhookEvent extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => WebhookEventStatus::class,
            'payload' => 'array',
            'attempts' => 'integer',
            'next_attempt_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    /** Failed or dead, or stuck in "received" because processing never finished. */
    public function scopeNeedsAttention(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereIn('status', [WebhookEventStatus::Failed->value, WebhookEventStatus::Dead->value])
            ->orWhere(fn (Builder $q) => $q->where('status', WebhookEventStatus::Received->value)->where('updated_at', '<', self::staleBefore())));
    }

    public static function staleBefore(): Carbon
    {
        return now()->subMinutes((int) config('shop.webhook_stale_minutes'));
    }

    public function isStale(): bool
    {
        return $this->status === WebhookEventStatus::Received && $this->updated_at !== null && $this->updated_at->lt(self::staleBefore());
    }
}
