<?php

declare(strict_types=1);

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
                    username: config('device.mqtt.username'),
                    password: config('device.mqtt.password'),
                ),
                default => new MockDevice,
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // Cap per-user commands to physical hardware. Tunable via
        // config('wolf.rate_limits.device_capture_per_minute').
        RateLimiter::for('device-capture', function (Request $request) {
            $perMinute = (int) config('wolf.rate_limits.device_capture_per_minute');

            return Limit::perMinute($perMinute)->by($request->user()?->id ?: $request->ip());
        });
    }
}
