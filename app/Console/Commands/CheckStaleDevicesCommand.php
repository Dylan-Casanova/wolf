<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\StreamStatus;
use App\Events\DeviceStatusChanged;
use App\Events\StreamEnded;
use App\Models\Device;
use App\Models\Stream;
use Illuminate\Console\Command;

class CheckStaleDevicesCommand extends Command
{
    protected $signature = 'devices:check-stale';

    protected $description = 'Mark stale devices as offline and clean up stale streams';

    public function handle(): int
    {
        $this->cleanStaleDevices();
        $this->cleanStaleStreams();

        return self::SUCCESS;
    }

    private function cleanStaleDevices(): void
    {
        $staleAfter = (int) config('wolf.device.stale_after_minutes');

        $staleDevices = Device::where('is_online', true)
            ->where(function ($query) use ($staleAfter) {
                $query->where('last_seen_at', '<', now()->subMinutes($staleAfter))
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
    }

    private function cleanStaleStreams(): void
    {
        $staleStreams = Stream::stale()->get();

        foreach ($staleStreams as $stream) {
            $stream->update(['status' => StreamStatus::Ended, 'ended_at' => now()]);
            broadcast(new StreamEnded($stream->id, 'stale'));
            $this->info("Ended stale stream #{$stream->id}.");
        }

        if ($staleStreams->isEmpty()) {
            $this->info('No stale streams found.');
        }

        $deleted = Stream::purgeable()->delete();

        if ($deleted > 0) {
            $this->info("Purged {$deleted} ended stream(s).");
        }
    }
}
