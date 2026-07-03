<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\DeviceInterface;
use App\Enums\DeviceType;
use App\Models\ScheduledGeofenceTrigger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class TriggerScheduledGeofenceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $scheduledTriggerId) {}

    public function handle(DeviceInterface $device): void
    {
        // Atomic claim: only fire if still pending
        $claimed = ScheduledGeofenceTrigger::where('id', $this->scheduledTriggerId)
            ->where('status', ScheduledGeofenceTrigger::STATUS_PENDING)
            ->update(['status' => ScheduledGeofenceTrigger::STATUS_FIRED]);

        if (! $claimed) {
            return; // cancelled, already fired, or deleted
        }

        $trigger = ScheduledGeofenceTrigger::with('geoFence.user.devices')->find($this->scheduledTriggerId);
        if (! $trigger || ! $trigger->geoFence) {
            return;
        }

        $fence = $trigger->geoFence;
        $esp = $fence->user->devices()->where('type', DeviceType::Esp8266->value)->first();
        if ($esp) {
            $device->triggerServo($esp);
        }

        // No fence-side write: the atomic claim above already flipped the trigger
        // row to status=fired. That alone makes the GeoFence is_active accessor
        // derive to false on its next read.
    }
}
