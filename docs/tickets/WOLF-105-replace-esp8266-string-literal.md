# WOLF-105 · Replace `'esp8266'` string literals with `DeviceType::Esp8266->value`

| Field | Value |
|---|---|
| **Type** | Refactor / Type safety |
| **Priority** | Low |
| **Status** | To Do |
| **Component** | Domain / Enum usage |
| **Estimate** | 10 min |
| **Reporter** | Dylan |
| **Spec** | – |
| **Related** | [WOLF-103](WOLF-103-declare-strict-types.md) (strict typing baseline) |

## Summary

Two production code paths filter devices by hardware type using a raw
string literal instead of the `DeviceType` enum that already models that
value. Replace both with `DeviceType::Esp8266->value` so the enum is the
single source of truth and rename-safe under IDE refactor tools.

## Background

`DeviceType` is a backed enum defined in `app/Enums/DeviceType.php`:

```php
enum DeviceType: string
{
    case Esp32Cam = 'esp32_cam';
    case Esp8266 = 'esp8266';
    // ...
}
```

The `Device` model casts the `type` column to this enum
(`'type' => DeviceType::class`), so reading `type` on a hydrated model
returns an enum instance. But two write-adjacent sites still filter by
the raw string:

- `app/Http/Controllers/GeoFenceController.php:101`
  ```php
  $esp = $request->user()->devices()->where('type', 'esp8266')->first();
  ```
- `app/Jobs/TriggerScheduledGeofenceJob.php:38`
  ```php
  $esp = $fence->user->devices()->where('type', 'esp8266')->first();
  ```

The enum declaration itself (case name → value mapping) is untouched;
it's the source of truth.

## Failure modes this prevents

1. **Rename desync.** If `DeviceType::Esp8266 = 'esp8266'` is ever
   renamed (say `= 'esp8266_relay'` to clarify the actual hardware
   role), the two string-literal sites don't move with the enum.
   Devices get created with the new value; these queries never find
   them; the native-geofence trigger path silently returns no devices
   and no servo fires. This is exactly the class of bug enums exist
   to prevent.
2. **Grep drift.** Global searches for uses of `DeviceType::Esp8266`
   don't find these two call sites. Refactor-safety tools (IDE
   "find usages") also miss them.
3. **Interview-readable smell.** A reviewer greps `where('type',`
   and immediately spots the string literal next to an existing enum
   — the fix is one line and the story is one sentence.

## Acceptance criteria

- [x] `app/Http/Controllers/GeoFenceController.php:101` uses
      `DeviceType::Esp8266->value` in place of `'esp8266'`.
- [x] `app/Jobs/TriggerScheduledGeofenceJob.php:38` uses
      `DeviceType::Esp8266->value` in place of `'esp8266'`.
- [x] Both files import `use App\Enums\DeviceType;` (inserted
      alphabetically in the existing use block).
- [x] No behavioral change — verified: 145/145 tests pass, 381
      assertions unchanged.
- [x] No changes to the `DeviceType` enum, migrations, or the
      `Device` model.

## Out of scope

- **Passing the enum instance directly** (`->where('type',
  DeviceType::Esp8266)`). Laravel 11's query builder does unwrap
  BackedEnum arguments automatically, but relying on framework magic
  reads less obviously than an explicit `->value`. Explicit wins for
  senior-legible code; picking one and documenting the choice is
  worth more than debating both.
- **Test-suite string literals.** `tests/Feature/GeoFenceTest.php`
  and other test files pass `'esp8266'` to factory states. Tests
  intentionally freeze the wire format they're asserting against;
  changing them makes the tests less honest, not more. Leave.
- **Migration string literals** (`2026_06_08_170123_normalize_
  device_type_values.php`). Migrations run once against the DB
  schema at the point in time they were written; retroactively
  referencing an enum that could later rename would break historical
  correctness. Leave.
- **Introducing a scope on the `Device` model** (e.g.
  `Device::scopeEsp8266`). Nice-to-have and defensible either way,
  but it's a design decision, not a mechanical fix. Deferred.

## Effort breakdown

| Step | Estimate |
|---|---|
| Edit two files (import + one-line change each) | 3 min |
| Run `composer test` to confirm no regression | 5 min |
| Verify emitted SQL identical (spot-check via Telescope or Log::info) | 2 min (skipped if tests green) |

## Sequencing

Independent from every other ticket. Ships on its own branch.

## Notes

- **Why not pass the enum directly?** As noted in Out of Scope, the
  framework unwraps it. Explicit `->value` reads better for a
  reviewer scanning the diff cold — no need to know Laravel version-
  specific query builder behavior.
- **Why not touch tests?** They assert the wire value; they should
  keep asserting the wire value. If the enum value ever changes,
  tests SHOULD break to prove the wire-facing contract also changed.
