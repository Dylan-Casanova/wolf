<?php

declare(strict_types=1);

namespace App\Http\Requests\GeoFence;

use Illuminate\Foundation\Http\FormRequest;

class EstimateGeoFenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('view', $this->route('geoFence'));
    }

    public function rules(): array
    {
        return [
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ];
    }
}
