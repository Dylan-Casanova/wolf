# Wolf Deployment Design

## Goal

Deploy Wolf (Laravel + React + MQTT IoT) to a single Hetzner VPS using Docker Compose, with automatic HTTPS, accessible to ESP devices over the public internet.

## Context

Wolf is a Smart Garage Control System with 8 containerized services: Laravel app (PHP-FPM), Nginx, Reverb (WebSockets), queue worker, MQTT listener, MySQL, Redis, and Mosquitto. It supports ESP32-CAM and ESP8266 devices that connect via MQTT. The system currently runs locally with Docker Compose and has a working production Dockerfile stage.

Target audience: 2-5 users, under 10 devices. Budget: minimal (~$5/mo + domain).

## Infrastructure

### Server

- **Provider:** Hetzner Cloud
- **Plan:** CX22 — 2 shared vCPU, 4GB RAM, 40GB SSD, 20TB traffic — $4.51/mo
- **OS:** Ubuntu 24.04 LTS (Docker pre-installed image)
- **Location:** Choose closest region to physical device location (e.g., Ashburn for US East)

### Domain & DNS

- Purchase a domain (~$10/year from Namecheap, Cloudflare, or Porkbun)
- A record pointing to VPS IP for the app (e.g., `wolf.yourdomain.com`)
- Can use root domain or subdomain — user's choice

### SSL/TLS

- **Caddy** as the public-facing reverse proxy handles automatic HTTPS via Let's Encrypt
- Zero-config certificate provisioning and renewal

## Architecture

### Network Topology

```
Internet
   │
   ├── :443 (HTTPS) ──► Caddy ──► Nginx ──► PHP-FPM (Laravel)
   │                       │
   │                       └──► Reverb (:8080 internal, WSS via Caddy)
   │
   ├── :1883 (MQTT) ──► Mosquitto
   │
   └── :22 (SSH) ──► Server
   
Internal Docker network:
   PHP-FPM ←→ MySQL, Redis
   Queue Worker ←→ MySQL, Redis
   MQTT Listener ←→ MySQL, Redis, Mosquitto
   Reverb ←→ Redis
```

### Public Ports

| Port | Protocol | Service | Purpose |
|------|----------|---------|---------|
| 443  | HTTPS    | Caddy   | Web app + WebSockets (WSS) |
| 80   | HTTP     | Caddy   | Auto-redirect to HTTPS |
| 1883 | TCP      | Mosquitto | MQTT for ESP devices |
| 22   | TCP      | SSH     | Server management |

### Internal-Only Services (Not Exposed)

- MySQL (3306) — only reachable within Docker network
- Redis (6379) — only reachable within Docker network
- Nginx (80 internal) — proxied through Caddy
- PHP-FPM (9000) — proxied through Nginx
- Reverb (8080 internal) — proxied through Caddy

## Docker Compose Production Setup

### Strategy

Create `docker-compose.prod.yml` as an override file. Deploy with:

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build
```

### Production Overrides

- **Add Caddy** as a new service (reverse proxy + auto HTTPS)
- **Remove Vite** dev server (assets pre-built in Docker image)
- **Remove port mappings** for MySQL, Redis (internal only)
- **Nginx** listens internally only (Caddy is the public entry point)
- **Reverb** runs behind Caddy with WSS
- **Production image** used for app, queue, mqtt-listener, reverb (Dockerfile production stage)

### Caddy Configuration

Caddy handles:
- HTTPS termination with automatic Let's Encrypt certificates
- Reverse proxy to Nginx for the Laravel app
- WebSocket proxy to Reverb (upgrade headers)
- Automatic HTTP → HTTPS redirect

### Environment Variables

Create `.env.production.example` in the repo with all required variables, comments, and safe placeholder values. On the server, copy to `.env` and fill in real values.

Key production differences from dev:
- `APP_ENV=production`, `APP_DEBUG=false`
- `APP_URL=https://wolf.yourdomain.com`
- Real `DB_PASSWORD` (generated during setup)
- `APP_KEY` generated via `php artisan key:generate`
- `REVERB_HOST` set to actual domain
- `VITE_REVERB_SCHEME=https`

## ESP Device Configuration

### Firmware Change

The provisioning base URL in firmware must point to the production server:

```cpp
#define WOLF_PROVISION_BASE "https://wolf.yourdomain.com"
```

This requires a manual firmware update and re-flash. Both ESP32-CAM and ESP8266 firmware files need this change.

### MQTT Connectivity

ESP devices connect to the VPS public IP on port 1883. The MQTT topic structure and JSON command format remain identical — no backend changes needed.

## Security

### SSH

- Key-based authentication only — password login disabled
- User copies existing SSH public key from Mac during server setup

### Firewall

- Hetzner Cloud firewall + `ufw` on the server
- Only ports 22, 80, 443, 1883 open
- All other ports blocked

### Database & Redis

- Not exposed to the internet (no public port mapping)
- Only accessible within Docker's internal network

### MQTT

- Mosquitto unauthenticated for initial deployment (same as dev)
- Future improvement: add username/password authentication

### OS

- `unattended-upgrades` enabled for automatic security patches

## Deployment Workflow

### Initial Deployment

1. Create Hetzner VPS with Docker image
2. SSH in, configure firewall, disable password auth
3. Clone repo from GitHub (`master` branch)
4. Create `.env` from `.env.production.example`, fill in real values
5. Generate `APP_KEY`
6. Run `docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build`
7. Run migrations
8. Create admin user
9. Point domain DNS to VPS IP
10. Verify HTTPS, WebSockets, and MQTT connectivity

### Subsequent Deploys

1. Push changes to `master` on GitHub
2. SSH into VPS
3. `git pull`
4. `docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build`
5. Run migrations if needed

Manual for now. GitHub Actions auto-deploy is a future improvement.

## Files to Create/Modify

- **Create:** `docker-compose.prod.yml` — production overrides
- **Create:** `docker/caddy/Caddyfile` — Caddy reverse proxy config
- **Create:** `.env.production.example` — production environment template with comments
- **Modify:** `docker/mosquitto/mosquitto.conf` — ensure it works for public access
- **Modify:** `.github/workflows/ci.yml` — optional: add deploy step later

## Future Improvements (Out of Scope)

- GitHub Actions auto-deploy on push to master
- MQTT authentication (username/password for devices)
- Automated database backups
- Monitoring/alerting (uptime, disk, memory)
- Self-hosting migration (same Docker Compose on home server)
- Configurable provisioning URL in firmware captive portal
