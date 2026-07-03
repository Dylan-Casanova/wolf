<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ScheduledGeofenceTriggerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'geo_fence_id' => $this->geo_fence_id,
            'scheduled_at' => $this->scheduled_at,
            'origin_lat' => $this->origin_lat,
            'origin_lng' => $this->origin_lng,
            'origin_distance_meters' => $this->origin_distance_meters,
            'status' => $this->status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
