# ESP32-CAM Firmware Implementation Plan

> **For agentic workers:** This plan is fully self-contained. Execute it task-by-task. All code is provided — write the files exactly as shown. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build an Arduino sketch for the AI-Thinker ESP32-CAM that receives MQTT capture commands from the Wolf Laravel backend, takes VGA photos, and HTTP POSTs them back — with a WiFi captive portal for initial configuration.

**Architecture:** Single Arduino sketch with 5 header files. On first boot, the ESP32 creates a WiFi AP with a web form for entering credentials. After configuration, it connects to WiFi and an MQTT broker, subscribes to a command topic, and waits. When a capture command arrives, it takes a photo with the OV2640 camera and uploads the JPEG to the Laravel API endpoint.

**Tech Stack:** Arduino IDE, ESP32 Arduino core, PubSubClient (MQTT), ArduinoJson (JSON parsing), built-in ESP32 libraries (WiFi, esp_camera, HTTPClient, WebServer, Preferences)

---

## Critical Constraints

- **The user handles all git operations.** Never run `git add`, `git commit`, or `git push`. After writing files, tell the user what to stage.
- **Compilation is manual.** The Arduino IDE is a GUI app — you cannot compile or upload from the terminal. Write the files, then tell the user to open Arduino IDE and click Verify/Upload.
- **All files go in:** `/Users/mr.casanova/Code/wolf/wolf-esp32-cam/`

---

## Project Context: What is Wolf?

Wolf is a remote camera app. A user opens a web page, presses a "Capture" button, and an ESP32-CAM somewhere else takes a photo and sends it back to the browser in real-time.

**The full data flow:**
```
1. User clicks Capture in browser
2. Browser → Laravel backend (Inertia POST)
3. Laravel creates a DeviceCapture record (status: "pending") and publishes
   an MQTT message to the device's command topic
4. ESP32-CAM receives the MQTT message, takes a photo
5. ESP32-CAM HTTP POSTs the JPEG to Laravel's upload endpoint
6. Laravel saves the image, updates the record to "success",
   and broadcasts a CaptureReady event via Reverb WebSocket
7. Browser receives the event and displays the photo
```

This plan builds step 4 and 5 — the ESP32 firmware.

---

## Backend API Contract

The firmware must match these exact backend contracts. The Laravel code already exists and cannot be changed.

### MQTT Command (Server → Device)

The Laravel backend publishes to this MQTT topic when a capture is requested:

- **Topic pattern:** `wolf/{device_id}/command`
  - Example: `wolf/esp32-001/command`
- **Payload (JSON):**
  ```json
  {"action":"capture","capture_id":123,"type":"image"}
  ```
- **QoS:** 1

The firmware subscribes to this topic and handles the `capture` action.

### MQTT Status (Device → Broker)

- **Topic pattern:** `wolf/{device_id}/status`
- **On connect:** Publish `online` (retained)
- **LWT (Last Will and Testament):** `offline` (retained) — broker auto-publishes this if the device disconnects unexpectedly

### Photo Upload (Device → Server)

After taking a photo, the ESP32 uploads it via HTTP POST:

- **URL:** `{server_url}/api/device/captures/{capture_id}/upload`
  - Example: `http://192.168.1.50:8000/api/device/captures/123/upload`
- **Method:** `POST`
- **Headers:**
  - `Content-Type: image/jpeg`
  - `X-Device-Token: {plain_text_device_token}`
- **Body:** Raw JPEG bytes (not multipart form, not base64 — raw binary)
- **Auth:** The `X-Device-Token` header value is verified against a bcrypt hash stored in the `devices` table. The token is generated once when the device is created in the Wolf admin UI.
- **Success response:** HTTP 200 with JSON body (can be ignored by firmware)
- **Error responses:**
  - 401: Invalid or missing device token
  - 409: Capture already processed (not pending)
  - 404: Capture ID doesn't exist

---

## Prerequisites

Before starting, the user must complete these Arduino IDE setup steps:

1. **Install ESP32 board support:**
   - Arduino IDE → File → Preferences → Additional Board Manager URLs: `https://espressif.github.io/arduino-esp32/package_esp32_index.json`
   - Tools → Board Manager → search "esp32" → install "esp32 by Espressif Systems"

2. **Install libraries from Library Manager** (Sketch → Include Library → Manage Libraries):
   - `PubSubClient` by Nick O'Leary (MQTT client)
   - `ArduinoJson` by Benoit Blanchon (JSON parsing)

3. **Board settings** (Tools menu):
   - Board: "AI Thinker ESP32-CAM"
   - Partition Scheme: "Huge APP (3MB No OTA/1MB SPIFFS)"
   - Upload Speed: 115200
   - Port: (the serial port your Arduino Uno programmer shows up as)

---

## File Structure

All files live in `/Users/mr.casanova/Code/wolf/wolf-esp32-cam/` (the `wolf-esp32-cam` directory at the root of the Wolf Laravel project):

```
wolf-esp32-cam/
├── wolf-esp32-cam.ino    -- main entry: setup(), loop(), mode switching
├── config.h              -- NVS read/write, captive portal web server, WolfConfig struct
├── camera.h              -- camera pin definitions, init, take photo, release buffer
├── mqtt.h                -- MQTT connect with LWT, subscribe, message callback, reconnect
├── upload.h              -- HTTP POST photo to Laravel upload endpoint
└── led.h                 -- LED pin, solid/fast-blink/slow-blink patterns
```

| File | Responsibility | Dependencies |
|---|---|---|
| `led.h` | LED control (solid, fast blink, slow blink) | None |
| `config.h` | `WolfConfig` struct, NVS load/save/clear, captive portal web server | `led.h` (slow blink during setup) |
| `camera.h` | OV2640 pin map for AI-Thinker, `initCamera()`, `takePhoto()` | None |
| `upload.h` | `uploadPhoto()` — HTTP POST JPEG to Laravel with device token header | `config.h` (reads server URL + token) |
| `mqtt.h` | MQTT connect (LWT, client ID), subscribe, callback that parses JSON and triggers capture+upload | `config.h`, `camera.h`, `upload.h`, `led.h` |
| `wolf-esp32-cam.ino` | `setup()` loads config, decides mode. `loop()` keeps MQTT alive, handles reconnect | All headers |

---

### Task 1: LED Indicator Module

**Files:**
- Create: `wolf-esp32-cam/led.h`

This is the simplest module — no dependencies. It controls the onboard LED (GPIO 33 on AI-Thinker, active LOW) with three patterns.

- [ ] **Step 1: Create the sketch directory**

Run in terminal:
```bash
mkdir -p /Users/mr.casanova/Code/wolf/wolf-esp32-cam
```

- [ ] **Step 2: Write `led.h`**

Create `wolf-esp32-cam/led.h`:

```cpp
#ifndef WOLF_LED_H
#define WOLF_LED_H

// AI-Thinker ESP32-CAM onboard LED is GPIO 33 (active LOW)
#define LED_PIN 33

// Flash LED is GPIO 4 (the bright white one, active HIGH)
#define FLASH_PIN 4

static unsigned long _ledLastToggle = 0;
static bool _ledState = false;
static int _ledBlinkInterval = 0; // 0 = solid, >0 = blink interval in ms

void ledInit() {
  pinMode(LED_PIN, OUTPUT);
  pinMode(FLASH_PIN, OUTPUT);
  digitalWrite(LED_PIN, HIGH);   // OFF (active LOW)
  digitalWrite(FLASH_PIN, LOW);  // OFF
}

// Solid ON — connected and ready
void ledSolid() {
  _ledBlinkInterval = 0;
  digitalWrite(LED_PIN, LOW); // ON (active LOW)
}

// Fast blink — connecting / reconnecting (200ms interval)
void ledFastBlink() {
  _ledBlinkInterval = 200;
}

// Slow blink — setup mode / AP active (1000ms interval)
void ledSlowBlink() {
  _ledBlinkInterval = 1000;
}

// Turn LED off
void ledOff() {
  _ledBlinkInterval = 0;
  digitalWrite(LED_PIN, HIGH); // OFF (active LOW)
}

// Call this in loop() to update blink pattern
void ledUpdate() {
  if (_ledBlinkInterval == 0) return;

  unsigned long now = millis();
  if (now - _ledLastToggle >= (unsigned long)_ledBlinkInterval) {
    _ledLastToggle = now;
    _ledState = !_ledState;
    digitalWrite(LED_PIN, _ledState ? LOW : HIGH);
  }
}

// Brief flash of the bright white LED (for capture confirmation)
void ledFlashCapture() {
  digitalWrite(FLASH_PIN, HIGH);
  delay(150);
  digitalWrite(FLASH_PIN, LOW);
}

#endif
```

- [ ] **Step 3: Verify it compiles**

Create a minimal `wolf-esp32-cam/wolf-esp32-cam.ino` for compilation testing:

```cpp
#include "led.h"

void setup() {
  Serial.begin(115200);
  ledInit();
  ledSlowBlink();
  Serial.println("[wolf] LED module test");
}

void loop() {
  ledUpdate();
}
```

Tell the user to open `wolf-esp32-cam/wolf-esp32-cam.ino` in Arduino IDE and click **Verify** (checkmark button). You cannot compile from the terminal — Arduino IDE is a GUI app.

Expected: Compilation succeeds. Do NOT upload yet — we're just verifying compilation at each step.

- [ ] **Step 4: Notify user to commit**

Tell the user to stage: `wolf-esp32-cam/led.h` and `wolf-esp32-cam/wolf-esp32-cam.ino`. Do NOT run git commands yourself.

---

### Task 2: Configuration & Captive Portal Module

**Files:**
- Create: `wolf-esp32-cam/config.h`
- Modify: `wolf-esp32-cam/wolf-esp32-cam.ino`

This is the largest module. It defines the `WolfConfig` struct, NVS persistence, and the captive portal web server with an HTML form.

- [ ] **Step 1: Write `config.h`**

Create `wolf-esp32-cam/config.h`:

```cpp
#ifndef WOLF_CONFIG_H
#define WOLF_CONFIG_H

#include <WiFi.h>
#include <WebServer.h>
#include <Preferences.h>
#include "led.h"

// Config struct — all fields needed to operate in normal mode
struct WolfConfig {
  String wifiSsid;
  String wifiPassword;
  String serverUrl;    // e.g. "http://192.168.1.50:8000"
  String mqttHost;     // e.g. "192.168.1.50"
  int    mqttPort;     // default 1883
  String deviceId;     // e.g. "esp32-001"
  String deviceToken;  // plain-text token from admin UI
};

static WolfConfig _wolfConfig;
static Preferences _prefs;
static WebServer _portalServer(80);

// ── NVS persistence ──────────────────────────────────────────

bool configLoad() {
  _prefs.begin("wolf", true); // read-only
  _wolfConfig.wifiSsid    = _prefs.getString("wifiSsid", "");
  _wolfConfig.wifiPassword = _prefs.getString("wifiPass", "");
  _wolfConfig.serverUrl    = _prefs.getString("serverUrl", "");
  _wolfConfig.mqttHost     = _prefs.getString("mqttHost", "");
  _wolfConfig.mqttPort     = _prefs.getInt("mqttPort", 1883);
  _wolfConfig.deviceId     = _prefs.getString("deviceId", "");
  _wolfConfig.deviceToken  = _prefs.getString("devToken", "");
  _prefs.end();

  // Config is valid if at least WiFi SSID and device ID are set
  return _wolfConfig.wifiSsid.length() > 0 && _wolfConfig.deviceId.length() > 0;
}

void configSave() {
  _prefs.begin("wolf", false); // read-write
  _prefs.putString("wifiSsid",  _wolfConfig.wifiSsid);
  _prefs.putString("wifiPass",  _wolfConfig.wifiPassword);
  _prefs.putString("serverUrl", _wolfConfig.serverUrl);
  _prefs.putString("mqttHost",  _wolfConfig.mqttHost);
  _prefs.putInt("mqttPort",     _wolfConfig.mqttPort);
  _prefs.putString("deviceId",  _wolfConfig.deviceId);
  _prefs.putString("devToken",  _wolfConfig.deviceToken);
  _prefs.end();
}

void configClear() {
  _prefs.begin("wolf", false);
  _prefs.clear();
  _prefs.end();
}

WolfConfig& configGet() {
  return _wolfConfig;
}

// ── Captive portal HTML ──────────────────────────────────────

static const char PORTAL_HTML[] PROGMEM = R"rawliteral(
<!DOCTYPE html>
<html>
<head>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Wolf Setup</title>
  <style>
    body { font-family: -apple-system, sans-serif; max-width: 400px; margin: 40px auto; padding: 0 20px; background: #f5f5f5; }
    h1 { color: #4f46e5; font-size: 1.5rem; }
    label { display: block; margin-top: 12px; font-size: 0.875rem; font-weight: 600; color: #374151; }
    input { width: 100%; padding: 8px 12px; margin-top: 4px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 1rem; box-sizing: border-box; }
    button { margin-top: 20px; width: 100%; padding: 10px; background: #4f46e5; color: white; border: none; border-radius: 6px; font-size: 1rem; font-weight: 600; cursor: pointer; }
    button:hover { background: #4338ca; }
    .note { margin-top: 16px; font-size: 0.75rem; color: #6b7280; }
  </style>
</head>
<body>
  <h1>Wolf Setup</h1>
  <form method="POST" action="/save">
    <label>WiFi SSID</label>
    <input name="wifiSsid" required>

    <label>WiFi Password</label>
    <input name="wifiPass" type="password">

    <label>Server URL</label>
    <input name="serverUrl" placeholder="http://192.168.1.50:8000" required>

    <label>MQTT Host</label>
    <input name="mqttHost" placeholder="192.168.1.50" required>

    <label>MQTT Port</label>
    <input name="mqttPort" value="1883" required>

    <label>Device ID</label>
    <input name="deviceId" placeholder="esp32-001" required>

    <label>Device Token</label>
    <input name="devToken" required>

    <button type="submit">Save & Reboot</button>
  </form>
  <p class="note">After saving, the device will reboot and connect to your WiFi network.</p>
</body>
</html>
)rawliteral";

// ── Portal request handlers ──────────────────────────────────

void _handlePortalRoot() {
  _portalServer.send(200, "text/html", PORTAL_HTML);
}

void _handlePortalSave() {
  _wolfConfig.wifiSsid    = _portalServer.arg("wifiSsid");
  _wolfConfig.wifiPassword = _portalServer.arg("wifiPass");
  _wolfConfig.serverUrl    = _portalServer.arg("serverUrl");
  _wolfConfig.mqttHost     = _portalServer.arg("mqttHost");
  _wolfConfig.mqttPort     = _portalServer.arg("mqttPort").toInt();
  _wolfConfig.deviceId     = _portalServer.arg("deviceId");
  _wolfConfig.deviceToken  = _portalServer.arg("devToken");

  // Remove trailing slash from server URL if present
  if (_wolfConfig.serverUrl.endsWith("/")) {
    _wolfConfig.serverUrl.remove(_wolfConfig.serverUrl.length() - 1);
  }

  configSave();

  _portalServer.send(200, "text/html",
    "<html><body><h1>Saved!</h1><p>Rebooting...</p></body></html>");

  delay(1500);
  ESP.restart();
}

// ── Start captive portal ─────────────────────────────────────

void configStartPortal() {
  Serial.println("[wolf] Starting setup portal...");
  ledSlowBlink();

  WiFi.mode(WIFI_AP);
  WiFi.softAP("Wolf-Setup");

  Serial.print("[wolf] AP IP: ");
  Serial.println(WiFi.softAPIP());

  _portalServer.on("/", HTTP_GET, _handlePortalRoot);
  _portalServer.on("/save", HTTP_POST, _handlePortalSave);
  // Redirect any other request to the portal root (captive portal behavior)
  _portalServer.onNotFound([]() {
    _portalServer.sendHeader("Location", "/", true);
    _portalServer.send(302, "text/plain", "");
  });

  _portalServer.begin();
  Serial.println("[wolf] Portal ready at http://192.168.4.1");
}

void configPortalLoop() {
  _portalServer.handleClient();
  ledUpdate();
}

// ── Connect to WiFi ──────────────────────────────────────────

bool configConnectWifi() {
  Serial.printf("[wolf] Connecting to WiFi: %s\n", _wolfConfig.wifiSsid.c_str());
  ledFastBlink();

  WiFi.mode(WIFI_STA);
  WiFi.begin(_wolfConfig.wifiSsid.c_str(), _wolfConfig.wifiPassword.c_str());

  int attempts = 0;
  while (WiFi.status() != WL_CONNECTED && attempts < 20) {
    delay(500);
    Serial.print(".");
    ledUpdate();
    attempts++;
  }
  Serial.println();

  if (WiFi.status() == WL_CONNECTED) {
    Serial.printf("[wolf] WiFi connected. IP: %s\n", WiFi.localIP().toString().c_str());
    return true;
  }

  Serial.println("[wolf] WiFi connection failed.");
  return false;
}

#endif
```

- [ ] **Step 2: Update `.ino` to test config module**

Replace `wolf-esp32-cam/wolf-esp32-cam.ino` with:

```cpp
#include "config.h"

bool setupMode = false;

void setup() {
  Serial.begin(115200);
  delay(1000);
  Serial.println("\n[wolf] Booting...");

  ledInit();

  if (!configLoad()) {
    Serial.println("[wolf] No config found — entering setup mode");
    setupMode = true;
    configStartPortal();
  } else {
    Serial.println("[wolf] Config loaded");
    Serial.printf("[wolf] Device ID: %s\n", configGet().deviceId.c_str());
    Serial.printf("[wolf] MQTT: %s:%d\n", configGet().mqttHost.c_str(), configGet().mqttPort);

    if (!configConnectWifi()) {
      Serial.println("[wolf] WiFi failed — entering setup mode");
      setupMode = true;
      configClear();
      configStartPortal();
    } else {
      ledSolid();
      Serial.println("[wolf] Ready (MQTT not yet implemented)");
    }
  }
}

void loop() {
  if (setupMode) {
    configPortalLoop();
  } else {
    ledUpdate();
  }
}
```

- [ ] **Step 3: Verify compilation**

Tell the user to open in Arduino IDE and click **Verify**. You cannot compile from the terminal. Expected: compiles with no errors.

- [ ] **Step 4: Notify user to commit**

Tell the user to stage: `wolf-esp32-cam/config.h` and the updated `wolf-esp32-cam/wolf-esp32-cam.ino`. Do NOT run git commands yourself.

---

### Task 3: Camera Module

**Files:**
- Create: `wolf-esp32-cam/camera.h`

Initializes the OV2640 camera using the AI-Thinker pin map and provides `takePhoto()` / `releasePhoto()`.

- [ ] **Step 1: Write `camera.h`**

Create `wolf-esp32-cam/camera.h`:

```cpp
#ifndef WOLF_CAMERA_H
#define WOLF_CAMERA_H

#include "esp_camera.h"

// ── AI-Thinker ESP32-CAM pin definitions ─────────────────────

#define PWDN_GPIO_NUM     32
#define RESET_GPIO_NUM    -1
#define XCLK_GPIO_NUM      0
#define SIOD_GPIO_NUM     26
#define SIOC_GPIO_NUM     27
#define Y9_GPIO_NUM       35
#define Y8_GPIO_NUM       34
#define Y7_GPIO_NUM       39
#define Y6_GPIO_NUM       36
#define Y5_GPIO_NUM       21
#define Y4_GPIO_NUM       19
#define Y3_GPIO_NUM       18
#define Y2_GPIO_NUM        5
#define VSYNC_GPIO_NUM    25
#define HREF_GPIO_NUM     23
#define PCLK_GPIO_NUM     22

// ── Camera init ──────────────────────────────────────────────

bool cameraInit() {
  camera_config_t config;
  config.ledc_channel = LEDC_CHANNEL_0;
  config.ledc_timer   = LEDC_TIMER_0;
  config.pin_d0       = Y2_GPIO_NUM;
  config.pin_d1       = Y3_GPIO_NUM;
  config.pin_d2       = Y4_GPIO_NUM;
  config.pin_d3       = Y5_GPIO_NUM;
  config.pin_d4       = Y6_GPIO_NUM;
  config.pin_d5       = Y7_GPIO_NUM;
  config.pin_d6       = Y8_GPIO_NUM;
  config.pin_d7       = Y9_GPIO_NUM;
  config.pin_xclk     = XCLK_GPIO_NUM;
  config.pin_pclk     = PCLK_GPIO_NUM;
  config.pin_vsync    = VSYNC_GPIO_NUM;
  config.pin_href     = HREF_GPIO_NUM;
  config.pin_sccb_sda = SIOD_GPIO_NUM;
  config.pin_sccb_scl = SIOC_GPIO_NUM;
  config.pin_pwdn     = PWDN_GPIO_NUM;
  config.pin_reset    = RESET_GPIO_NUM;
  config.xclk_freq_hz = 20000000;
  config.pixel_format = PIXFORMAT_JPEG;

  // VGA resolution (640x480) as specified in design
  config.frame_size   = FRAMESIZE_VGA;
  config.jpeg_quality = 12;  // 0-63, lower = better quality
  config.fb_count     = 1;

  esp_err_t err = esp_camera_init(&config);
  if (err != ESP_OK) {
    Serial.printf("[wolf] Camera init failed: 0x%x\n", err);
    return false;
  }

  Serial.println("[wolf] Camera initialized (VGA 640x480)");
  return true;
}

// ── Take photo ───────────────────────────────────────────────

// Returns a frame buffer pointer. Caller MUST call releasePhoto() when done.
camera_fb_t* takePhoto() {
  camera_fb_t* fb = esp_camera_fb_get();
  if (!fb) {
    Serial.println("[wolf] Camera capture failed");
    return nullptr;
  }
  Serial.printf("[wolf] Photo taken: %d bytes\n", fb->len);
  return fb;
}

void releasePhoto(camera_fb_t* fb) {
  if (fb) {
    esp_camera_fb_return(fb);
  }
}

#endif
```

- [ ] **Step 2: Update `.ino` to include camera module**

Add this include near the top of `wolf-esp32-cam.ino`, after `#include "config.h"`:

```cpp
#include "camera.h"
```

And in the `setup()` function, after the `ledSolid()` line in the WiFi-connected branch, add camera init:

```cpp
      ledSolid();

      if (!cameraInit()) {
        Serial.println("[wolf] Camera init failed — check wiring");
      }

      Serial.println("[wolf] Ready (MQTT not yet implemented)");
```

- [ ] **Step 3: Verify compilation**

Tell the user to open in Arduino IDE and click **Verify**. You cannot compile from the terminal. Expected: compiles with no errors.

- [ ] **Step 4: Notify user to commit**

Tell the user to stage: `wolf-esp32-cam/camera.h` and the updated `wolf-esp32-cam/wolf-esp32-cam.ino`. Do NOT run git commands yourself.

---

### Task 4: Upload Module

**Files:**
- Create: `wolf-esp32-cam/upload.h`

HTTP POSTs a JPEG frame buffer to the Laravel upload endpoint with device token authentication.

- [ ] **Step 1: Write `upload.h`**

Create `wolf-esp32-cam/upload.h`:

```cpp
#ifndef WOLF_UPLOAD_H
#define WOLF_UPLOAD_H

#include <HTTPClient.h>
#include "config.h"

// Upload a JPEG photo to the Laravel backend.
// URL: {serverUrl}/api/device/captures/{captureId}/upload
// Headers: Content-Type: image/jpeg, X-Device-Token: {token}
// Body: raw JPEG bytes
//
// Returns true on HTTP 2xx response.
bool uploadPhoto(uint8_t* jpegData, size_t jpegLen, int captureId) {
  WolfConfig& cfg = configGet();

  String url = cfg.serverUrl + "/api/device/captures/" + String(captureId) + "/upload";

  Serial.printf("[wolf] Uploading %d bytes to %s\n", jpegLen, url.c_str());

  HTTPClient http;
  http.begin(url);
  http.addHeader("Content-Type", "image/jpeg");
  http.addHeader("X-Device-Token", cfg.deviceToken);

  int httpCode = http.POST(jpegData, jpegLen);

  if (httpCode >= 200 && httpCode < 300) {
    Serial.printf("[wolf] Upload success (HTTP %d)\n", httpCode);
    String response = http.getString();
    Serial.printf("[wolf] Response: %s\n", response.c_str());
    http.end();
    return true;
  }

  Serial.printf("[wolf] Upload failed (HTTP %d)\n", httpCode);
  if (httpCode > 0) {
    Serial.printf("[wolf] Response: %s\n", http.getString().c_str());
  }
  http.end();
  return false;
}

#endif
```

- [ ] **Step 2: Verify compilation**

Add `#include "upload.h"` to the `.ino` after `#include "camera.h"`. Tell the user to open in Arduino IDE and click **Verify**. You cannot compile from the terminal. Expected: compiles with no errors.

- [ ] **Step 3: Notify user to commit**

Tell the user to stage: `wolf-esp32-cam/upload.h` and the updated `wolf-esp32-cam/wolf-esp32-cam.ino`. Do NOT run git commands yourself.

---

### Task 5: MQTT Module

**Files:**
- Create: `wolf-esp32-cam/mqtt.h`
- Modify: `wolf-esp32-cam/wolf-esp32-cam.ino`

Connects to the MQTT broker with LWT, subscribes to the command topic, and handles incoming capture commands by triggering camera + upload.

- [ ] **Step 1: Write `mqtt.h`**

Create `wolf-esp32-cam/mqtt.h`:

```cpp
#ifndef WOLF_MQTT_H
#define WOLF_MQTT_H

#include <PubSubClient.h>
#include <ArduinoJson.h>
#include <WiFi.h>
#include "config.h"
#include "camera.h"
#include "upload.h"
#include "led.h"

static WiFiClient _wifiClient;
static PubSubClient _mqttClient(_wifiClient);

// Reconnect tracking
static unsigned long _mqttLastAttempt = 0;
static unsigned long _mqttBackoff = 2000; // start at 2s, max 30s

// Build topic strings from device ID
static String _commandTopic;
static String _statusTopic;

// ── MQTT message callback ────────────────────────────────────

void _mqttCallback(char* topic, byte* payload, unsigned int length) {
  Serial.printf("[wolf] MQTT message on %s (%d bytes)\n", topic, length);

  // Parse JSON payload
  JsonDocument doc;
  DeserializationError err = deserializeJson(doc, payload, length);
  if (err) {
    Serial.printf("[wolf] JSON parse error: %s\n", err.c_str());
    return;
  }

  const char* action = doc["action"];
  if (!action || strcmp(action, "capture") != 0) {
    Serial.printf("[wolf] Unknown action: %s\n", action ? action : "null");
    return;
  }

  int captureId = doc["capture_id"] | 0;
  if (captureId == 0) {
    Serial.println("[wolf] Missing capture_id");
    return;
  }

  Serial.printf("[wolf] Capture command received (id: %d)\n", captureId);

  // Take photo
  camera_fb_t* fb = takePhoto();
  if (!fb) {
    Serial.println("[wolf] Capture failed — no frame buffer");
    return;
  }

  // Upload to Laravel
  bool success = uploadPhoto(fb->buf, fb->len, captureId);

  // Release frame buffer
  releasePhoto(fb);

  // Visual feedback
  if (success) {
    ledFlashCapture();
    Serial.println("[wolf] Capture complete");
  } else {
    Serial.println("[wolf] Capture failed — upload error");
  }
}

// ── Connect to MQTT broker ───────────────────────────────────

bool mqttConnect() {
  WolfConfig& cfg = configGet();

  // Build topic strings
  _commandTopic = "wolf/" + cfg.deviceId + "/command";
  _statusTopic  = "wolf/" + cfg.deviceId + "/status";

  String clientId = "wolf-" + cfg.deviceId;

  _mqttClient.setServer(cfg.mqttHost.c_str(), cfg.mqttPort);
  _mqttClient.setCallback(_mqttCallback);

  // PubSubClient default buffer is 256 bytes — increase for JSON payloads
  _mqttClient.setBufferSize(512);

  Serial.printf("[wolf] Connecting to MQTT %s:%d as %s\n",
    cfg.mqttHost.c_str(), cfg.mqttPort, clientId.c_str());

  // Connect with LWT: if we disconnect unexpectedly, broker publishes "offline"
  bool connected = _mqttClient.connect(
    clientId.c_str(),           // client ID
    nullptr,                     // username (none for now)
    nullptr,                     // password (none for now)
    _statusTopic.c_str(),       // will topic
    1,                           // will QoS
    true,                        // will retain
    "offline"                    // will message
  );

  if (!connected) {
    Serial.printf("[wolf] MQTT connect failed, rc=%d\n", _mqttClient.state());
    return false;
  }

  // Publish online status (retained)
  _mqttClient.publish(_statusTopic.c_str(), "online", true);
  Serial.println("[wolf] Published online status");

  // Subscribe to command topic (QoS 1)
  _mqttClient.subscribe(_commandTopic.c_str(), 1);
  Serial.printf("[wolf] Subscribed to %s\n", _commandTopic.c_str());

  // Reset backoff on successful connection
  _mqttBackoff = 2000;

  return true;
}

// ── Reconnect with exponential backoff ───────────────────────

void mqttReconnect() {
  if (_mqttClient.connected()) return;

  unsigned long now = millis();
  if (now - _mqttLastAttempt < _mqttBackoff) return;

  _mqttLastAttempt = now;
  ledFastBlink();

  Serial.printf("[wolf] MQTT reconnecting (backoff: %lums)\n", _mqttBackoff);

  if (mqttConnect()) {
    ledSolid();
  } else {
    // Exponential backoff: 2s, 4s, 8s, 16s, 30s max
    _mqttBackoff = min(_mqttBackoff * 2, (unsigned long)30000);
  }
}

// ── Call in loop() ───────────────────────────────────────────

void mqttLoop() {
  if (!_mqttClient.connected()) {
    mqttReconnect();
  }
  _mqttClient.loop();
}

#endif
```

- [ ] **Step 2: Verify compilation**

Add `#include "mqtt.h"` to the `.ino` after `#include "upload.h"`. Tell the user to open in Arduino IDE and click **Verify**. You cannot compile from the terminal. Expected: compiles with no errors.

- [ ] **Step 3: Notify user to commit**

Tell the user to stage: `wolf-esp32-cam/mqtt.h` and the updated `wolf-esp32-cam/wolf-esp32-cam.ino`. Do NOT run git commands yourself.

---

### Task 6: Main Sketch — Wire Everything Together

**Files:**
- Modify: `wolf-esp32-cam/wolf-esp32-cam.ino`

Replace the test `.ino` with the final version that orchestrates all modules: config → WiFi → camera → MQTT → loop.

- [ ] **Step 1: Write the final `wolf-esp32-cam.ino`**

Replace the entire contents of `wolf-esp32-cam/wolf-esp32-cam.ino` with:

```cpp
/*
 * Wolf ESP32-CAM Firmware
 *
 * Modes:
 *   1. Setup — captive portal at 192.168.4.1 for entering WiFi/MQTT/server config
 *   2. Normal — connects to WiFi + MQTT, waits for capture commands
 *   3. Capture — takes photo, uploads to Laravel backend via HTTP POST
 *
 * Backend: Wolf Laravel app (github.com/...)
 * Board: AI-Thinker ESP32-CAM (OV2640)
 *
 * Libraries: PubSubClient, ArduinoJson (install from Library Manager)
 */

#include "config.h"
#include "camera.h"
#include "upload.h"
#include "mqtt.h"

// GPIO 0 button — hold during boot to force setup mode
#define SETUP_BUTTON_PIN 0

bool setupMode = false;

void setup() {
  Serial.begin(115200);
  delay(1000);
  Serial.println("\n=============================");
  Serial.println("  Wolf ESP32-CAM v1.0");
  Serial.println("=============================");

  ledInit();

  // Check if GPIO 0 button is held at boot — force setup mode
  pinMode(SETUP_BUTTON_PIN, INPUT_PULLUP);
  if (digitalRead(SETUP_BUTTON_PIN) == LOW) {
    Serial.println("[wolf] Setup button held — clearing config");
    configClear();
  }

  // Load config from NVS
  if (!configLoad()) {
    Serial.println("[wolf] No config found — entering setup mode");
    setupMode = true;
    configStartPortal();
    return;
  }

  Serial.printf("[wolf] Config loaded for device: %s\n", configGet().deviceId.c_str());

  // Connect to WiFi
  if (!configConnectWifi()) {
    Serial.println("[wolf] WiFi failed — entering setup mode");
    setupMode = true;
    configClear();
    configStartPortal();
    return;
  }

  // Initialize camera
  if (!cameraInit()) {
    Serial.println("[wolf] Camera init failed — check hardware");
    // Continue anyway — MQTT will connect, captures will fail gracefully
  }

  // Connect to MQTT
  if (mqttConnect()) {
    ledSolid();
    Serial.println("[wolf] Ready — waiting for capture commands");
  } else {
    ledFastBlink();
    Serial.println("[wolf] MQTT connect failed — will retry in loop");
  }
}

void loop() {
  if (setupMode) {
    configPortalLoop();
    return;
  }

  // Check WiFi — if lost, reconnect
  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("[wolf] WiFi lost — reconnecting");
    ledFastBlink();
    configConnectWifi();
  }

  // Keep MQTT alive and handle reconnection
  mqttLoop();

  // Update LED blink pattern
  ledUpdate();
}
```

- [ ] **Step 2: Verify compilation**

Tell the user to open in Arduino IDE and click **Verify**. You cannot compile from the terminal. Expected: compiles with no errors and no warnings about missing includes.

Expected output in the Arduino IDE console (approximate):
```
Sketch uses XXXXX bytes (XX%) of program storage space.
Global variables use XXXXX bytes (XX%) of dynamic memory.
```

- [ ] **Step 3: Notify user to commit**

Tell the user to stage the updated `wolf-esp32-cam/wolf-esp32-cam.ino`. Do NOT run git commands yourself.

---

### Task 7: Flash & End-to-End Test

**Files:** None (testing only)

This task tests the firmware on actual hardware against the Wolf Laravel backend.

**Prerequisites:**
- ESP32-CAM wired to Arduino Uno for flashing
- Mosquitto MQTT broker installed and running locally (`brew install mosquitto && brew services start mosquitto`)
- Wolf Laravel app running locally (`php artisan serve` at `http://localhost:8000`)
- A device registered in the Wolf admin UI (note the `device_id` and `device_token`)
- `DEVICE_DRIVER=esp32_mqtt` in Wolf's `.env`
- `MQTT_HOST=127.0.0.1` and `MQTT_PORT=1883` in Wolf's `.env`

- [ ] **Step 1: Flash the firmware**

1. Wire ESP32-CAM to Arduino Uno:
   - ESP32 GND → Uno GND
   - ESP32 5V → Uno 5V
   - ESP32 U0R (GPIO 3) → Uno RX
   - ESP32 U0T (GPIO 1) → Uno TX
   - ESP32 GPIO 0 → Uno GND (enables flash mode)
   - Uno RESET → Uno GND (disables Uno's MCU so it acts as passthrough)

2. In Arduino IDE:
   - Select port (the Uno's serial port)
   - Click **Upload**
   - When console shows "Connecting...", press the ESP32 RST button

3. After upload completes:
   - Disconnect GPIO 0 from GND
   - Press RST button to reboot normally

4. Open Serial Monitor at 115200 baud. You should see:
```
=============================
  Wolf ESP32-CAM v1.0
=============================
[wolf] No config found — entering setup mode
[wolf] Starting setup portal...
[wolf] AP IP: 192.168.4.1
[wolf] Portal ready at http://192.168.4.1
```

- [ ] **Step 2: Configure via captive portal**

1. On your phone or laptop, connect to WiFi network `Wolf-Setup`
2. Open browser to `http://192.168.4.1`
3. Fill in the form:
   - WiFi SSID: your home WiFi name
   - WiFi Password: your home WiFi password
   - Server URL: `http://<your-mac-ip>:8000` (e.g. `http://192.168.1.50:8000`)
   - MQTT Host: `<your-mac-ip>` (e.g. `192.168.1.50`)
   - MQTT Port: `1883`
   - Device ID: the device_id from Wolf admin (e.g. `esp32-001`)
   - Device Token: the plain-text token shown when you created the device
4. Click "Save & Reboot"

Serial Monitor should show:
```
[wolf] Booting...
[wolf] Config loaded for device: esp32-001
[wolf] Connecting to WiFi: YourNetwork
.....
[wolf] WiFi connected. IP: 192.168.1.XXX
[wolf] Camera initialized (VGA 640x480)
[wolf] Connecting to MQTT 192.168.1.50:1883 as wolf-esp32-001
[wolf] Published online status
[wolf] Subscribed to wolf/esp32-001/command
[wolf] Ready — waiting for capture commands
```

- [ ] **Step 3: Verify MQTT status message**

In a separate terminal, subscribe to the status topic to confirm the device published "online":

```bash
mosquitto_sub -h localhost -t "wolf/esp32-001/status" -v
```

Expected output:
```
wolf/esp32-001/status online
```

- [ ] **Step 4: Test the full capture flow**

1. Make sure these are running:
   - `php artisan serve` (Laravel)
   - `php artisan reverb:start` (WebSocket)
   - `npm run dev` (Vite)
   - Mosquitto broker

2. Open the Wolf app in browser, go to Dashboard
3. Click the **Capture** button
4. Watch the Serial Monitor — you should see:

```
[wolf] MQTT message on wolf/esp32-001/command (XX bytes)
[wolf] Capture command received (id: XX)
[wolf] Photo taken: XXXXX bytes
[wolf] Uploading XXXXX bytes to http://192.168.1.50:8000/api/device/captures/XX/upload
[wolf] Upload success (HTTP 200)
[wolf] Capture complete
```

5. The photo should appear on the Dashboard in the browser (pushed via Reverb WebSocket).

- [ ] **Step 5: Test setup button**

1. Hold GPIO 0 button while pressing RST
2. Serial Monitor should show "Setup button held — clearing config"
3. Device should enter setup mode again with `Wolf-Setup` AP

- [ ] **Step 6: Notify user to commit (if any adjustments were needed)**

Tell the user to stage any files that were modified during testing. Do NOT run git commands yourself.

---

## Summary

| Task | What it builds | Files |
|---|---|---|
| 1 | LED indicators | `led.h`, scaffold `.ino` |
| 2 | Config + captive portal | `config.h`, update `.ino` |
| 3 | Camera init + photo capture | `camera.h`, update `.ino` |
| 4 | HTTP POST upload | `upload.h`, update `.ino` |
| 5 | MQTT client + command handler | `mqtt.h`, update `.ino` |
| 6 | Final `.ino` wiring everything | `wolf-esp32-cam.ino` (final) |
| 7 | Flash to hardware + E2E test | No files — testing only |
