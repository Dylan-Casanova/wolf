# WebSocket Streaming Design

**Date:** 2026-06-05
**Status:** Approved
**Replaces:** 2026-06-03-video-streaming-design.md (temp file + MJPEG approach)

## Problem

The current streaming architecture uses a long-running PHP-FPM process to serve MJPEG via a temp file polling loop. This is unreliable: PHP-FPM workers aren't designed for persistent connections, `filemtime()` has 1-second resolution causing frame detection issues, and race conditions between the writer and reader can corrupt frames. Streams intermittently fail to start even when the MQTT command and ESP32 connection succeed.

## Solution

Replace the temp file + MJPEG approach with Reverb WebSocket broadcasting. The ESP32 still POSTs JPEG frames via HTTP, but instead of writing to a temp file, Laravel base64-encodes each frame and broadcasts it via Reverb. The browser receives frames through Echo and renders them directly.

## Data Flow

1. User clicks "LIVE VIEW" → `POST /stream/start` → creates Stream record, publishes MQTT `start_stream` command (unchanged)
2. Frontend subscribes to `private-stream.{streamId}` via Echo
3. ESP32 receives MQTT command, begins POSTing JPEG frames to `POST /api/device/stream/{streamId}/feed` (unchanged)
4. `feed()` controller base64-encodes the frame and broadcasts a `StreamFrameReceived` event via Reverb
5. Browser receives event, renders frame as `<img src="data:image/jpeg;base64,...">`
6. Stream ends via: user stop button, 120-second timeout, or stale cleanup
7. `StreamEnded` event broadcasts on the same channel with a reason
8. Frontend shows "Stream ended" message for ~3 seconds, then returns to idle

## Broadcast Events

### StreamFrameReceived

- Implements `ShouldBroadcastNow` (synchronous, not queued — frames must go out immediately)
- Channel: `private-stream.{streamId}`
- Payload: `{ "frame": "<base64-encoded JPEG>" }`
- Fired from `StreamFeedController@feed()` on every valid frame

### StreamEnded

- Implements `ShouldBroadcastNow`
- Channel: `private-stream.{streamId}`
- Payload: `{ "reason": "stopped" | "timeout" | "stale" }`
- Fired from `StreamController@stop()` and `CheckStaleDevicesCommand`

## Channel Authorization

```php
Broadcast::channel('stream.{streamId}', function ($user, $streamId) {
    $stream = Stream::find($streamId);
    return $stream && (int) $user->id === (int) $stream->user_id;
});
```

## Reverb Configuration

Publish `config/reverb.php` and set `max_message_size` to 150KB. Default Pusher protocol allows 10KB — VGA JPEG frames base64-encoded are typically 40-80KB.

## StreamView Component Changes

- State machine: `idle` → `connecting` → `streaming` → `ended` → `idle`
- New `ended` state shows "Stream ended" message for 3 seconds before returning to idle
- On start: `POST /stream/start`, subscribe to `private-stream.{streamId}` via Echo
- On `StreamFrameReceived`: set `<img>` src to `data:image/jpeg;base64,{frame}`
- On `StreamEnded`: transition to `ended` state, show message, auto-return to idle
- On stop button: `POST /stream/{id}/stop`, unsubscribe from channel
- Cleanup: `useEffect` return unsubscribes from channel, `sendBeacon` fires on navigate away
- 120-second countdown timer remains (unchanged)

## What Gets Removed

- `StreamFeedController@view()` method (the MJPEG endpoint)
- `GET /stream/{stream}` route
- All temp file I/O: `/tmp/wolf-streams/` writing, reading, `.seq` counter, `.tmp` atomic writes
- Temp file cleanup from `StreamController@stop()` and `CheckStaleDevicesCommand`

## What Stays Unchanged

- Stream model, table, and 24-hour auto-purge
- `feed()` endpoint (ESP32 still POSTs frames via HTTP)
- MQTT start/stop commands via DeviceInterface
- Device token verification
- Nginx unbuffered config for the feed endpoint

## Error Handling

- **ESP32 sends frames but nobody's listening:** Reverb drops unsubscribed messages. No resource leak.
- **Browser loses WebSocket:** Echo/Pusher.js auto-reconnects. Frames resume immediately on reconnect.
- **Stream ends while browser is disconnected:** Countdown timer hits zero and calls stop. Safety net for missed `StreamEnded` events.
- **Frame too large:** `max_message_size` set to 150KB. If a frame exceeds this, Reverb drops it silently — browser gets the next frame.
- **Multiple browser tabs:** All tabs subscribe to the same channel and receive frames independently. One stop call broadcasts to all.

## Testing

### New Tests
- `StreamFrameReceived` — broadcasts on correct channel with base64 data
- `StreamEnded` — broadcasts on correct channel with reason
- `StreamFeedController@feed()` — broadcasts event instead of writing temp file
- `StreamController@stop()` — broadcasts `StreamEnded`
- Channel authorization — owner can listen, other users cannot

### Modified Tests
- `StaleStreamCleanupTest` — remove temp file assertions, add `StreamEnded` broadcast assertion
- `StreamFeedTest` — assert event broadcast instead of file I/O

### Removed
- Any test logic asserting temp file creation/deletion
