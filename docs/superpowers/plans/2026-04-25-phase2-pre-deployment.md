# Phase 2: Pre-Deployment Hardening — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Prepare the Docker setup for production deployment on a VPS — production compose overrides, SSL/TLS with Caddy, MQTT authentication, and environment hardening.

**Architecture:** A production compose override file (`docker-compose.prod.yml`) layers on top of the dev compose. Caddy handles auto-HTTPS and reverse-proxies to nginx (HTTP) and reverb (WSS). Mosquitto gets password auth + ACL. A `.env.production` template documents every secret that must be set before deploy.

**Tech Stack:** Docker Compose, Caddy 2, Eclipse Mosquitto 2, Laravel 13

**Constraints:**
- Do NOT run `git add`, `git commit`, or `git push` — the user handles all git operations manually.
- All code changes happen in `/Users/mr.casanova/Code/wolf`.
- Run tests inside Docker: `docker compose exec app php artisan test`
- The ESP32 firmware is at `/Users/mr.casanova/Documents/arduino/wolf-esp32-cam/` — do NOT modify it. Note what firmware changes are needed in a comment.

---

## File Map

| File | Action | Responsibility |
|---|---|---|
| `docker-compose.prod.yml` | Create | Production overrides — prod target, no vite, no exposed DB/Redis ports, Caddy service |
| `docker/caddy/Caddyfile` | Create | Caddy reverse proxy config — HTTPS for web, WSS for Reverb |
| `docker/mosquitto/mosquitto.prod.conf` | Create | Production Mosquitto config — auth required, no anonymous |
| `docker/mosquitto/acl` | Create | MQTT topic access control — who can publish/subscribe to what |
| `.env.production` | Create | Production env template with placeholder values and documentation |
| `.gitignore` | Modify | Add `docker/mosquitto/passwd` to prevent committing secrets |

---

### Task 1: Production Docker Compose Override

**Files:**
- Create: `docker-compose.prod.yml`

This file overrides the dev compose for production. Usage: `docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d`

- [ ] **Step 1: Create the production override file**

Create `docker-compose.prod.yml`:

```yaml
# =============================================================================
# Wolf — Docker Compose Production Overrides
# =============================================================================
# Usage:
#   docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d
#
# This file overrides the dev compose with production settings:
#   - Uses production Dockerfile stage (no Xdebug, optimized autoloader, built assets)
#   - Removes vite service (assets baked into image)
#   - Removes exposed ports for MySQL, Redis (internal only)
#   - Adds Caddy for auto-HTTPS
#   - Uses production Mosquitto config (auth required)
# =============================================================================

services:

  # Override app to use production image
  app:
    build:
      target: production
    volumes:
      - storage_data:/var/www/storage/app
    environment:
      - PHP_IDE_CONFIG=

  # Override nginx — remove host port, Caddy handles public traffic
  nginx:
    ports: !override []

  # Remove vite entirely — assets are built into the production image
  vite:
    profiles:
      - disabled

  # Override reverb — remove host port, Caddy handles WSS
  reverb:
    build:
      target: production
    ports: !override []
    volumes:
      - storage_data:/var/www/storage/app

  # Override queue to use production image
  queue:
    build:
      target: production
    volumes:
      - storage_data:/var/www/storage/app

  # Override MySQL — remove exposed port
  mysql:
    ports: !override []

  # Override Redis — remove exposed port
  redis:
    ports: !override []

  # Override Mosquitto — use production config with auth
  mosquitto:
    ports: !override
      - "1883:1883"
    volumes:
      - ./docker/mosquitto/mosquitto.prod.conf:/mosquitto/config/mosquitto.conf:ro
      - ./docker/mosquitto/passwd:/mosquitto/config/passwd:ro
      - ./docker/mosquitto/acl:/mosquitto/config/acl:ro
      - mosquitto_data:/mosquitto/data
      - mosquitto_log:/mosquitto/log

  # ---------------------------------------------------------------------------
  # Caddy: Reverse Proxy + Auto-HTTPS
  # ---------------------------------------------------------------------------
  caddy:
    restart: unless-stopped
    image: caddy:2-alpine
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - ./docker/caddy/Caddyfile:/etc/caddy/Caddyfile:ro
      - caddy_data:/data
      - caddy_certs:/config
    depends_on:
      nginx:
        condition: service_healthy
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"
    networks:
      - wolf

volumes:
  caddy_data:
  caddy_certs:
```

- [ ] **Step 2: Verify the file is valid YAML**

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml config --quiet
```

Expected: No output (valid config). Note: this may warn about missing files like `passwd` — that's expected until Task 3.

---

### Task 2: Caddy Reverse Proxy

**Files:**
- Create: `docker/caddy/Caddyfile`

Caddy auto-provisions Let's Encrypt certificates. It reverse-proxies HTTPS to nginx and WSS to reverb.

- [ ] **Step 1: Create the Caddy config directory**

```bash
mkdir -p docker/caddy
```

- [ ] **Step 2: Create the Caddyfile**

Create `docker/caddy/Caddyfile`:

```
{$DOMAIN:localhost} {
    # Web app — proxy to nginx
    reverse_proxy nginx:80

    # WebSocket — proxy /app/* to Reverb
    @websocket {
        path /app/*
    }
    reverse_proxy @websocket reverb:8080
}
```

The `{$DOMAIN:localhost}` syntax reads from the `DOMAIN` environment variable. In production, set `DOMAIN=wolf.yourdomain.com` in `.env`. Caddy will automatically provision HTTPS for that domain. Locally it defaults to `localhost` (self-signed).

---

### Task 3: Mosquitto Authentication

**Files:**
- Create: `docker/mosquitto/mosquitto.prod.conf`
- Create: `docker/mosquitto/acl`
- Modify: `.gitignore`

- [ ] **Step 1: Create the production Mosquitto config**

Create `docker/mosquitto/mosquitto.prod.conf`:

```
listener 1883
allow_anonymous false
password_file /mosquitto/config/passwd
acl_file /mosquitto/config/acl
persistence true
persistence_location /mosquitto/data/
log_dest stdout
```

- [ ] **Step 2: Create the ACL file**

Create `docker/mosquitto/acl`:

```
# Wolf MQTT Access Control
#
# wolf_server: the Laravel backend — publishes commands, subscribes to status
# wolf_device: the ESP32 devices — subscribe to commands, publish status

# Server can publish commands and read status for all devices
user wolf_server
topic write wolf/+/command
topic read wolf/+/status

# Devices can read their own commands and publish their own status
# Pattern %u matches the MQTT username, but devices use a shared user.
# Use pattern %c (client ID) since devices connect as wolf-{deviceId}
pattern read wolf/%c/command
pattern write wolf/%c/status
```

**Note:** The ACL pattern `%c` matches the MQTT client ID. The ESP32 firmware connects with client ID `wolf-{deviceId}` (see `mqtt.h:81`). This means the ACL pattern `wolf/%c/command` would expand to `wolf/wolf-Esp32-001/command` which doesn't match the actual topic `wolf/Esp32-001/command`. 

To keep things simple for V1, we use named users (`wolf_server` and `wolf_device`) with explicit topic permissions instead of patterns. Update the ACL to:

```
# Wolf MQTT Access Control

# Server can publish commands and read status for all devices
user wolf_server
topic write wolf/+/command
topic read wolf/+/status

# Device user can read commands and publish status for all devices
# (Each physical device authenticates as wolf_device)
user wolf_device
topic read wolf/+/command
topic write wolf/+/status
```

- [ ] **Step 3: Generate the Mosquitto password file**

This file should NOT be committed to git. Generate it on the machine where you deploy:

```bash
# Create the password file with two users
docker run --rm -v "$(pwd)/docker/mosquitto:/mosquitto/config" eclipse-mosquitto:2 \
    mosquitto_passwd -b -c /mosquitto/config/passwd wolf_server <YOUR_SERVER_PASSWORD>

docker run --rm -v "$(pwd)/docker/mosquitto:/mosquitto/config" eclipse-mosquitto:2 \
    mosquitto_passwd -b /mosquitto/config/passwd wolf_device <YOUR_DEVICE_PASSWORD>
```

Replace `<YOUR_SERVER_PASSWORD>` and `<YOUR_DEVICE_PASSWORD>` with strong random passwords.

- [ ] **Step 4: Add the password file to .gitignore**

Add this line to `.gitignore`:

```
docker/mosquitto/passwd
```

- [ ] **Step 5: Verify dev Mosquitto is unaffected**

The dev compose still uses `docker/mosquitto/mosquitto.conf` (anonymous access). Only the prod override switches to `mosquitto.prod.conf`. Dev workflow is unchanged.

**ESP32 firmware note:** The ESP32 firmware (`mqtt.h:93-94`) currently connects with `nullptr` for username/password. In production, the firmware needs to be updated to pass `wolf_device` credentials. The provisioning endpoint (`DeviceProvisionController`) should also return MQTT credentials in the response. These changes are NOT part of this plan — they will be done when deploying to production.

---

### Task 4: Environment Hardening

**Files:**
- Create: `.env.production`

- [ ] **Step 1: Create the production env template**

Create `.env.production`:

```bash
# =============================================================================
# Wolf — Production Environment Template
# =============================================================================
# Copy this to .env on your VPS and fill in every value marked CHANGE_ME.
# Never commit the filled-in .env file to git.
# =============================================================================

APP_NAME=Wolf
APP_ENV=production
APP_KEY=                          # Run: php artisan key:generate
APP_DEBUG=false                   # NEVER true in production
APP_URL=https://CHANGE_ME         # e.g. https://wolf.yourdomain.com

APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_US

APP_MAINTENANCE_DRIVER=file

BCRYPT_ROUNDS=12

LOG_CHANNEL=stack
LOG_STACK=single
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=warning                 # Less verbose than dev

# --- Domain (used by Caddy for auto-HTTPS) ---
DOMAIN=CHANGE_ME                  # e.g. wolf.yourdomain.com

# --- Database ---
DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=wolf
DB_USERNAME=wolf
DB_PASSWORD=CHANGE_ME             # Strong random password

MYSQL_ROOT_PASSWORD=CHANGE_ME     # Strong random password

# --- Sessions / Cache / Queues ---
SESSION_DRIVER=redis
SESSION_LIFETIME=120
SESSION_ENCRYPT=true              # Encrypt sessions in production
SESSION_PATH=/
SESSION_DOMAIN=CHANGE_ME          # e.g. wolf.yourdomain.com

BROADCAST_CONNECTION=reverb
FILESYSTEM_DISK=local
QUEUE_CONNECTION=redis

CACHE_STORE=redis

REDIS_CLIENT=phpredis
REDIS_HOST=redis
REDIS_PASSWORD=CHANGE_ME          # Set a Redis password
REDIS_PORT=6379

# --- MQTT ---
DEVICE_DRIVER=esp32_mqtt
MQTT_HOST=mosquitto
MQTT_PORT=1883
MQTT_USERNAME=wolf_server
MQTT_PASSWORD=CHANGE_ME           # Must match mosquitto passwd file

# --- Reverb WebSocket ---
REVERB_APP_ID=CHANGE_ME           # Random string
REVERB_APP_KEY=CHANGE_ME          # Random string
REVERB_APP_SECRET=CHANGE_ME       # Random string
REVERB_HOST=reverb
REVERB_PORT=8080
REVERB_SCHEME=https

VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST=CHANGE_ME        # Same as DOMAIN
VITE_REVERB_PORT=443
VITE_REVERB_SCHEME=https

VITE_APP_NAME="${APP_NAME}"

# --- Mail ---
MAIL_MAILER=smtp
MAIL_HOST=CHANGE_ME               # e.g. smtp.mailgun.org
MAIL_PORT=587
MAIL_USERNAME=CHANGE_ME
MAIL_PASSWORD=CHANGE_ME
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=CHANGE_ME       # e.g. noreply@wolf.yourdomain.com
MAIL_FROM_NAME="${APP_NAME}"

# --- Telescope ---
TELESCOPE_ENABLED=false            # Disable in production
```

- [ ] **Step 2: Add .env.production to .gitignore check**

`.env.production` is a TEMPLATE with placeholder values — it IS safe to commit. Only the filled-in `.env` file must never be committed. Verify `.env` is already in `.gitignore`:

```bash
grep "^\.env$" .gitignore
```

Expected: `.env` appears in the output.

---

## Final Verification

After all 4 tasks are complete:

- [ ] **Verify dev environment still works unchanged**

```bash
docker compose up -d
docker compose exec app php artisan test
```

Expected: All 40 tests pass. Dev workflow is completely unaffected.

- [ ] **Verify prod config is syntactically valid**

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml config --services
```

Expected: Lists all services (app, nginx, reverb, queue, mysql, redis, mosquitto, caddy). No `vite`.

- [ ] **Files created checklist:**
- `docker-compose.prod.yml` — production overrides with Caddy
- `docker/caddy/Caddyfile` — reverse proxy with auto-HTTPS
- `docker/mosquitto/mosquitto.prod.conf` — auth-required config
- `docker/mosquitto/acl` — MQTT topic access control
- `.env.production` — documented production env template
- `.gitignore` — updated to exclude `docker/mosquitto/passwd`

## Production Deployment Checklist (for when you have a VPS)

1. Point your domain's DNS A record to the VPS IP
2. Clone the repo on the VPS
3. Copy `.env.production` to `.env`, fill in all `CHANGE_ME` values
4. Generate Mosquitto passwords (Task 3, Step 3)
5. Run: `docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build`
6. Run: `docker compose exec app php artisan key:generate`
7. Run: `docker compose exec app php artisan migrate --force`
8. Caddy auto-provisions SSL — site is live at `https://yourdomain.com`
9. Update ESP32 firmware with production server URL, MQTT credentials, and domain
