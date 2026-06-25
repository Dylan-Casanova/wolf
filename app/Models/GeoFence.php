<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class GeoFence extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'north_lat',
        'south_lat',
        'east_lng',
        'west_lng',
        'address_lat',
        'address_lng',
        'live_check_armed',
    ];

    protected $casts = [
        'north_lat' => 'float',
        'south_lat' => 'float',
        'east_lng' => 'float',
        'west_lng' => 'float',
        'address_lat' => 'float',
        'address_lng' => 'float',
        'live_check_armed' => 'boolean',
    ];

    protected $appends = ['is_active'];

    /**
     * Derived: true when either surface has the fence armed.
     * - Native arms by toggling `live_check_armed`.
     * - Web arms by creating a pending ScheduledGeofenceTrigger row.
     * Splitting prevents one surface from clearing the other's state.
     */
    public function getIsActiveAttribute(): bool
    {
        return $this->live_check_armed
            || $this->pendingScheduledTrigger !== null;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scheduledTriggers(): HasMany
    {
        return $this->hasMany(ScheduledGeofenceTrigger::class);
    }

    public function pendingScheduledTrigger(): HasOne
    {
        return $this->hasOne(ScheduledGeofenceTrigger::class)
            ->where('status', ScheduledGeofenceTrigger::STATUS_PENDING)
            ->latestOfMany();
    }

    public function contains(float $lat, float $lng): bool
    {
        return $lat <= $this->north_lat
            && $lat >= $this->south_lat
            && $lng <= $this->east_lng
            && $lng >= $this->west_lng;
    }

    public function centerLat(): float
    {
        return ($this->north_lat + $this->south_lat) / 2;
    }

    public function centerLng(): float
    {
        return ($this->east_lng + $this->west_lng) / 2;
    }

    public function distanceFromCenter(float $lat, float $lng): float
    {
        $earthRadius = 6371000;
        $centerLat = $this->centerLat();
        $centerLng = $this->centerLng();

        $dLat = deg2rad($lat - $centerLat);
        $dLng = deg2rad($lng - $centerLng);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($centerLat)) * cos(deg2rad($lat)) * sin($dLng / 2) ** 2;

        return $earthRadius * 2 * asin(sqrt($a));
    }
}
