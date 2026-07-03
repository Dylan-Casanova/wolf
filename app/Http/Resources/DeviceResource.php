<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeviceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'device_id' => $this->device_id,
            'type' => $this->type->value,
            'is_online' => $this->is_online,
            'last_seen_at' => $this->last_seen_at,
            'user' => $this->whenLoaded('user'),
        ];
    }
}
