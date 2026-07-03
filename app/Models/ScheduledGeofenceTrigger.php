<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduledGeofenceTrigger extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_FIRED = 'fired';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'geo_fence_id',
        'scheduled_at',
        'status',
        'origin_lat',
        'origin_lng',
        'origin_distance_meters',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'origin_lat' => 'float',
        'origin_lng' => 'float',
        'origin_distance_meters' => 'float',
    ];

    public function geoFence(): BelongsTo
    {
        return $this->belongsTo(GeoFence::class);
    }
}
