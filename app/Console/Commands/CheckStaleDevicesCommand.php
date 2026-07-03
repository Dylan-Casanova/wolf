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
    }

    private function cleanStaleStreams(): void
    {
        // End streams that are active for more than 3 minutes or pending for more than 3 minutes
        $staleStreams = Stream::whereIn('status', [StreamStatus::Active, StreamStatus::Pending])
            ->where(function ($query) {
                $query->where(function ($q) {
                    $q->where('status', StreamStatus::Active)
                        ->where('started_at', '<', now()->subMinutes(3));
                })->orWhere(function ($q) {
                    $q->where('status', StreamStatus::Pending)
                        ->where('created_at', '<', now()->subMinutes(3));
                });
            })
            ->get();

        foreach ($staleStreams as $stream) {
            $stream->update(['status' => StreamStatus::Ended, 'ended_at' => now()]);
            broadcast(new StreamEnded($stream->id, 'stale'));
            $this->info("Ended stale stream #{$stream->id}.");
        }

        if ($staleStreams->isEmpty()) {
            $this->info('No stale streams found.');
        }

        // Purge ended streams older than 24 hours
        $deleted = Stream::where('status', StreamStatus::Ended)
            ->where('ended_at', '<', now()->subHours(24))
            ->delete();

        if ($deleted > 0) {
            $this->info("Purged {$deleted} ended stream(s) older than 24 hours.");
        }
    }
}
