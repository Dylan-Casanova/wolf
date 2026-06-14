<?php

namespace App\Jobs;

use App\Contracts\DeviceInterface;
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
        $esp = $fence->user->devices()->where('type', 'esp8266')->first();
        if ($esp) {
            $device->triggerServo($esp);
        }

        $fence->update(['is_active' => false]);
    }
}
