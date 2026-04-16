<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
            'user_id' => ['required', 'exists:users,id', "unique:devices,user_id,{$deviceId}"],
            'type' => ['sometimes', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'user_id.unique' => 'This user already has a device assigned.',
        ];
    }
}
