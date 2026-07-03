<?php

declare(strict_types=1);

return [
    /*
    | Default speed (mph) used to estimate arrival time for the web's
    | time-based geofence trigger. Single point estimate; not routing-aware.
    */
    'estimated_arrival_mph' => env('WOLF_ESTIMATED_ARRIVAL_MPH', 35),

    /*
    | Device liveness thresholds — used by `devices:check-stale` to
    | mark devices offline when they stop reporting.
    */
    'device' => [
        'stale_after_minutes' => (int) env('WOLF_DEVICE_STALE_MINUTES', 2),
    ],

    /*
    | Stream lifecycle thresholds — used by `devices:check-stale` to
    | force-end streams that never transitioned or ran too long, and
    | to purge historical rows.
    */
    'stream' => [
        'stale_after_minutes' => (int) env('WOLF_STREAM_STALE_MINUTES', 3),
        'purge_after_hours' => (int) env('WOLF_STREAM_PURGE_HOURS', 24),
    ],

    /*
    | Per-user rate limit for endpoints that command physical hardware
    | (garage servo, stream start). Applied via the `device-capture`
    | named limiter in `AppServiceProvider`.
    */
    'rate_limits' => [
        'device_capture_per_minute' => (int) env('WOLF_DEVICE_CAPTURE_PER_MINUTE', 10),
    ],
];
