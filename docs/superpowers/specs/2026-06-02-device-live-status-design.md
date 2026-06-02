# Device Live Status — Design Spec

**Date:** 2026-06-02
**Status:** Approved

## Overview

Add real-time device online/offline tracking to the Wolf dashboard using MQTT LWT + periodic heartbeat + Reverb WebSocket push. The devices table should show a live status indicator and updated `last_seen_at` without page refresh.

## Architecture

Three layers work together:

1. **ESP32 firmware** — publishes status and heartbeat via MQTT
2. **Laravel MQTT listener** — subscribes to device topics, updates DB, broadcasts events
3. **Stale device scheduler** — safety net that marks devices offline if heartbeat stops

```
ESP32                    Mosquitto              Laravel                 Browser
 |                          |                      |                      |
 |-- online (retained) ---->|                      |                      |
 |-- LWT: offline (set) --->|                      |                      |
 |                          |                      |                      |
 |                          |<-- subscribe --------|  (mqtt:listen)       |
 |                          |    wolf/+/status     |                      |
 |                          |    wolf/+/heartbeat  |                      |
 |                          |                      |                      |
 |-- heartbeat (5min) ----->|--- forward --------->|-- markOnline() ----->|
 |                          |                      |-- broadcast -------->| (Reverb)
 |                          |                      |                      |-- update row
 |                          |                      |                      |
 | (power off / crash)      |                      |                      |
 |         X                |--- LWT offline ----->|-- markOffline() ---->|
 |                          |                      |-- broadcast -------->| (Reverb)
 |                          |                      |                      |-- update row
 |                          |                      |                      |
 |                          |                      |  (schedule: 5min)    |
 |                          |                      |-- check stale ------>|
 |                          |                      |   last_seen > 15min  |
 |                          |                      |-- markOffline() ---->|
 |                          |                      |-- broadcast -------->| (Reverb)
```

## ESP32 Firmware Changes

### MQTT Connect

On successful MQTT connection:
- Set LWT: topic `wolf/{device_id}/status`, payload `offline`, retained, QoS 1
- Publish `online` (retained) to `wolf/{device_id}/status`

### Heartbeat

Every **5 minutes**, publish to `wolf/{device_id}/heartbeat`:
```json
{
  "rssi": -45,
  "free_heap": 120000,
  "uptime": 3600
}
```

### Graceful Disconnect

If a shutdown command is ever added, publish `offline` to `wolf/{device_id}/status` before disconnecting.

## Laravel MQTT Listener

### New Command: `mqtt:listen`

A long-running Artisan command that subscribes to:
- `wolf/+/status` — handles `online`/`offline` messages
- `wolf/+/heartbeat` — handles heartbeat payloads

**On `online` message:**
- Extract `device_id` from topic (`wolf/{device_id}/status`)
- Find device by `device_id`
- Call `$device->markOnline()`
- Broadcast `DeviceStatusChanged` event

**On `offline` message:**
- Extract `device_id` from topic
- Find device by `device_id`
- Call `$device->markOffline()`
- Broadcast `DeviceStatusChanged` event

**On heartbeat message:**
- Extract `device_id` from topic (`wolf/{device_id}/heartbeat`)
- Parse JSON metadata (rssi, free_heap, uptime)
- Find device by `device_id`
- Call `$device->markOnline($meta)`
- Broadcast `DeviceStatusChanged` event

### Docker Service

New `mqtt-listener` service in `docker-compose.yml`:
- Command: `php artisan mqtt:listen`
- Same build/volumes as the queue worker
- Restart policy: `unless-stopped`

## Stale Device Check

### New Scheduled Command: `devices:check-stale`

Runs every **5 minutes** via Laravel scheduler.

- Query: all devices where `is_online = true` AND `last_seen_at < now() - 15 minutes`
- For each stale device:
  - Call `$device->markOffline()`
  - Broadcast `DeviceStatusChanged` event

### Scheduler Execution

Runs via `php artisan schedule:work` inside the app container (or a dedicated scheduler service if needed).

## Reverb Broadcast & Frontend

### New Event: `DeviceStatusChanged`

Implements `ShouldBroadcast`. Broadcasts on a private admin channel with payload:
```php
[
    'device_id' => $device->id,
    'is_online' => $device->is_online,
    'last_seen_at' => $device->last_seen_at,
]
```

### Devices Index Page (`Index.tsx`)

- Listen on the admin channel via Laravel Echo (same pattern as `CaptureReady`)
- On `DeviceStatusChanged` event, update the matching device row in state
- Badge flips green/gray, `last_seen_at` updates — no page reload

### DeviceStatusBadge

Already functional — receives `isOnline` prop and renders green (Online) or gray (Offline) badge. No changes needed.

## Configuration

- **Heartbeat interval:** 5 minutes (ESP32 firmware constant)
- **Stale threshold:** 15 minutes (Laravel scheduled command)
- **MQTT topics:**
  - `wolf/{device_id}/status` — online/offline (retained)
  - `wolf/{device_id}/heartbeat` — periodic metadata
- **Broadcast channel:** Private admin channel via Reverb

## What's NOT in Scope

- Geo-fencing (V1.1)
- Garage door servo control (future, reuses same MQTT pipeline)
- Device-to-device communication
- Heartbeat retry/backoff logic on ESP32 (keep simple for V1.0)
