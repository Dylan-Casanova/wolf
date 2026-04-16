# Reverb WebSocket Activation — Design Spec

## Overview

Activate Laravel Reverb for real-time capture result delivery to the browser. Most of the infrastructure is already in place — this spec covers the configuration changes and production process management needed to make it functional.

## What Changes

### `.env` (local development)

Switch broadcasting driver and uncomment Reverb frontend variables:

```ini
BROADCAST_CONNECTION=reverb

VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"
```

### `.env.example`

Uncomment the same `VITE_REVERB_*` vars so future developers know they are required.

### `routes/channels.php`

Add private channel authorization for `user.{id}`:

```php
Broadcast::channel('user.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});
```

Without this, `Echo.private('user.{id}')` returns 403 and the listener never fires.

### Supervisor config (production)

File: `/etc/supervisor/conf.d/wolf-reverb.conf`

```ini
[program:wolf-reverb]
command=php /var/www/wolf/artisan reverb:start --host=0.0.0.0 --port=8080
directory=/var/www/wolf
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/wolf/storage/logs/reverb.log
```

After deploying:

```bash
supervisorctl reread
supervisorctl update
supervisorctl start wolf-reverb
```

## What Stays the Same

| File | Status |
|------|--------|
| `app/Events/CaptureReady.php` | Already implements `ShouldBroadcast`, broadcasts on `user.{id}` ✓ |
| `app/Services/Device/CaptureService.php` | Already calls `broadcast(new CaptureReady(...))` ✓ |
| `resources/js/bootstrap.ts` | Echo/Reverb already initialized from `VITE_REVERB_*` vars ✓ |
| `resources/js/Pages/Dashboard.tsx` | Already listens on `user.{userId}` for `.CaptureReady` ✓ |

## Local Dev Workflow

```bash
# Terminal 1 — Laravel app
php artisan serve

# Terminal 2 — Reverb WebSocket server
php artisan reverb:start
```

## Data Flow (End to End)

1. User clicks Capture → `CaptureService::trigger()` dispatches MQTT command to ESP32
2. ESP32 uploads media → `DeviceCaptureController::upload()` → `CaptureService::finalise()`
3. `finalise()` calls `broadcast(new CaptureReady($capture->fresh()))`
4. Reverb server pushes event to private channel `user.{id}`
5. `Dashboard.tsx` listener receives `.CaptureReady` → updates `MediaDisplay` with result

## Scope

**In scope:**
- Env var activation (local + example)
- Channel authorization
- Supervisor config for production

**Out of scope:**
- TLS/WSS setup (handled at nginx level in production)
- Reverb scaling / horizontal deployment
- Mock driver fallback (already works via flash props, unchanged)
