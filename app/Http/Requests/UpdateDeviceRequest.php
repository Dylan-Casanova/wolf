<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\DeviceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $deviceId = $this->route('device')->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'device_id' => ['required', 'string', 'max:255', "unique:devices,device_id,{$deviceId}"],
            'user_id' => ['nullable', 'exists:users,id'],
            'type' => ['required', Rule::in(DeviceType::values())],
        ];
    }
}
