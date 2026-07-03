# Wolf

**A smart garage control system built for a motorcycle rider who wants to open the garage door from the saddle without dismounting.**

At its core, Wolf is an end-to-end IoT stack: a Laravel + React web app, a Sanctum-authenticated iOS companion, and an ESP32-based servo controller that all talk to each other via MQTT for commands and Reverb WebSockets for live status. The web app also runs a time-based scheduled trigger flow (schedule a delayed open, watch the countdown, get a broadcast when the servo fires); the iOS app uses OS geofencing for the same effect based on physical location.

The photo-capture feature was built first as a proof of concept for the entire command pipeline — same MQTT command, same broadcast return path, different physical action (camera vs. servo). Once the pipeline was proven, swapping in the garage servo was a change of payload, not a change of architecture.

## Architecture

```
   ┌────────────────────────┐     ┌────────────────────────┐
   │  Web (Inertia+React)   │     │      iOS app           │
   │  session-authenticated │     │  Sanctum bearer token  │
   └───────────┬────────────┘     └───────────┬────────────┘
               │                              │
               │   HTTPS  (JSON / Inertia)    │
               │                              │
               ▼                              ▼
   ┌────────────────────────────────────────────────────────┐
   │                    Laravel 13 backend                  │
   │                                                        │
   │  Routes → FormRequests → Policies → Controllers        │
   │                                     │                  │
   │                                     ▼                  │
   │                              Domain Services           │
   │                                     │                  │
   │                                     ▼                  │
   │                       DeviceInterface (Strategy)       │
   │                                     │                  │
   │                       ┌─────────────┴────────────┐     │
   │                       │                          │     │
   │                       ▼                          ▼     │
   │                MockDevice                Esp32MqttDevice│
   │                (local dev)               (production)   │
   └───────────────────────┬────────────────────┬──────────┘
                           │                    │
                           │ MQTT command       │ broadcast
                           │                    │ (via Reverb)
                           ▼                    ▼
                    ┌──────────────┐     ┌──────────────┐
                    │  Mosquitto   │     │  Reverb WS   │
                    │  (broker)    │     │              │
                    └──────┬───────┘     └──────┬───────┘
                           │                    │
                           │ MQTT subscribe     │ Echo client
                           ▼                    │
                    ┌──────────────┐             │
                    │  ESP32-CAM   │             │
                    │  ESP8266     │─────────────┘
                    │  (servo)     │  HTTPS callback
                    └──────────────┘  (stream frames, status)
```

**Command flow:** browser or iOS triggers → Laravel validates + authorizes at the boundary → domain service dispatches → MQTT publishes to `wolf/{deviceId}/command` → ESP32 executes → device broadcasts the result back through Reverb → browser sees the update over Echo.

**Different transports on purpose:** MQTT for commands (low-latency, publish/subscribe, keep-alive) and HTTPS POST for media/frames (bulk binary, per-request auth). LWT (Last Will and Testament) on the MQTT connection gives us free online/offline signalling.

## Tech stack

| Layer | Tech |
|---|---|
| Backend | Laravel 13, PHP 8.3 |
| SPA | React 18 + TypeScript + Inertia.js 2 |
| Styling | Tailwind 3 + Tailwind Forms |
| WebSockets | Laravel Reverb + Laravel Echo + pusher-js |
| Auth | Session (Breeze) for web, Sanctum PATs for mobile |
| IoT transport | MQTT (`php-mqtt/client`) via self-hosted Mosquitto |
| Storage | SQLite (local), MySQL (production) |
| Cache/Queue/Session | Redis (production), `array` / `database` (local) |
| Maps | Leaflet + react-leaflet |
| Testing | PHPUnit 12 (feature + unit) |
| Deployment | Docker Compose (dev + prod), Supervisor, Caddy TLS |

## Getting started

Requires PHP 8.3+, Composer, Node 20.19+ (see `.nvmrc`), and Redis (for local queue/cache if you switch off the defaults).

```bash
# One-shot setup
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
npm install
npm run build

# Start the dev stack (Laravel serve + queue worker + Pail logs + Vite)
composer dev
```

Then open <http://localhost:8000> and register.

For a step-by-step walkthrough of the mock device driver, event broadcasting, and test flows, see [`docs/HOW-TO-TEST.md`](docs/HOW-TO-TEST.md).

## Testing

```bash
composer test        # runs the full PHPUnit suite
```

Or, if you need the finer control:

```bash
./vendor/bin/phpunit --filter GeoFence
./vendor/bin/phpunit --testsuite Feature
```

The test suite runs against `sqlite :memory:` and the `array` cache — no external services required.

## Deployment

Production runs behind Docker Compose with Caddy handling TLS. Full instructions and the `deploy.sh` runbook are in [`docs/deployment.md`](docs/deployment.md).

Key pieces:

- `Dockerfile` builds the app image (PHP-FPM + composer install + npm build)
- `docker-compose.prod.yml` composes app + Mosquitto + Redis + MySQL + Reverb + Caddy
- `docs/supervisor/wolf-queue.conf` and `wolf-reverb.conf` keep the queue worker and Reverb server up
- `.githooks/pre-commit` runs Pint on staged PHP files before every commit

## License

MIT.
