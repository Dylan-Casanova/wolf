<?php

namespace App\Providers;

use App\Contracts\DeviceInterface;
use App\Services\Device\Esp32MqttDevice;
use App\Services\Device\MockDevice;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(DeviceInterface::class, function () {
            return match (config('device.driver')) {
                'esp32_mqtt' => new Esp32MqttDevice(
                    host: config('device.mqtt.host'),
                    port: config('device.mqtt.port'),
                    topic: config('device.mqtt.topic'),
                    username: config('device.mqtt.username'),
                    password: config('device.mqtt.password'),
                ),
                default => new MockDevice(),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // Limit capture triggers to 10 per minute per user
        RateLimiter::for('device-capture', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });
    }
}
