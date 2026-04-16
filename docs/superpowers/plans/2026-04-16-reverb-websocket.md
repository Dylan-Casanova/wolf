# Reverb WebSocket Activation — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Activate Laravel Reverb so capture results are delivered to the browser in real-time via WebSocket.

**Architecture:** Three small changes — add channel authorization to `channels.php`, update `.env` and `.env.example` to switch from the log driver to Reverb and expose frontend vars, and add a Supervisor config for production. No application logic changes needed; the event, listener, and frontend Echo setup are already in place.

**Tech Stack:** Laravel Reverb, Laravel Echo, pusher-js, Supervisor (production)

---

## File Map

| Action | File | Responsibility |
|--------|------|----------------|
| Modify | `routes/channels.php` | Add `user.{id}` private channel authorization |
| Modify | `.env` | Switch `BROADCAST_CONNECTION` to `reverb`, uncomment `VITE_REVERB_*` vars |
| Modify | `.env.example` | Uncomment `VITE_REVERB_*` vars for future developers |
| Create | `docs/supervisor/wolf-reverb.conf` | Supervisor config for production process management |

---

### Task 1: Authorize the Private Channel

**Files:**
- Modify: `routes/channels.php`

- [ ] **Step 1: Add `user.{id}` channel authorization**

The `CaptureReady` event broadcasts on `PrivateChannel('user.{id}')`. Without this authorization callback, `Echo.private('user.1')` returns a 403 and the Dashboard listener never fires.

Replace the full contents of `routes/channels.php`:

```php
<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('user.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});
```

- [ ] **Step 2: Run the full test suite to confirm nothing broke**

Run: `cd /Users/mr.casanova/Code/wolf && php artisan test`
Expected: 33 tests pass.

---

### Task 2: Activate Reverb in `.env`

**Files:**
- Modify: `.env`

- [ ] **Step 1: Switch the broadcasting driver**

Find this line in `.env`:
```ini
BROADCAST_CONNECTION=log
```
Change it to:
```ini
BROADCAST_CONNECTION=reverb
```

- [ ] **Step 2: Uncomment the frontend Reverb variables**

Find these commented-out lines in `.env` (near the bottom, after the `REVERB_*` server vars):
```ini
#VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
#VITE_REVERB_HOST="${REVERB_HOST}"
#VITE_REVERB_PORT="${REVERB_PORT}"
#VITE_REVERB_SCHEME="${REVERB_SCHEME}"
```
Uncomment them so they read:
```ini
VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"
```

- [ ] **Step 3: Verify the Reverb server vars are set**

Confirm these lines already exist (they should from the initial scaffold):
```ini
REVERB_APP_ID=wolf-local
REVERB_APP_KEY=wolf-local-key
REVERB_APP_SECRET=wolf-local-secret
REVERB_HOST=localhost
REVERB_PORT=8080
REVERB_SCHEME=http
```

---

### Task 3: Update `.env.example`

**Files:**
- Modify: `.env.example`

- [ ] **Step 1: Uncomment the VITE_REVERB vars in .env.example**

Find the same commented-out block in `.env.example` and uncomment it:
```ini
VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"
```

---

### Task 4: Supervisor Config for Production

**Files:**
- Create: `docs/supervisor/wolf-reverb.conf`

- [ ] **Step 1: Create the Supervisor config file**

```bash
mkdir -p /Users/mr.casanova/Code/wolf/docs/supervisor
```

Create `docs/supervisor/wolf-reverb.conf`:

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

- [ ] **Step 2: Add deployment instructions as a comment at the top of the file**

```ini
; Deploy instructions:
; 1. Copy this file to /etc/supervisor/conf.d/wolf-reverb.conf on the VPS
; 2. Run: supervisorctl reread
; 3. Run: supervisorctl update
; 4. Run: supervisorctl start wolf-reverb
; 5. Check status: supervisorctl status wolf-reverb
;
; Adjust 'command' path and 'user' to match your VPS setup.

[program:wolf-reverb]
command=php /var/www/wolf/artisan reverb:start --host=0.0.0.0 --port=8080
directory=/var/www/wolf
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/wolf/storage/logs/reverb.log
```

---

### Task 5: Smoke Test Locally

- [ ] **Step 1: Start the Reverb server in a separate terminal**

```bash
cd /Users/mr.casanova/Code/wolf && php artisan reverb:start
```
Expected output: `Starting server on 0.0.0.0:8080`

- [ ] **Step 2: Start the dev server in another terminal**

```bash
cd /Users/mr.casanova/Code/wolf && nvm use v20.19.3 && npm run dev
```

- [ ] **Step 3: Start the Laravel app**

```bash
cd /Users/mr.casanova/Code/wolf && php artisan serve
```

- [ ] **Step 4: Manually verify**

1. Log in at `http://localhost:8000`
2. Open browser DevTools → Network → WS tab
3. Click the Capture button on the Dashboard
4. Confirm a WebSocket connection appears to `ws://localhost:8080`
5. Confirm the capture result appears on the Dashboard without a page reload (real-time update)

---
