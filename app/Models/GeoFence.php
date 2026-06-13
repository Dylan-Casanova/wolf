<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GeoFence extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'north_lat',
        'south_lat',
        'east_lng',
        'west_lng',
        'is_active',
    ];

    protected $casts = [
        'north_lat' => 'float',
        'south_lat' => 'float',
        'east_lng' => 'float',
        'west_lng' => 'float',
        'is_active' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
