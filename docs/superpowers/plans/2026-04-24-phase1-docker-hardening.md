# Phase 1: Docker Dev Hardening — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the Docker dev environment resilient — photos survive rebuilds, crashed containers auto-restart, logs don't fill the disk, and health is observable via `docker compose ps`.

**Architecture:** All 4 tasks modify `docker-compose.yml`. Task 3 also adds a Laravel `/health` endpoint (controller + route + test). No frontend changes. No database migrations.

**Tech Stack:** Docker Compose, Laravel 13, PHP 8.4, PHPUnit

**Constraints:**
- Do NOT run `git add`, `git commit`, or `git push` — the user handles all git operations manually.
- All code changes happen in `/Users/mr.casanova/Code/wolf`.
- Run tests inside Docker: `docker compose exec app php artisan test`
- After modifying `docker-compose.yml`, restart with: `docker compose up -d`

---

## File Map

| File | Action | Responsibility |
|---|---|---|
| `docker-compose.yml` | Modify | Add storage volume, restart policies, health checks, log rotation |
| `app/Http/Controllers/HealthController.php` | Create | Returns JSON health status (DB + Redis connectivity) |
| `routes/web.php` | Modify | Add `GET /health` route (no auth required) |
| `tests/Feature/HealthCheckTest.php` | Create | Tests for the `/health` endpoint |

---

### Task 1: Storage Volume

**Files:**
- Modify: `docker-compose.yml`

This adds a named volume so uploaded photos in `storage/app` persist across `docker compose up --build` rebuilds.

- [ ] **Step 1: Add the storage volume to the `app` service**

In `docker-compose.yml`, add `storage_data:/var/www/storage/app` to the `app` service's `volumes` list. The updated `app` service volumes should be:

```yaml
    volumes:
      - .:/var/www
      - /var/www/vendor
      - storage_data:/var/www/storage/app
```

- [ ] **Step 2: Declare the volume**

Add `storage_data:` to the `volumes:` section at the bottom of `docker-compose.yml`:

```yaml
volumes:
  mysql_data:
  redis_data:
  mosquitto_data:
  mosquitto_log:
  storage_data:
```

- [ ] **Step 3: Apply and verify**

Run:
```bash
docker compose up -d
```

Then verify the volume was created:
```bash
docker volume ls | grep storage
```

Expected: `wolf_storage_data` appears in the list.

- [ ] **Step 4: Verify photos persist across rebuilds**

```bash
# Create a test file inside the container
docker compose exec app sh -c "echo 'test' > /var/www/storage/app/test-persist.txt"

# Rebuild
docker compose up -d --build

# Check the file survived
docker compose exec app cat /var/www/storage/app/test-persist.txt
```

Expected output: `test`

Clean up:
```bash
docker compose exec app rm /var/www/storage/app/test-persist.txt
```

---

### Task 2: Restart Policies

**Files:**
- Modify: `docker-compose.yml`

Add `restart: unless-stopped` to every service so crashed containers auto-restart without manual intervention.

- [ ] **Step 1: Add restart policy to all services**

Add `restart: unless-stopped` to each of the 8 services in `docker-compose.yml`. Place it directly after the service name (before `build:` or `image:`). Here is the exact line to add to each service:

**app:**
```yaml
  app:
    restart: unless-stopped
    build:
```

**nginx:**
```yaml
  nginx:
    restart: unless-stopped
    image: nginx:alpine
```

**vite:**
```yaml
  vite:
    restart: unless-stopped
    image: node:20-alpine
```

**reverb:**
```yaml
  reverb:
    restart: unless-stopped
    build:
```

**queue:**
```yaml
  queue:
    restart: unless-stopped
    build:
```

**mysql:**
```yaml
  mysql:
    restart: unless-stopped
    image: mysql:8.0
```

**redis:**
```yaml
  redis:
    restart: unless-stopped
    image: redis:7-alpine
```

**mosquitto:**
```yaml
  mosquitto:
    restart: unless-stopped
    image: eclipse-mosquitto:2
```

- [ ] **Step 2: Apply and verify**

```bash
docker compose up -d
```

Then verify restart policies are set:
```bash
docker inspect wolf-app-1 --format '{{.HostConfig.RestartPolicy.Name}}'
```

Expected: `unless-stopped`

- [ ] **Step 3: Test auto-restart**

Kill the queue worker and watch it come back:
```bash
# Kill the queue container's main process
docker compose kill queue

# Wait 3 seconds, then check status
sleep 3 && docker compose ps queue
```

Expected: `queue` container shows status `Up` (restarted automatically).

---

### Task 3: Health Check Endpoint

**Files:**
- Create: `app/Http/Controllers/HealthController.php`
- Modify: `routes/web.php`
- Create: `tests/Feature/HealthCheckTest.php`
- Modify: `docker-compose.yml`

Adds a `GET /health` endpoint that checks MySQL and Redis. Docker uses this to report actual health in `docker compose ps`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/HealthCheckTest.php`:

```php
<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_endpoint_returns_healthy_status(): void
    {
        $response = $this->getJson('/health');

        $response->assertOk()
            ->assertJson([
                'status' => 'healthy',
            ])
            ->assertJsonStructure([
                'status',
                'checks' => [
                    'database',
                    'redis',
                ],
            ]);
    }

    public function test_health_endpoint_is_accessible_without_auth(): void
    {
        $response = $this->getJson('/health');

        $response->assertOk();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
docker compose exec app php artisan test --filter=HealthCheckTest
```

Expected: FAIL — route `/health` not defined.

- [ ] **Step 3: Create the HealthController**

Create `app/Http/Controllers/HealthController.php`:

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'redis' => $this->checkRedis(),
        ];

        $healthy = !in_array(false, $checks, true);

        return response()->json([
            'status' => $healthy ? 'healthy' : 'unhealthy',
            'checks' => $checks,
        ], $healthy ? 200 : 503);
    }

    private function checkDatabase(): bool
    {
        try {
            DB::connection()->getPdo();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function checkRedis(): bool
    {
        try {
            Redis::ping();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
```

- [ ] **Step 4: Add the route**

In `routes/web.php`, add the import at the top with the other use statements:

```php
use App\Http\Controllers\HealthController;
```

Add the route **before** the auth middleware group (this route must be public):

```php
Route::get('/health', HealthController::class)->name('health');
```

Place it right after the `'/'` welcome route, before `Route::middleware(['auth', 'verified'])`.

- [ ] **Step 5: Run tests to verify they pass**

```bash
docker compose exec app php artisan test --filter=HealthCheckTest
```

Expected: 2 tests, 2 passed.

- [ ] **Step 6: Run full test suite to check for regressions**

```bash
docker compose exec app php artisan test
```

Expected: 40 passed (38 existing + 2 new).

- [ ] **Step 7: Add Docker health checks to docker-compose.yml**

Add a `healthcheck` to the **app** service (uses the health endpoint via nginx):

```yaml
  app:
    restart: unless-stopped
    build:
      context: .
      dockerfile: Dockerfile
      target: dev
    volumes:
      - .:/var/www
      - /var/www/vendor
      - storage_data:/var/www/storage/app
    depends_on:
      mysql:
        condition: service_healthy
      redis:
        condition: service_healthy
    environment:
      - PHP_IDE_CONFIG=serverName=wolf
    healthcheck:
      test: ["CMD", "php", "artisan", "tinker", "--execute", "try { DB::connection()->getPdo(); echo 'ok'; } catch (Exception) { exit(1); }"]
      interval: 30s
      timeout: 5s
      retries: 3
      start_period: 30s
    networks:
      - wolf
```

Add a `healthcheck` to the **reverb** service:

```yaml
  reverb:
    restart: unless-stopped
    build:
      context: .
      dockerfile: Dockerfile
      target: dev
    command: php artisan reverb:start --host=0.0.0.0 --port=8080
    ports:
      - "8080:8080"
    volumes:
      - .:/var/www
      - /var/www/vendor
    depends_on:
      mysql:
        condition: service_healthy
      redis:
        condition: service_healthy
    healthcheck:
      test: ["CMD", "sh", "-c", "php -r \"\\$c = @fsockopen('127.0.0.1', 8080); if (!\\$c) exit(1); fclose(\\$c);\""]
      interval: 30s
      timeout: 5s
      retries: 3
      start_period: 10s
    networks:
      - wolf
```

Add a `healthcheck` to the **nginx** service:

```yaml
  nginx:
    restart: unless-stopped
    image: nginx:alpine
    ports:
      - "8000:80"
    volumes:
      - .:/var/www
      - ./docker/nginx/default.conf:/etc/nginx/conf.d/default.conf:ro
    depends_on:
      app:
        condition: service_healthy
    healthcheck:
      test: ["CMD", "wget", "--spider", "-q", "http://localhost/health"]
      interval: 30s
      timeout: 5s
      retries: 3
      start_period: 10s
    networks:
      - wolf
```

Note: The `nginx` service `depends_on` changes from `- app` to the conditional form so it waits for the app to be healthy before starting.

- [ ] **Step 8: Apply and verify health checks**

```bash
docker compose up -d
```

Wait 30-40 seconds for health checks to run, then:

```bash
docker compose ps
```

Expected: `app`, `nginx`, `reverb`, `mysql`, and `redis` all show `(healthy)` in the STATUS column.

- [ ] **Step 9: Test the health endpoint manually**

```bash
curl -s http://localhost:8000/health | python3 -m json.tool
```

Expected:
```json
{
    "status": "healthy",
    "checks": {
        "database": true,
        "redis": true
    }
}
```

---

### Task 4: Log Rotation

**Files:**
- Modify: `docker-compose.yml`

Add log rotation to all services. Each service gets max 3 files of 10MB each (30MB cap per service).

- [ ] **Step 1: Add logging config to every service**

Add this `logging` block to each of the 8 services in `docker-compose.yml`. Place it at the end of each service definition, before the `networks:` key:

```yaml
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"
```

Here is the complete list — add it to: `app`, `nginx`, `vite`, `reverb`, `queue`, `mysql`, `redis`, `mosquitto`.

Example for `app` (same block for all services):
```yaml
  app:
    restart: unless-stopped
    build:
      context: .
      dockerfile: Dockerfile
      target: dev
    volumes:
      - .:/var/www
      - /var/www/vendor
      - storage_data:/var/www/storage/app
    depends_on:
      mysql:
        condition: service_healthy
      redis:
        condition: service_healthy
    environment:
      - PHP_IDE_CONFIG=serverName=wolf
    healthcheck:
      test: ["CMD", "php", "artisan", "tinker", "--execute", "try { DB::connection()->getPdo(); echo 'ok'; } catch (Exception) { exit(1); }"]
      interval: 30s
      timeout: 5s
      retries: 3
      start_period: 30s
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"
    networks:
      - wolf
```

- [ ] **Step 2: Apply and verify**

```bash
docker compose up -d
```

Verify log config on one service:
```bash
docker inspect wolf-app-1 --format '{{.HostConfig.LogConfig.Type}} max-size={{index .HostConfig.LogConfig.Config "max-size"}} max-file={{index .HostConfig.LogConfig.Config "max-file"}}'
```

Expected: `json-file max-size=10m max-file=3`

- [ ] **Step 3: Verify logs still work**

```bash
docker compose logs app --tail=5
```

Expected: Shows the last 5 log lines from the app container (not empty, not errored).

---

## Final Verification

After all 4 tasks are complete:

- [ ] **Run full test suite**

```bash
docker compose exec app php artisan test
```

Expected: 40 passed (38 existing + 2 new health check tests).

- [ ] **Verify all services are healthy**

```bash
docker compose ps
```

Expected: All containers `Up`, with `(healthy)` on app, nginx, reverb, mysql, redis.

- [ ] **Verify the complete docker-compose.yml has:**
- `storage_data` volume on `app` and declared at bottom
- `restart: unless-stopped` on all 8 services
- `healthcheck` on `app`, `nginx`, `reverb` (mysql and redis already had them)
- `logging` with `max-size: "10m"` and `max-file: "3"` on all 8 services
