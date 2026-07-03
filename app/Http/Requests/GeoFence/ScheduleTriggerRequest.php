<?php

declare(strict_types=1);

namespace App\Http\Requests\GeoFence;

use Illuminate\Foundation\Http\FormRequest;

class ScheduleTriggerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('geoFence'));
    }

    public function rules(): array
    {
        return [
            'minutes' => ['required', 'integer', 'between:1,180'],
            'origin_lat' => ['required', 'numeric', 'between:-90,90'],
            'origin_lng' => ['required', 'numeric', 'between:-180,180'],
        ];
    }
}
