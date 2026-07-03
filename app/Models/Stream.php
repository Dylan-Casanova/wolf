<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StreamStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Stream extends Model
{
    use HasFactory;

    protected $fillable = [
        'device_id',
        'user_id',
        'status',
        'started_at',
        'ended_at',
    ];

    protected $casts = [
        'status' => StreamStatus::class,
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isPending(): bool
    {
        return $this->status === StreamStatus::Pending;
    }

    public function isActive(): bool
    {
        return $this->status === StreamStatus::Active;
    }

    public function isEnded(): bool
    {
        return $this->status === StreamStatus::Ended;
    }

    /**
     * Streams that should be force-ended because they never transitioned
     * or ran past the configured stale threshold.
     *
     * - Pending: created_at older than the stale window (never sent a frame).
     * - Active: started_at older than the stale window (still streaming past cap).
     */
    public function scopeStale(Builder $query): Builder
    {
        $staleAfter = (int) config('wolf.stream.stale_after_minutes');
        $cutoff = now()->subMinutes($staleAfter);

        return $query->whereIn('status', [StreamStatus::Active, StreamStatus::Pending])
            ->where(function ($q) use ($cutoff) {
                $q->where(function ($inner) use ($cutoff) {
                    $inner->where('status', StreamStatus::Active)
                        ->where('started_at', '<', $cutoff);
                })->orWhere(function ($inner) use ($cutoff) {
                    $inner->where('status', StreamStatus::Pending)
                        ->where('created_at', '<', $cutoff);
                });
            });
    }

    /**
     * Ended streams past their retention window — targets for hard delete.
     */
    public function scopePurgeable(Builder $query): Builder
    {
        $purgeAfterHours = (int) config('wolf.stream.purge_after_hours');

        return $query->where('status', StreamStatus::Ended)
            ->where('ended_at', '<', now()->subHours($purgeAfterHours));
    }
}
