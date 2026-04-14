<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GeoFence extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'latitude',
        'longitude',
        'radius_meters',
        'trigger_on',
        'active',
    ];

    protected $casts = [
        'active'    => 'boolean',
        'latitude'  => 'float',
        'longitude' => 'float',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function captures(): HasMany
    {
        return $this->hasMany(DeviceCapture::class);
    }

    /**
     * Returns true if the given coordinates fall within this fence's radius.
     * Uses the Haversine formula.
     */
    public function contains(float $lat, float $lng): bool
    {
        $earthRadius = 6371000; // metres

        $dLat = deg2rad($lat - $this->latitude);
        $dLng = deg2rad($lng - $this->longitude);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($this->latitude)) * cos(deg2rad($lat)) * sin($dLng / 2) ** 2;

        $distance = $earthRadius * 2 * asin(sqrt($a));

        return $distance <= $this->radius_meters;
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }
}
