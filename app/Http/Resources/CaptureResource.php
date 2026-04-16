<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CaptureResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'trigger_source' => $this->trigger_source,
            'media_type'     => $this->media_type,
            'media_url'      => $this->media_url,
            'status'         => $this->status,
            'error_message'  => $this->error_message,
            'captured_at'    => $this->created_at?->toISOString(),
            'device'         => $this->whenLoaded('device', fn () => ['name' => $this->device->name]),
            'user'           => $this->whenLoaded('user', fn () => ['name' => $this->user->name, 'email' => $this->user->email]),
        ];
    }
}
