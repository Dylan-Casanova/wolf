# Wolf Deployment Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deploy Wolf to a Hetzner VPS with Docker Compose, automatic HTTPS via Caddy, and public MQTT for ESP devices.

**Architecture:** Single Hetzner CX22 VPS running Ubuntu 24.04 with Docker. Caddy handles HTTPS termination and reverse-proxies to Nginx (Laravel) and Reverb (WebSockets). Mosquitto is exposed on port 1883 for ESP device MQTT. MySQL and Redis are internal-only.

**Tech Stack:** Docker Compose, Caddy 2, Hetzner Cloud, Ubuntu 24.04, Let's Encrypt

---

## File Structure

| Action | File | Purpose |
|--------|------|---------|
| Create | `docker-compose.prod.yml` | Production overrides (Caddy, no Vite, prod image targets, no public DB/Redis ports) |
| Create | `docker/caddy/Caddyfile` | Caddy reverse proxy config with auto HTTPS |
| Create | `docker/php/php-prod.ini` | Production PHP config (no Xdebug, opcache tuned) |
| Create | `.env.production.example` | Production env template with comments |
| Create | `docs/deployment.md` | Deployment guide for future reference |

---

### Task 1: Create Production PHP Configuration

**Files:**
- Create: `docker/php/php-prod.ini`

- [ ] **Step 1: Create production PHP config**

Create `docker/php/php-prod.ini` — same as `php.ini` but with opcache tuned for production (no revalidation) and no Xdebug section:

```ini
[PHP]
upload_max_filesize = 20M
post_max_size = 20M
memory_limit = 256M
max_execution_time = 60

[opcache]
opcache.enable = 1
opcache.memory_consumption = 128
opcache.max_accelerated_files = 10000
opcache.validate_timestamps = 0
```

Note: `opcache.validate_timestamps = 0` means PHP won't check if files changed — faster in production since code only changes on deploy (container rebuild).

- [ ] **Step 2: Verify file exists**

Run: `cat docker/php/php-prod.ini`
Expected: The config above without Xdebug section

---

### Task 2: Create Caddy Configuration

**Files:**
- Create: `docker/caddy/Caddyfile`

- [ ] **Step 1: Create the Caddyfile**

Create `docker/caddy/Caddyfile`:

```caddyfile
{$DOMAIN} {
    # Laravel app — proxy to internal Nginx
    handle {
        reverse_proxy nginx:80
    }

    # WebSocket — proxy Reverb
    handle /app/* {
        reverse_proxy reverb:8080
    }

    # Logging
    log {
        output stdout
    }
}
```

**How this works:**
- `{$DOMAIN}` is replaced by the `DOMAIN` environment variable (e.g. `wolf.yourdomain.com`)
- Caddy automatically obtains and renews Let's Encrypt certificates for that domain
- `/app/*` is the path Laravel Reverb uses for WebSocket connections — Caddy upgrades these to WSS automatically
- Everything else goes to Nginx which proxies to PHP-FPM

- [ ] **Step 2: Verify file exists**

Run: `cat docker/caddy/Caddyfile`
Expected: The Caddyfile above

---

### Task 3: Create Production Docker Compose Override

**Files:**
- Create: `docker-compose.prod.yml`

- [ ] **Step 1: Create the production override file**

Create `docker-compose.prod.yml`:

```yaml
# =============================================================================
# Wolf — Docker Compose Production Overrides
# =============================================================================
# Usage:
#   docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build
#
# This file overrides docker-compose.yml for production:
#   - Uses production Dockerfile stage (no Xdebug, no dev deps)
#   - Adds Caddy for automatic HTTPS
#   - Removes Vite dev server
#   - Removes public ports for MySQL and Redis
#   - Uses production PHP config
# =============================================================================

services:

  # ---------------------------------------------------------------------------
  # Caddy: Reverse Proxy + Automatic HTTPS
  # ---------------------------------------------------------------------------
  caddy:
    image: caddy:2-alpine
    restart: unless-stopped
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - ./docker/caddy/Caddyfile:/etc/caddy/Caddyfile:ro
      - caddy_data:/data
      - caddy_config:/config
    environment:
      - DOMAIN=${APP_DOMAIN}
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

  # ---------------------------------------------------------------------------
  # App: Use production stage
  # ---------------------------------------------------------------------------
  app:
    build:
      target: production
    volumes:
      - storage_data:/var/www/storage/app
      - ./docker/php/php-prod.ini:/usr/local/etc/php/conf.d/99-wolf.ini:ro
    environment:
      - PHP_IDE_CONFIG=

  # ---------------------------------------------------------------------------
  # Nginx: Remove public port (Caddy is the entry point)
  # ---------------------------------------------------------------------------
  nginx:
    ports: !override
      - "80"

  # ---------------------------------------------------------------------------
  # Vite: Disabled in production (assets pre-built in Docker image)
  # ---------------------------------------------------------------------------
  vite:
    profiles:
      - disabled

  # ---------------------------------------------------------------------------
  # Reverb: Use production stage, no public port (Caddy proxies WSS)
  # ---------------------------------------------------------------------------
  reverb:
    build:
      target: production
    ports: !override
      - "8080"
    volumes:
      - storage_data:/var/www/storage/app
      - ./docker/php/php-prod.ini:/usr/local/etc/php/conf.d/99-wolf.ini:ro

  # ---------------------------------------------------------------------------
  # Queue: Use production stage
  # ---------------------------------------------------------------------------
  queue:
    build:
      target: production
    volumes:
      - storage_data:/var/www/storage/app
      - ./docker/php/php-prod.ini:/usr/local/etc/php/conf.d/99-wolf.ini:ro

  # ---------------------------------------------------------------------------
  # MQTT Listener: Use production stage
  # ---------------------------------------------------------------------------
  mqtt-listener:
    build:
      target: production
    volumes:
      - storage_data:/var/www/storage/app
      - ./docker/php/php-prod.ini:/usr/local/etc/php/conf.d/99-wolf.ini:ro

  # ---------------------------------------------------------------------------
  # MySQL: Remove public port (internal only)
  # ---------------------------------------------------------------------------
  mysql:
    ports: !override []

  # ---------------------------------------------------------------------------
  # Redis: Remove public port (internal only)
  # ---------------------------------------------------------------------------
  redis:
    ports: !override []

volumes:
  caddy_data:
  caddy_config:
```

**Key points:**
- `profiles: [disabled]` on Vite prevents it from starting
- `ports: !override` removes the host port mapping so MySQL/Redis are internal-only
- PHP services use the `production` build target (no Xdebug, no dev deps, built assets)
- `APP_DOMAIN` env var configures Caddy's domain (set in `.env`)
- `caddy_data` volume persists SSL certificates across restarts

- [ ] **Step 2: Validate the YAML syntax**

Run: `docker compose -f docker-compose.yml -f docker-compose.prod.yml config --quiet`
Expected: No output (valid YAML). If there are errors, fix them.

> **Note:** The `!override` tag is a Docker Compose v2.24+ feature. If it fails, use an alternative approach — we'll verify in the step above and adjust if needed.

---

### Task 4: Create Production Environment Template

**Files:**
- Create: `.env.production.example`

- [ ] **Step 1: Create the production env template**

Create `.env.production.example`:

```env
# =============================================================================
# Wolf — Production Environment Variables
# =============================================================================
# Copy this file to .env on your server and fill in real values.
#
# Setup:
#   1. cp .env.production.example .env
#   2. Fill in your domain, generate passwords, etc.
#   3. Run: docker compose -f docker-compose.yml -f docker-compose.prod.yml exec app php artisan key:generate
# =============================================================================

APP_NAME=Wolf
APP_ENV=production
APP_KEY=
APP_DEBUG=false

# Your domain — used by Caddy for HTTPS and by Laravel for URL generation
APP_DOMAIN=wolf.yourdomain.com
APP_URL=https://wolf.yourdomain.com

APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_US

APP_MAINTENANCE_DRIVER=file

BCRYPT_ROUNDS=12

LOG_CHANNEL=stack
LOG_STACK=single
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=warning

# --- Database ---
# MySQL runs inside Docker, not exposed to the internet.
# Change the password to something strong (e.g. openssl rand -base64 24)
DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=wolf
DB_USERNAME=wolf
DB_PASSWORD=CHANGE_ME_USE_OPENSSL_RAND

# --- Sessions / Cache / Queues ---
# Redis runs inside Docker, not exposed to the internet.
SESSION_DRIVER=redis
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=null

BROADCAST_CONNECTION=reverb
FILESYSTEM_DISK=local
QUEUE_CONNECTION=redis

CACHE_STORE=redis

REDIS_CLIENT=phpredis
REDIS_HOST=redis
REDIS_PASSWORD=null
REDIS_PORT=6379

# --- MQTT ---
# Mosquitto runs inside Docker. ESP devices connect on port 1883.
DEVICE_DRIVER=mqtt
MQTT_HOST=mosquitto
MQTT_PORT=1883

# --- Reverb WebSocket ---
# Generate real secrets: openssl rand -hex 16 (for key and secret)
REVERB_APP_ID=wolf-prod
REVERB_APP_KEY=CHANGE_ME_OPENSSL_RAND_HEX_16
REVERB_APP_SECRET=CHANGE_ME_OPENSSL_RAND_HEX_16
REVERB_HOST=0.0.0.0
REVERB_PORT=8080
REVERB_SCHEME=http

# Vite variables are baked into the frontend build.
# VITE_REVERB_HOST must be your public domain (browser connects here).
VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST=wolf.yourdomain.com
VITE_REVERB_PORT=443
VITE_REVERB_SCHEME=https

VITE_APP_NAME="${APP_NAME}"

# --- Mail ---
# Configure with your mail provider (Mailgun, SES, etc.)
# For now, log driver is fine — emails go to storage/logs
MAIL_MAILER=log
MAIL_HOST=127.0.0.1
MAIL_PORT=2525
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_FROM_ADDRESS="hello@wolf.yourdomain.com"
MAIL_FROM_NAME="${APP_NAME}"

# --- Telescope ---
# Disabled in production (saves memory and DB writes)
TELESCOPE_ENABLED=false
```

**Important notes about this file:**
- `DEVICE_DRIVER=mqtt` — in production we use the real MQTT driver, not mock
- `VITE_REVERB_PORT=443` and `VITE_REVERB_SCHEME=https` — the browser connects to Caddy on 443, which proxies to Reverb on 8080 internally
- `LOG_LEVEL=warning` — production doesn't need debug logs
- `TELESCOPE_ENABLED=false` — saves resources in production
- All `CHANGE_ME` values must be replaced on the server

---

### Task 5: Update Dockerfile for Production PHP Config

**Files:**
- Modify: `Dockerfile` (production stage only)

The current production stage copies `docker/php/php.ini` (which includes Xdebug config). We need it to use the production config instead.

- [ ] **Step 1: Update the production stage in Dockerfile**

In the `Dockerfile`, in the `production` stage, add a line to copy the production PHP config after the existing `COPY . .` line. Find this block:

```dockerfile
# Copy application code
COPY . .
```

Add after it (before the `COPY --from=node` line):

```dockerfile
# Production PHP config (no Xdebug, opcache tuned)
COPY docker/php/php-prod.ini /usr/local/etc/php/conf.d/99-wolf.ini
```

This overwrites the dev php.ini that was copied as part of the base stage.

- [ ] **Step 2: Verify Dockerfile is valid**

Run: `docker build --target production --no-cache --progress=plain . 2>&1 | tail -5`
Expected: Build completes successfully (may take a few minutes the first time)

---

### Task 6: Create Deployment Guide

**Files:**
- Create: `docs/deployment.md`

- [ ] **Step 1: Create the deployment guide**

Create `docs/deployment.md`:

````markdown
# Wolf Deployment Guide

## Prerequisites

- A Hetzner Cloud account ([signup](https://hetzner.cloud))
- A domain name pointed to your server's IP
- Your SSH public key (`cat ~/.ssh/id_ed25519.pub` — if you don't have one, run `ssh-keygen -t ed25519`)

## 1. Create the Server

1. Log in to [Hetzner Cloud Console](https://console.hetzner.cloud)
2. Create a new project called "Wolf"
3. Add your SSH public key: **Security → SSH Keys → Add SSH Key** — paste the output of `cat ~/.ssh/id_ed25519.pub`
4. Create a server:
   - **Location:** Ashburn (or closest to you)
   - **Image:** Apps → Docker CE (Ubuntu 24.04)
   - **Type:** Shared vCPU → CX22 ($4.51/mo)
   - **SSH Key:** Select the key you just added
   - **Name:** `wolf`
5. Note the server's **IP address** after creation

## 2. Set Up DNS

Go to your domain registrar and create an A record:

| Type | Name | Value | TTL |
|------|------|-------|-----|
| A | wolf (or @) | YOUR_SERVER_IP | 300 |

Wait a few minutes for DNS to propagate. Verify:

```bash
dig wolf.yourdomain.com +short
# Should return your server IP
```

## 3. Secure the Server

SSH into the server:

```bash
ssh root@YOUR_SERVER_IP
```

Set up the firewall:

```bash
ufw allow 22/tcp    # SSH
ufw allow 80/tcp    # HTTP (Caddy redirect)
ufw allow 443/tcp   # HTTPS
ufw allow 1883/tcp  # MQTT
ufw enable
ufw status
```

Disable password authentication:

```bash
sed -i 's/#PasswordAuthentication yes/PasswordAuthentication no/' /etc/ssh/sshd_config
sed -i 's/PasswordAuthentication yes/PasswordAuthentication no/' /etc/ssh/sshd_config
systemctl restart ssh
```

Enable automatic security updates:

```bash
apt update && apt install -y unattended-upgrades
dpkg-reconfigure -plow unattended-upgrades
# Select "Yes" when prompted
```

## 4. Clone and Configure

Still on the server:

```bash
cd /opt
git clone https://github.com/YOUR_USERNAME/wolf.git
cd wolf
```

Create the environment file:

```bash
cp .env.production.example .env
```

Now edit `.env` and fill in your real values:

```bash
nano .env
```

**Values you need to set:**

1. **APP_DOMAIN** — your domain (e.g. `wolf.yourdomain.com`)
2. **APP_URL** — `https://wolf.yourdomain.com`
3. **DB_PASSWORD** — generate one:
   ```bash
   openssl rand -base64 24
   ```
4. **REVERB_APP_KEY** — generate one:
   ```bash
   openssl rand -hex 16
   ```
5. **REVERB_APP_SECRET** — generate another:
   ```bash
   openssl rand -hex 16
   ```
6. **VITE_REVERB_HOST** — same as your domain
7. **MAIL_FROM_ADDRESS** — your email or `noreply@yourdomain.com`

Save and exit (`Ctrl+X`, `Y`, `Enter` in nano).

## 5. Set MySQL Root Password

The MySQL container needs the same password you chose. Edit `docker-compose.yml` is NOT needed — the password is read from `.env`. But the base `docker-compose.yml` has hardcoded `secret` in the MySQL environment. The production override handles this.

Actually, you need to update the MySQL environment in `docker-compose.prod.yml` to use the `.env` values. This is already handled — MySQL reads `MYSQL_PASSWORD` from the compose environment which inherits from `.env`.

**Important:** The `DB_PASSWORD` in `.env` must match `MYSQL_PASSWORD`. Both should be the same value you generated above.

## 6. Deploy

```bash
cd /opt/wolf

# Build and start all services
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build

# Wait for MySQL to be healthy (check status)
docker compose ps

# Generate the app key
docker compose exec app php artisan key:generate

# Run database migrations
docker compose exec app php artisan migrate --force

# Create your admin user
docker compose exec app php artisan tinker
# In tinker, run:
# \App\Models\User::create(['name' => 'Your Name', 'email' => 'you@email.com', 'password' => bcrypt('your-password'), 'is_admin' => true]);
# exit
```

## 7. Verify

**Web app:**
Open `https://wolf.yourdomain.com` in your browser. You should see the Wolf login page with a valid HTTPS certificate.

**WebSockets:**
Log in and go to the dashboard. Open browser DevTools → Network → WS. You should see a WebSocket connection to `wss://wolf.yourdomain.com/app/...`.

**MQTT:**
From your local machine (with mosquitto-clients installed):
```bash
mosquitto_sub -h wolf.yourdomain.com -t "wolf/#" -v
```
You should be able to subscribe. When a device connects, you'll see messages.

## Updating

When you push new code:

```bash
ssh root@YOUR_SERVER_IP
cd /opt/wolf
git pull
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build
docker compose exec app php artisan migrate --force  # if there are new migrations
```

## Troubleshooting

**Check logs:**
```bash
docker compose logs -f app        # Laravel app
docker compose logs -f caddy      # HTTPS / proxy
docker compose logs -f reverb     # WebSockets
docker compose logs -f mqtt-listener  # MQTT
docker compose logs -f mosquitto  # MQTT broker
```

**Caddy not getting certificate:**
- Make sure DNS is pointing to the server IP
- Make sure ports 80 and 443 are open in the firewall
- Check Caddy logs: `docker compose logs caddy`

**ESP device can't connect to MQTT:**
- Make sure port 1883 is open: `ufw status`
- Test from your local machine: `mosquitto_pub -h wolf.yourdomain.com -t test -m hello`

**WebSockets not connecting:**
- Check that `VITE_REVERB_HOST` matches your domain
- Check that `VITE_REVERB_SCHEME=https` and `VITE_REVERB_PORT=443`
- These are baked into the frontend build — if you change them, rebuild: `docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build`
````

---

### Task 7: Handle MySQL Password in Production

**Files:**
- Modify: `docker-compose.prod.yml` (add MySQL env override)

The base `docker-compose.yml` hardcodes `MYSQL_ROOT_PASSWORD: secret` and `MYSQL_PASSWORD: secret`. In production these must use the password from `.env`.

- [ ] **Step 1: Add MySQL environment override**

In `docker-compose.prod.yml`, update the MySQL service section. Replace:

```yaml
  mysql:
    ports: !override []
```

With:

```yaml
  mysql:
    ports: !override []
    environment:
      MYSQL_ROOT_PASSWORD: ${DB_PASSWORD}
      MYSQL_DATABASE: ${DB_DATABASE}
      MYSQL_USER: ${DB_USERNAME}
      MYSQL_PASSWORD: ${DB_PASSWORD}
```

This overrides the hardcoded `secret` values from the base compose file with values from `.env`.

---

### Task 8: Validate Full Production Compose Config

This task validates that everything works together locally before deploying.

**Files:**
- All files created in Tasks 1-7

- [ ] **Step 1: Validate compose config merges correctly**

Run: `docker compose -f docker-compose.yml -f docker-compose.prod.yml config`
Expected: Merged YAML output showing:
- Caddy service present
- Vite service absent or with `profiles: [disabled]`
- App/queue/reverb/mqtt-listener using `target: production`
- MySQL and Redis without host port mappings
- No errors

- [ ] **Step 2: Check all created files exist**

Run:
```bash
ls -la docker/php/php-prod.ini docker/caddy/Caddyfile docker-compose.prod.yml .env.production.example docs/deployment.md
```
Expected: All 5 files listed

- [ ] **Step 3: Run existing tests to confirm nothing is broken**

Run: `docker compose exec app php artisan test`
Expected: All 89 tests pass (dev compose still works as before)

- [ ] **Step 4: TypeScript build check**

Run: `docker compose exec vite npx tsc --noEmit`
Expected: No errors

---

### Task 9: Server Setup and Deploy (Manual — Guided)

> **⚠️ This task is performed manually by the user on Hetzner, guided step by step. It is NOT automated.**

Follow the deployment guide created in Task 6 (`docs/deployment.md`):

- [ ] **Step 1: Create Hetzner VPS** — follow Section 1 of the guide
- [ ] **Step 2: Set up DNS** — follow Section 2, point domain to server IP
- [ ] **Step 3: Secure the server** — follow Section 3 (firewall, SSH, unattended-upgrades)
- [ ] **Step 4: Clone and configure** — follow Section 4 (clone repo, create `.env`)
- [ ] **Step 5: Deploy** — follow Section 6 (docker compose up, migrations, create admin user)
- [ ] **Step 6: Verify** — follow Section 7 (test HTTPS, WebSockets, MQTT)
- [ ] **Step 7: Update firmware** — change `WOLF_PROVISION_BASE` in firmware to `https://wolf.yourdomain.com` and re-flash the ESP device

---

## Summary

| Task | What | Type |
|------|------|------|
| 1 | Production PHP config | Create file |
| 2 | Caddy reverse proxy config | Create file |
| 3 | Production Docker Compose override | Create file |
| 4 | Production environment template | Create file |
| 5 | Dockerfile production stage update | Modify file |
| 6 | Deployment guide | Create file |
| 7 | MySQL password override | Modify file |
| 8 | Validate everything works | Verification |
| 9 | Server setup and deploy (manual) | Guided walkthrough |
