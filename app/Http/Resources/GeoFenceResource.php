<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GeoFenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'north_lat' => $this->north_lat,
            'south_lat' => $this->south_lat,
            'east_lng' => $this->east_lng,
            'west_lng' => $this->west_lng,
            'address_lat' => $this->address_lat,
            'address_lng' => $this->address_lng,
            'live_check_armed' => $this->live_check_armed,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'pending_scheduled_trigger' => ScheduledGeofenceTriggerResource::make(
                $this->whenLoaded('pendingScheduledTrigger'),
            ),
        ];
    }
}
