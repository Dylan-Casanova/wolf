<?php

namespace App\Enums;

enum DeviceType: string
{
    case Esp32Cam = 'esp32_cam';
    case Esp8266 = 'esp8266';

    public function label(): string
    {
        return match ($this) {
            self::Esp32Cam => 'ESP32-CAM',
            self::Esp8266 => 'ESP8266',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function options(): array
    {
        return array_map(fn (self $case) => [
            'value' => $case->value,
            'label' => $case->label(),
        ], self::cases());
    }
}
