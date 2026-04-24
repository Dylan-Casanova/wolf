# ESP32-CAM Firmware Design

**Date:** 2026-04-16
**Scope:** Arduino sketch for AI-Thinker ESP32-CAM — WiFi captive portal setup, MQTT command listener, photo capture, HTTP POST upload to Wolf Laravel backend.

---

## Overview

The firmware has three modes:

1. **Setup mode** — On first boot (no saved config), creates a WiFi AP (`Wolf-Setup`). User connects and fills a web form with WiFi, MQTT, and server credentials. Saved to NVS. Reboots into normal mode.
2. **Normal mode** — Connects to WiFi and MQTT broker. Subscribes to `wolf/{device_id}/command`. Idles waiting for capture commands. Publishes LWT for offline detection.
3. **Capture mode** — On receiving a capture command via MQTT, takes a VGA photo and HTTP POSTs the JPEG to the Laravel upload endpoint with device token auth.

**Full loop:**
```
Browser -> Laravel -> MQTT -> ESP32 -> photo -> HTTP POST -> Laravel -> Reverb -> Browser
```

---

## Captive Portal (Setup Mode)

Triggered when NVS has no saved config.

- **AP name:** `Wolf-Setup` (open network)
- **Portal IP:** `192.168.4.1`
- **Web form fields:**
  - WiFi SSID
  - WiFi Password
  - Server URL (e.g. `http://192.168.1.50:8000` or `https://wolf.example.com`)
  - MQTT Host (e.g. `192.168.1.50`)
  - MQTT Port (default: `1883`)
  - Device ID (matches `device_id` in admin UI, e.g. `esp32-001`)
  - Device Token (plain-text token shown once at device creation)

After submit, values are saved to NVS (survives reboot/reflash). ESP32 reboots into normal mode.

**Re-entering setup mode:** Hold RESET for 5 seconds (or press a GPIO button) to wipe NVS and reboot into setup mode. Allows reconfiguring without reflashing.

---

## MQTT & Connectivity

**Boot sequence (normal mode):**
1. Connect to WiFi (retry every 5 seconds on failure)
2. Connect to MQTT broker:
   - Client ID: `wolf-{device_id}`
   - LWT topic: `wolf/{device_id}/status`
   - LWT message: `offline`
3. Publish `online` to `wolf/{device_id}/status` (retained)
4. Subscribe to `wolf/{device_id}/command` (QoS 1)
5. Blink onboard LED to indicate ready

**Reconnection:** Auto-reconnect with exponential backoff (2s, 4s, 8s, max 30s).

**LED indicators:**
- Solid on: connected and ready
- Fast blink: connecting / reconnecting
- Slow blink: setup mode (AP active)

---

## Photo Capture & Upload

**On MQTT command received:**

Expected payload: `{"action":"capture","capture_id":123,"type":"image"}`

1. Parse JSON payload
2. Initialize camera (OV2640) at VGA resolution (640x480)
3. Take photo — grab frame buffer
4. HTTP POST raw JPEG bytes to `{server_url}/api/device/captures/{capture_id}/upload`
   - Header: `Content-Type: image/jpeg`
   - Header: `X-Device-Token: {saved_token}`
5. Release frame buffer
6. Flash LED briefly to confirm capture

**On upload failure:** Log error to Serial. No retry in V1.0 — user presses capture again.

---

## Hardware

- **Board:** AI-Thinker ESP32-CAM (OV2640 camera)
- **Programmer:** Arduino Uno used as USB-to-serial adapter
- **IDE:** Arduino IDE

---

## Arduino Libraries

- `WiFi.h` — built-in, WiFi connectivity
- `esp_camera.h` — built-in, camera driver
- `HTTPClient.h` — built-in, HTTP POST
- `PubSubClient` — install from Library Manager, MQTT client
- `WebServer.h` — built-in, captive portal
- `Preferences.h` — built-in, NVS storage
- `ArduinoJson` — install from Library Manager, JSON parsing

---

## File Structure

```
wolf-esp32-cam/
├── wolf-esp32-cam.ino    -- main entry: setup(), loop(), mode switching
├── config.h              -- NVS read/write, captive portal web server
├── camera.h              -- camera init, take photo, release buffer
├── mqtt.h                -- MQTT connect, subscribe, message handler
├── upload.h              -- HTTP POST photo to Laravel
└── led.h                 -- LED indicator patterns
```

Each `.h` file contains declarations and implementation (standard Arduino pattern). The `.ino` file orchestrates everything.

---

## Backend Integration Points

The firmware integrates with these existing Laravel endpoints and conventions:

| Firmware action | Backend target |
|---|---|
| Subscribe to commands | MQTT topic `wolf/{device_id}/command` |
| Publish online status | MQTT topic `wolf/{device_id}/status` (retained) |
| LWT offline message | MQTT topic `wolf/{device_id}/status` |
| Upload photo | `POST /api/device/captures/{capture_id}/upload` |
| Auth header | `X-Device-Token: {plain_text_token}` |
| Content type | `image/jpeg` |

---

## Future Improvements (Backlog)

1. **Server-side MQTT listener for online/offline** — An MQTT listener on the Laravel server that watches `wolf/{device_id}/status` topics and automatically calls `markOnline()` / `markOffline()` on the Device model. Removes need for manual tracking.
2. **Video clip capture** — Short AVI/MJPEG recording with configurable duration.
3. **Higher resolutions** — SVGA, XGA, or UXGA options configurable via MQTT command payload.
4. **Queue-based retry** — On upload failure, store photo to SD card and retry later.
5. **OTA firmware updates** — Push new firmware over WiFi without reflashing via USB.
6. **MQTT over TLS** — Encrypted MQTT connection for production security.
7. **HTTPS uploads** — TLS for photo upload POST (needs cert management on ESP32).
8. **Battery/deep sleep mode** — Wake on MQTT or GPIO trigger, sleep between captures to save power.
