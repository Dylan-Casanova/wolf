# Live Video Streaming — Design Spec

**Date:** 2026-06-03
**Status:** Approved

## Overview

Replace the photo capture flow with on-demand MJPEG live streaming from ESP32-CAM to the dashboard. Uses a hybrid architecture: MQTT as the control plane (start/stop commands) and HTTP as the data plane (MJPEG frame delivery). Nginx + PHP-FPM relay frames from the ESP32 to the browser via a shared temp file — no extra services required.

## Architecture

**Control plane (MQTT — existing infrastructure):**

```
User clicks "Live View"
  → Laravel creates stream record (status: pending)
  → Laravel publishes {"action":"start_stream","stream_id":X} via MQTT
  → ESP32 receives command, starts streaming

User clicks "Stop" / navigates away / 2-min timeout
  → Laravel publishes {"action":"stop_stream"} via MQTT
  → ESP32 stops streaming
```

**Data plane (HTTP — new):**

```
ESP32                          Nginx + PHP-FPM                    Browser
  │                                  │                               │
  │  POST /api/device/stream/{id}/feed                               │
  │  Content-Type: multipart/x-mixed-replace                         │
  │──────────────────────────────────>│                               │
  │                                  │  Auth via X-Device-Token      │
  │                                  │  Write frames to              │
  │                                  │  /tmp/wolf-streams/{id}       │
  │                                  │                               │
  │                                  │  GET /api/stream/{id}         │
  │                                  │<──────────────────────────────│
  │                                  │  Read from temp file          │
  │                                  │  Content-Type: multipart/     │
  │                                  │    x-mixed-replace            │
  │                                  │──────────────────────────────>│
  │                                  │                               │
  │          (frames flow continuously until stop)                   │
  │                                  │                               │
  │  Connection closes               │  Delete temp file             │
  │                                  │  Mark stream: ended           │
```

**Browser rendering:** The stream displays in a plain `<img src="/api/stream/{streamId}">` tag. MJPEG over HTTP is natively supported by all browsers — no JavaScript frame handling, no WebSocket, no video player library needed.

## Stream Lifecycle

1. User clicks "Live View" on dashboard
2. Laravel creates a `streams` record (status: `pending`, device_id, user_id)
3. Laravel publishes `start_stream` MQTT command with the stream ID
4. ESP32 receives command, opens HTTP POST to `/api/device/stream/{streamId}/feed`
5. PHP-FPM authenticates the device token, begins writing frames to `/tmp/wolf-streams/{streamId}`
6. Stream record updated to `active`, `started_at` set
7. Browser `<img>` tag points to `/api/stream/{streamId}`, begins receiving frames
8. Stream ends via any of:
   - User clicks "Stop"
   - User navigates away (triggers stop API call)
   - 2-minute server-side timeout
   - ESP32 disconnects
9. Laravel publishes `stop_stream` MQTT command
10. Temp file deleted, stream record marked `ended`, `ended_at` set

## ESP32 Firmware Changes

### New MQTT actions in `mqtt.h`

**`start_stream`:**
- Sets a streaming flag and stores the stream ID
- Enters a streaming loop: capture frame → send as MJPEG part via HTTP chunked POST → repeat
- Runs `client.loop()` between frames so `stop_stream` can be received
- Auto-stops after 2 minutes (120000ms safety timeout)
- Target: ~10-12 FPS at VGA (640x480)

**`stop_stream`:**
- Clears the streaming flag
- The streaming loop exits on next iteration
- HTTP POST connection closes

### Streaming HTTP connection

- Opens a single long-lived HTTP POST to `{serverUrl}/api/device/stream/{streamId}/feed`
- Content-Type: `multipart/x-mixed-replace; boundary=frame`
- Each frame sent as a JPEG part with boundary delimiter
- Connection closes when streaming stops

### Behavior during streaming

- MQTT `client.loop()` runs between frames (receives stop command)
- Heartbeat pauses during streaming (active streaming proves device is alive)
- Camera stays initialized (already is from current code)

### Files removed

- `upload.h` — photo upload no longer needed

## Backend Changes

### New endpoints

**`POST /api/device/stream/{streamId}/feed`** (ESP32-facing)
- Authenticated via `X-Device-Token` header
- Receives MJPEG multipart stream from ESP32
- Writes incoming frames to `/tmp/wolf-streams/{streamId}`
- Closes after 2-minute timeout or ESP32 disconnect
- Updates stream record to `active` on first frame

**`GET /api/stream/{streamId}`** (browser-facing)
- Authenticated via session (admin user)
- Returns `Content-Type: multipart/x-mixed-replace; boundary=frame`
- Reads frames from `/tmp/wolf-streams/{streamId}` and streams to browser
- Closes after 2-minute timeout or when temp file is deleted

**`POST /api/stream/start`** (dashboard-facing)
- Authenticated via session
- Creates stream record, publishes `start_stream` MQTT command
- Returns stream ID for the `<img>` tag URL

**`POST /api/stream/{streamId}/stop`** (dashboard-facing)
- Authenticated via session
- Publishes `stop_stream` MQTT command
- Marks stream as `ended`, deletes temp file

### Nginx configuration

Two location blocks added to `default.conf`:

```nginx
# ESP32 pushes MJPEG frames here
location ~ ^/api/device/stream/(\d+)/feed$ {
    client_max_body_size 0;
    client_body_timeout 130s;
    proxy_request_buffering off;
    fastcgi_pass app:9000;
    # ... standard fastcgi params
}

# Browser reads MJPEG stream here
location ~ ^/api/stream/(\d+)$ {
    proxy_buffering off;
    proxy_read_timeout 130s;
    fastcgi_pass app:9000;
    # ... standard fastcgi params
}
```

Both endpoints disable buffering to enable real-time streaming.

### Stream record model

New `Stream` model with relationships to `Device` and `User`.

### Files removed

- `DeviceCaptureController.php` (upload endpoint)
- `CaptureService.php`
- `CaptureReady.php` event
- `DeviceCapture.php` model + factory
- Capture-related tests
- Capture history page and routes

## Database

### New: `streams` table

| Column | Type | Notes |
|--------|------|-------|
| id | bigint (PK) | |
| device_id | bigint (FK → devices) | |
| user_id | bigint (FK → users) | |
| status | string | pending, active, ended |
| started_at | timestamp (nullable) | Set when first frame arrives |
| ended_at | timestamp (nullable) | Set when stream ends |
| created_at | timestamp | |
| updated_at | timestamp | |

### Remove: `device_captures` table

Migration to drop the table.

## Frontend Changes

### Dashboard (`Dashboard.tsx`)

- Replace `CaptureButton` + `MediaDisplay` with a `StreamView` component
- "Live View" button starts the stream → calls `POST /api/stream/start` → gets stream ID
- Stream displays in `<img src="/api/stream/{streamId}" />`
- Button changes to "Stop" while streaming
- "Connecting..." state shown between click and first frame
- On navigate away: fires `POST /api/stream/{streamId}/stop` via `beforeunload` and route change cleanup
- After 2-minute timeout: stream ends, button resets to "Live View"

### Components removed

- `CaptureButton.tsx`
- `MediaDisplay.tsx`

### Pages removed

- `Captures/Index.tsx` (capture history page)

## Temp File Cleanup

Three layers to ensure no leaked files:

1. **On stream end:** When Laravel marks a stream as `ended`, it deletes `/tmp/wolf-streams/{streamId}`
2. **Stale cleanup:** Scheduled command deletes any temp files older than 3 minutes — catches anything missed by normal cleanup
3. **Container restart:** Files in `/tmp` are wiped automatically on restart

## Configuration

- **Stream timeout:** 2 minutes (enforced on both ESP32 and server)
- **Resolution:** VGA 640x480
- **Frame rate:** ~10-12 FPS
- **Temp file path:** `/tmp/wolf-streams/`
- **MQTT topics:** Reuses existing `wolf/{device_id}/command`

## What's NOT in Scope

- Recording/saving streams
- Multiple simultaneous viewers per device
- Servo/garage door control (future, reuses same MQTT command pipeline)
- Audio
- Resolution switching
