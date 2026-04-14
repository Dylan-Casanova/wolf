<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceCapture extends Model
{
    protected $fillable = [
        'user_id',
        'device_id',
        'geo_fence_id',
        'trigger_source',
        'media_type',
        'media_url',
        'media_path',
        'status',
        'error_message',
        'device_meta',
    ];

    protected $casts = [
        'device_meta' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function geoFence(): BelongsTo
    {
        return $this->belongsTo(GeoFence::class);
    }
}
