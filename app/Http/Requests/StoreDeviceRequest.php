<?php

namespace App\Http\Requests;

use App\Enums\DeviceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'device_id' => ['required', 'string', 'max:255', 'unique:devices,device_id'],
            'user_id' => ['nullable', 'exists:users,id'],
            'type' => ['required', Rule::in(DeviceType::values())],
        ];
    }
}
