<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Device Driver
    |--------------------------------------------------------------------------
    | Supported: "mock", "esp32_mqtt"
    |
    | Use "mock" for local development without physical hardware.
    | Use "esp32_mqtt" in production once the MQTT broker and ESP32 are ready.
    */
    'driver' => env('DEVICE_DRIVER', 'mock'),

    'esp32' => [
        'base_url' => env('ESP32_BASE_URL', 'http://192.168.1.100'),
        'timeout' => (int) env('ESP32_TIMEOUT_SECONDS', 10),
    ],

    'mqtt' => [
        'host' => env('MQTT_HOST', '127.0.0.1'),
        'port' => (int) env('MQTT_PORT', 1883),
        'topic' => env('MQTT_DEVICE_TOPIC', 'wolf/device'),
        'username' => env('MQTT_USERNAME'),
        'password' => env('MQTT_PASSWORD'),
    ],
];
