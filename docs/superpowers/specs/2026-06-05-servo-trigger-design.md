# Servo Trigger (Garage Door) Design

**Date:** 2026-06-05
**Status:** Approved

## Problem

Wolf can stream video from the ESP32-CAM but cannot control the garage door. The user needs a button to trigger a servo that presses the garage door opener button, with confirmation that the action was executed.

## Solution

Add a garage door trigger button to the dashboard that sends an MQTT command to the ESP32. The ESP32 actuates a servo on GPIO 13 to press the garage door button, then publishes an acknowledgment. The frontend receives the ack via Reverb and shows confirmation.

## Data Flow

### Without active stream

1. User taps "Open/Close" button → `POST /garage/trigger`
2. Laravel publishes `{"action": "trigger_servo"}` via MQTT to `wolf/{deviceId}/command`
3. UI shows "Triggering garage..." loading state
4. ESP32 receives command, actuates servo (sweep to press position, hold 500ms, return to rest)
5. ESP32 publishes `{"status": "done"}` to `wolf/{deviceId}/servo`
6. Laravel MQTT listener picks up ack, broadcasts `ServoTriggered` event via Reverb
7. UI shows "Garage triggered" for 3 seconds, returns to idle

### With active stream

1. User taps "Open/Close" → frontend stops the active stream first
2. `POST /garage/trigger` — same MQTT command as above
3. UI shows "Triggering garage..." loading state
4. ESP32 actuates servo, publishes ack
5. Frontend receives ack via Reverb
6. Frontend immediately restarts the stream automatically
7. User sees the garage door moving in the live feed

### Timeout

If no ack is received within 10 seconds, the UI shows "Failed to trigger — try again" error state. The button re-enables so the user can retry. If a stream was active before the trigger, it is not automatically restarted on timeout — the user can manually restart it.

## Backend Components

### GarageController

- New controller: `app/Http/Controllers/GarageController.php`
- Single method: `trigger(Request $request)`
- Validates user has a device (422 if not)
- Calls `DeviceInterface::triggerServo($device)`
- Returns `{"message": "Command sent."}`

### Route

- `POST /garage/trigger` — auth middleware, in the same route group as streaming routes

### DeviceInterface

Add method: `triggerServo(Device $device): bool`

- `Esp32MqttDevice`: publishes `{"action": "trigger_servo"}` to `wolf/{deviceId}/command`
- `MockDevice`: returns `true`

### ServoTriggered Event

- New event: `app/Events/ServoTriggered.php`
- Implements `ShouldBroadcastNow`
- Channel: `private-device.{deviceId}` (not stream channel — servo is device-scoped, not stream-scoped)
- Payload: `{"status": "done"}`
- `broadcastAs()`: `'ServoTriggered'`

### MQTT Listener Update

- `app/Console/Commands/MqttListenCommand.php` — subscribe to `wolf/+/servo` topic
- When a message arrives on `wolf/{deviceId}/servo`, look up the device, broadcast `ServoTriggered` event

### Channel Authorization

Add to `routes/channels.php`:

```php
Broadcast::channel('device.{deviceId}', function ($user, $deviceId) {
    return $user->devices()->where('id', $deviceId)->exists();
});
```

## Frontend Components

### GarageButton

- New component: `resources/js/Components/GarageButton.tsx`
- Props: `deviceId: number`, `onTriggerStart?: () => void`, `onTriggerComplete?: () => void`
- States: `idle` | `triggering` | `triggered` | `error`
- On click: calls `onTriggerStart` callback (so Dashboard can stop stream), then `POST /garage/trigger`
- Subscribes to `.ServoTriggered` on `private-device.{deviceId}` via Echo
- On ack: transitions to `triggered`, calls `onTriggerComplete` callback (so Dashboard can restart stream)
- On 10-second timeout: transitions to `error`
- Button disabled during `triggering` state

### Dashboard Integration

- Dashboard passes the device ID to both `StreamView` and `GarageButton`
- Dashboard coordinates the stream stop/restart around servo triggers
- `StreamView` needs to expose `startStream` and `stopStream` via ref or callbacks
- Layout: StreamView on top, GarageButton below (same column)

### Dashboard needs device ID

- Dashboard Inertia page needs the user's device ID passed as a prop
- Update the dashboard route to pass `device_id` from the user's first device

## Firmware Changes

### Servo Setup

- GPIO 13 for servo signal
- Initialize servo PWM channel at boot (using ESP32 `ledc` hardware PWM)
- Rest position: 0 degrees
- Press position: 90 degrees (adjustable — depends on physical mount)
- Use `ESP32Servo` library or raw `ledc` functions

### MQTT Action Handler

Add to `_mqttCallback` in `mqtt.h`:

```cpp
if (strcmp(action, "trigger_servo") == 0) {
    servoTrigger();
}
```

### Servo Module

- New file: `servo.h`
- `servoSetup()` — initialize PWM on GPIO 13
- `servoTrigger()` — sweep to press position, hold 500ms, return to rest, publish ack

### Ack Publishing

After servo actuation, publish to `wolf/{deviceId}/servo`:

```json
{"status": "done"}
```

## Error Handling

- **No device registered:** 422 response from `GarageController`
- **ESP32 offline:** MQTT command sent but no ack — 10-second timeout on frontend
- **Triggered during active stream:** Stream stops cleanly first, restarts after ack (not on timeout)
- **Multiple rapid taps:** Button disabled during `triggering` state to prevent double-triggers
- **Servo command during streaming on ESP32:** The `trigger_servo` handler runs between frames via `_mqttClient.loop()`. The 500ms servo hold pauses frame delivery briefly — acceptable since the frontend has already stopped the stream.

## Testing

### Backend Tests

- `GarageControllerTest` — user with device can trigger, user without device gets 422, unauthenticated gets 401
- `ServoTriggeredTest` — event broadcasts on correct channel with payload
- `DeviceInterface` — `triggerServo` publishes correct MQTT payload
- MQTT listener — handles servo ack topic, broadcasts event
- Channel auth — device owner can access `device.{deviceId}`, non-owner cannot

### What's Not Tested

- Frontend component behavior (manual testing)
- ESP32 firmware (manual testing with serial monitor)
- End-to-end servo actuation (physical hardware test)
