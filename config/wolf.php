<?php

return [
    /*
    | Default speed (mph) used to estimate arrival time for the web's
    | time-based geofence trigger. Single point estimate; not routing-aware.
    */
    'estimated_arrival_mph' => env('WOLF_ESTIMATED_ARRIVAL_MPH', 35),
];
