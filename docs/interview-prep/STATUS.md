# Interview Prep — Status Board

Section-by-section progress across the two tracks. Reviewed alongside
[WORKFLOW.md](WORKFLOW.md).

## Delivery mode

**Ship-today refactor + async-review study.** The repo goes to reviewers by
end of day 2026-07-02; the walkthrough / ownership track happens during the
~1 week review window. Priorities are inverted from the original workflow:
refactor first, learn later.

- **Base branch:** `master`
- **PR strategy:** one branch per ticket, individual PRs, targeting `master`
- **Test rhythm:** run relevant suite after each ticket; full suite at wave boundaries
- **Approval mode:** confirm each ticket before code edits

## Refactor plan — 13 tickets, 4 waves

### Wave 1 — Mechanical quick wins

| Ticket | Title | Est. | Status |
|---|---|---|---|
| WOLF-103 | Add `declare(strict_types=1);` to all app + test PHP files | 15 min | ⏳ To Do |
| WOLF-104 | Migrate test suite to PHPUnit `#[Test]` attributes | 45 min | ⏳ To Do |
| WOLF-105 | Replace `'esp8266'` string literals with `DeviceType::Esp8266->value` | 10 min | ⏳ To Do |
| WOLF-106 | Extract Dashboard route closure → `DashboardController::index()` | 15 min | ⏳ To Do |
| WOLF-107 | Extract Geofence route closure → `GeofencePageController::index()` | 15 min | ⏳ To Do |
| WOLF-108 | Introduce `StreamStatus` enum, replace magic strings | 20 min | ⏳ To Do |

### Wave 2 — Foundation for service layer

| Ticket | Title | Est. | Status |
|---|---|---|---|
| WOLF-109 | `GeoFencePolicy` — replace 6 duplicated `user_id` checks | 30 min | ⏳ To Do |
| WOLF-110 | GeoFence Form Requests (Store/Update/Check/ScheduleTrigger) | 45 min | ⏳ To Do |
| WOLF-111 | API Resources (`GeoFenceResource`, `DeviceResource`, `StreamResource`) | 45 min | ⏳ To Do |

### Wave 3 — Service extractions (crown jewel)

| Ticket | Title | Est. | Depends on | Status |
|---|---|---|---|---|
| WOLF-112 | Extract `GeoFenceService` — thin controller orchestrates | 90 min | 109, 110, 111 | ⏳ To Do |
| WOLF-113 | Extract `StreamService` — controller orchestrates | 45 min | 108, 111 | ⏳ To Do |
| WOLF-114 | Extract `DeviceClaimService` — controller orchestrates | 20 min | — | ⏳ To Do |

### Wave 4 — Section 1 hardening (already ticketed)

| Ticket | Title | Est. | Status |
|---|---|---|---|
| WOLF-101 | Normalize API error responses (JSON on `api/*`) | 60 min | ⏳ To Do |
| WOLF-100 | Apply `device-capture` rate limiter to garage + stream routes | 25 min | ⏳ To Do (blocked by 101) |
| WOLF-102 | Standardize `DEVICE_DRIVER` env value; drop dead match branch | 15 min | ⏳ To Do |

### Wave 5 — Frontend selective cleanup

Survey after backend waves complete. Target: 1–2 high-signal fixes only.

## Sections (deferred — study during review week)

| # | Section | Review |
|---|---|---|
| 1 | Bootstrap & request lifecycle | ✅ done (Wave 4 covers must-fixes) |
| 2 | Config layer | ⏳ deferred |
| 3 | Auth (web + mobile) | ⏳ deferred |
| 4 | Middleware & authorization | ⏳ partially covered by WOLF-109 |
| 5 | Device domain | ⏳ deferred |
| 6 | Streaming | ⏳ partially covered by WOLF-108, 113 |
| 7 | Garage control | ⏳ partially covered by WOLF-100 |
| 8 | Geofence system | ⏳ partially covered by WOLF-109/110/111/112 |
| 9 | MQTT integration | ⏳ deferred |
| 10 | Broadcasting | ⏳ deferred |
| 11 | API layer | ⏳ partially covered by WOLF-101, 111 |
| 12 | Frontend | ⏳ Wave 5 |
| 13 | Migrations & DB evolution | ⏳ deferred |
| 14 | Testing strategy | ⏳ partially covered by WOLF-104 |
| 15 | Deployment & ops | ⏳ deferred |
| 16 | Critical review / attack surface | ⏳ deferred |

## Legend

- ✅ done
- 🔄 in progress
- ⏳ To Do / pending
- ⛔ skipped (with reason)
