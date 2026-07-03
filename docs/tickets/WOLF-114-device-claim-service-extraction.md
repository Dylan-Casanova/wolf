# WOLF-099 · Extract `DeviceClaimService` — thin controller, typed claim outcomes

## Summary

`DeviceClaimController::store` inlines three business-rule branches
plus a mutation:

1. Device with the given `device_id` doesn't exist.
2. User already owns this device.
3. Device is already claimed by another user.
4. Otherwise, claim the device.

Extract into a `DeviceClaimService` whose `claim(User, deviceId)`
method returns a typed `DeviceClaimResult` enum. Controller becomes
a `match` expression that maps each outcome to the appropriate
Inertia response.

## Background

Current `DeviceClaimController::store` (~25 lines):

```php
public function store(Request $request)
{
    $validated = $request->validate([
        'device_id' => ['required', 'string'],
    ]);

    $device = Device::where('device_id', $validated['device_id'])->first();

    if (! $device) {
        return back()->withErrors(['device_id' => 'Device not found.']);
    }

    if ($device->user_id === $request->user()->id) {
        return back()->withErrors(['device_id' => 'You already own this device.']);
    }

    if ($device->user_id !== null) {
        return back()->withErrors(['device_id' => 'Device is already claimed.']);
    }

    $device->update(['user_id' => $request->user()->id]);

    return redirect()->route('dashboard');
}
```

Four outcomes, four inline branches, one mutation — all in the HTTP
handler.

## Failure modes / signal

1. **Domain rule locked to HTTP context.** If a future admin-tool or
   CLI wants to claim a device on behalf of a user, it has to
   re-implement the three guards.
2. **Untyped return values.** Today the controller returns three
   different `back()->withErrors(...)` responses plus one
   `redirect()->route('dashboard')`. There's no first-class
   representation of "what happened" — it's implicit in the response
   shape.
3. **Interview signal.** With `GeoFenceService` (WOLF-112) and
   `StreamService` (WOLF-113) in place, `DeviceClaimController`
   sticks out as the last controller doing domain work inline.

## Solution

**New enum** `app/Enums/DeviceClaimResult.php` — outcomes, not
errors:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum DeviceClaimResult
{
    case Claimed;
    case DeviceNotFound;
    case AlreadyOwned;
    case AlreadyClaimed;
}
```

**New service** `app/Services/DeviceClaimService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DeviceClaimResult;
use App\Models\Device;
use App\Models\User;

class DeviceClaimService
{
    public function claim(User $user, string $deviceId): DeviceClaimResult
    {
        $device = Device::where('device_id', $deviceId)->first();

        if (! $device) {
            return DeviceClaimResult::DeviceNotFound;
        }

        if ($device->user_id === $user->id) {
            return DeviceClaimResult::AlreadyOwned;
        }

        if ($device->user_id !== null) {
            return DeviceClaimResult::AlreadyClaimed;
        }

        $device->update(['user_id' => $user->id]);

        return DeviceClaimResult::Claimed;
    }
}
```

**Controller** collapses to a `match`:

```php
class DeviceClaimController extends Controller
{
    public function __construct(private DeviceClaimService $service) {}

    public function create()
    {
        return Inertia::render('Devices/Claim');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'device_id' => ['required', 'string'],
        ]);

        $result = $this->service->claim($request->user(), $validated['device_id']);

        return match ($result) {
            DeviceClaimResult::Claimed => redirect()->route('dashboard'),
            DeviceClaimResult::DeviceNotFound => back()->withErrors(['device_id' => 'Device not found.']),
            DeviceClaimResult::AlreadyOwned => back()->withErrors(['device_id' => 'You already own this device.']),
            DeviceClaimResult::AlreadyClaimed => back()->withErrors(['device_id' => 'Device is already claimed.']),
        };
    }
}
```

## Design decisions to defend

1. **Return `DeviceClaimResult` enum, not exceptions.** These aren't
   errors — a user trying to claim their own device is a normal
   outcome, not exceptional flow. Enums give typed exhaustiveness
   (the controller's `match` won't compile if a new case is added
   and forgotten).

2. **Unbacked (unit) enum, not string-backed.** The result names are
   *domain outcomes*, not wire values. Nothing outside this codebase
   ever needs to serialize them. Unbacked keeps the ceremony minimal.

3. **Response strings stay in the controller.** The user-facing
   `"Device not found."` copy belongs at the HTTP boundary — it's
   presentation, not domain. If we later add an API or CLI, they'd
   have their own strings (or none). Service is copy-free.

4. **No FormRequest for `device_id`.** The current validation is one
   rule (`required, string`). Extracting a `ClaimDeviceRequest`
   would be pure ceremony for one rule. Follow-up if the shape
   grows.

5. **`ClaimResult::AlreadyOwned` vs `AlreadyClaimed`.** Two distinct
   outcomes because the user-facing message and possibly the future
   analytics story differ. Merging them into `NotClaimable` would
   lose signal without saving code.

## Behavior guarantees

- Same 4 responses:
  - Successful claim → 302 redirect to `dashboard`.
  - Not found / already owned / already claimed → 302 back with the
    same error messages as today.
- Existing `tests/Feature/DeviceClaimTest.php` continues to pass
  unchanged.

## Acceptance criteria

- [ ] `app/Enums/DeviceClaimResult.php` exists with 4 cases.
- [ ] `app/Services/DeviceClaimService.php` exists with a single
      `claim(User, string): DeviceClaimResult` method.
- [ ] `DeviceClaimController` no longer contains the three inline
      `if` guards.
- [ ] `DeviceClaimController::store` uses `match ($result) {...}` to
      map outcomes to responses.
- [ ] `composer test` reports 145/145 unchanged.

## Out of scope

- **`ClaimDeviceRequest` FormRequest.** One-rule validation stays
  inline; matches the scope guideline throughout the batch.
- **Applying the same pattern to `DeviceController`** (admin CRUD).
  It's mostly Inertia scaffolding plus `regenerateToken`; the
  refactor would be pure form without adding signal.
- **Adding a `Device::isUnclaimed()` model method.** Cute helper but
  the service is the only caller today; local branching reads more
  honestly.
- **Emitting domain events on claim** (e.g. `DeviceClaimed`). None
  needed today; add when a subscriber exists.

## Effort breakdown

| Step | Estimate |
|---|---|
| Create enum + service | 8 min |
| Refactor controller | 5 min |
| Run test suite | 3 min |
| Grep-verify | 4 min |

## Sequencing

Third and last ticket in Wave 3. Independent from WOLF-112 / WOLF-113.
Closes the service-layer batch.

## Notes

- **Interview readiness:** `match` on a typed enum is one of the
  cleanest patterns PHP 8 offers. Reviewer looking at
  `DeviceClaimController::store` sees exactly four possible outcomes
  in a single expression — instant intent legibility.
- **Rollback risk:** near-zero. Small surface, small test coverage
  footprint.
