# How to Test Wolf Locally

## Prerequisites

Install the following before starting:

| Tool | Install | Verify |
|---|---|---|
| PHP 8.2+ | `brew install php` | `php -v` |
| Composer | `brew install composer` | `composer -V` |
| MySQL 8+ | `brew install mysql && brew services start mysql` | `mysql -u root -e "SELECT 1"` |
| Node 20 | `nvm install 20 && nvm use 20` | `node -v` |
| Mosquitto | `brew install mosquitto && brew services start mosquitto` | `mosquitto_sub -h localhost -t test -E` |

## First-Time Setup

```bash
cd ~/Code/wolf

# Install PHP dependencies
composer install

# Install JS dependencies
npm install

# Copy environment file (if not already done)
cp .env.example .env
php artisan key:generate

# Create the database
mysql -u root -e "CREATE DATABASE IF NOT EXISTS wolf"

# Run migrations
php artisan migrate

# Create the storage symlink (serves uploaded captures)
php artisan storage:link
```

### Create an Admin User

```bash
php artisan tinker
```

```php
$user = \App\Models\User::factory()->create([
    'name'     => 'Admin',
    'email'    => 'admin@wolf.test',
    'password' => bcrypt('password'),
    'is_admin' => true,
]);
```

### Register a Device (via admin UI or tinker)

In tinker:
```php
$device = \App\Models\Device::create([
    'user_id'   => 1,
    'name'      => 'Living Room Cam',
    'device_id' => 'Esp32-001',
    'token_hash' => '',
    'type'       => 'esp32-cam',
]);
$token = $device->generateToken();
echo "Device token: $token\n";
```

Or log in as admin at `http://localhost:8000`, go to Devices, and create one through the UI.

---

## Running the App

You need **4 terminal tabs** running simultaneously:

### Tab 1: Laravel Server
```bash
cd ~/Code/wolf
php artisan serve --host=0.0.0.0 --port=8000
```
App is at: `http://localhost:8000`

### Tab 2: Vite Dev Server (frontend hot reload)
```bash
cd ~/Code/wolf
npm run dev
```

### Tab 3: Reverb WebSocket Server
```bash
cd ~/Code/wolf
php artisan reverb:start
```
WebSocket runs at: `ws://localhost:8080`

### Tab 4: Queue Worker (processes broadcast events)
```bash
cd ~/Code/wolf
php artisan queue:work
```

### Mosquitto
Should already be running as a brew service. Verify:
```bash
brew services list | grep mosquitto
```

---

## Two Testing Modes

### Mode A: Mock (No Hardware)

For testing the UI without an ESP32 camera.

Set in `.env`:
```
DEVICE_DRIVER=mock
```

Restart the Laravel server after changing this.

**How it works:** When you click Capture on the dashboard, the mock driver simulates an instant photo with a placeholder image. No MQTT or ESP32 involved.

### Mode B: ESP32 (Real Hardware)

For testing the full pipeline with a physical ESP32-CAM.

Set in `.env`:
```
DEVICE_DRIVER=esp32_mqtt
MQTT_HOST=127.0.0.1
MQTT_PORT=1883
```

**Requirements:**
- ESP32-CAM flashed with the wolf-esp32-cam firmware
- ESP32 connected to the same WiFi network as your Mac
- Mosquitto running locally
- All 4 terminal tabs running

---

## Testing the Full Capture Flow (Mode B)

1. Open `http://localhost:8000` in Chrome
2. Log in as admin
3. Go to Dashboard
4. Open DevTools (Cmd+Option+I) and check:
   - **Network > WS tab**: you should see an active WebSocket connection to `ws://localhost:8080`
   - **Console tab**: no errors
5. Click the **Capture** button
6. Watch the terminal tabs:
   - **Tab 4 (queue worker)**: should show `CaptureReady ... DONE`
7. The photo should appear on the dashboard in real-time (no page refresh)

### If the photo doesn't appear:
- Check Tab 3 (Reverb) is running
- Check Tab 4 (queue worker) is running and processed the job
- Check the WebSocket connection in DevTools Network > WS tab
- Check `http://localhost:8000/telescope` for errors (requests, events, jobs)

---

## Testing the Provisioning Endpoint

The ESP32 calls this during first-time setup to get its config:

```bash
# Should return device config JSON
curl http://localhost:8000/api/device/Esp32-001/provision

# Should return 404
curl http://localhost:8000/api/device/does-not-exist/provision
```

---

## Monitoring with MQTT

Watch MQTT messages in real time:

```bash
# See all wolf messages
mosquitto_sub -h localhost -t "wolf/#" -v

# See commands sent to a specific device
mosquitto_sub -h localhost -t "wolf/Esp32-001/command" -v

# See device status (online/offline)
mosquitto_sub -h localhost -t "wolf/Esp32-001/status" -v

# Manually send a capture command (for testing without the browser)
mosquitto_pub -h localhost -t "wolf/Esp32-001/command" -m '{"action":"capture","capture_id":1,"type":"image"}'
```

---

## Running the Test Suite

```bash
cd ~/Code/wolf
php artisan test
```

Expected: **38 tests, 117 assertions, all passing.**

Run a specific test file:
```bash
php artisan test --filter=CaptureHistoryTest
php artisan test --filter=DeviceManagementTest
```

---

## Debugging with Telescope

Visit `http://localhost:8000/telescope` (local only) to inspect:

- **Requests**: every HTTP request and response
- **Exceptions**: any errors thrown
- **Jobs**: queued broadcast events (CaptureReady)
- **Events**: all events fired
- **Queries**: every database query

---

## Quick Reference

| URL | What |
|---|---|
| `http://localhost:8000` | Wolf app |
| `http://localhost:8000/dashboard` | Capture button + live photo |
| `http://localhost:8000/captures` | Capture history |
| `http://localhost:8000/devices` | Device management (admin only) |
| `http://localhost:8000/telescope` | Debug dashboard |
| `ws://localhost:8080` | Reverb WebSocket |
| `localhost:1883` | Mosquitto MQTT broker |

| Command | What |
|---|---|
| `php artisan serve --host=0.0.0.0 --port=8000` | Laravel server |
| `npm run dev` | Vite dev server |
| `php artisan reverb:start` | WebSocket server |
| `php artisan queue:work` | Queue worker |
| `php artisan test` | Run test suite |
| `php artisan tinker` | Interactive PHP shell |
