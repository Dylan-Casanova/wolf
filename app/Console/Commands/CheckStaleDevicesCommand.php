<?php

namespace App\Console\Commands;

use App\Events\DeviceStatusChanged;
use App\Models\Device;
use Illuminate\Console\Command;

class CheckStaleDevicesCommand extends Command
{
    protected $signature = 'devices:check-stale';

    protected $description = 'Mark devices as offline if no heartbeat received within 15 minutes';

    public function handle(): int
    {
        $staleDevices = Device::where('is_online', true)
            ->where(function ($query) {
                $query->where('last_seen_at', '<', now()->subMinutes(2))
                    ->orWhereNull('last_seen_at');
            })
            ->get();

        foreach ($staleDevices as $device) {
            $device->markOffline();
            DeviceStatusChanged::dispatch($device->fresh());
            $this->info("Marked {$device->device_id} as offline (stale).");
        }

        if ($staleDevices->isEmpty()) {
            $this->info('No stale devices found.');
        }

        return self::SUCCESS;
    }
}
