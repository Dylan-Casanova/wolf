<?php

declare(strict_types=1);

namespace App\Http\Requests\GeoFence;

use Illuminate\Foundation\Http\FormRequest;

class UpdateGeoFenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('geoFence'));
    }

    public function rules(): array
    {
        return [
            'north_lat' => ['required', 'numeric', 'between:-90,90'],
            'south_lat' => ['required', 'numeric', 'between:-90,90'],
            'east_lng' => ['required', 'numeric', 'between:-180,180'],
            'west_lng' => ['required', 'numeric', 'between:-180,180'],
            'address_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'address_lng' => ['nullable', 'numeric', 'between:-180,180'],
        ];
    }
}
