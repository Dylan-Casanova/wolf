# WOLF-087 · Introduce `StreamStatus` enum; replace magic strings in stream code paths

## Summary

The `Stream` lifecycle passes through three well-defined states —
`pending`, `active`, `ended` — but the states are represented as raw
strings scattered across the model, two controllers, a console command,
a migration default, and a broadcast event. Introduce a
`StreamStatus` backed enum, cast the `Stream::status` attribute to it,
and replace every production-code string literal with the enum
reference. Tests and the migration keep their string values (same
rationale as WOLF-105).

## Background

State-transition survey — where the strings appear today:

**Production code (10 sites):**

| File | Line | Usage |
|---|---|---|
| `app/Models/Stream.php` | 23–26 | `$casts` block does not include `status` |
| `app/Http/Controllers/StreamController.php` | 31 | `'status' => 'pending'` on create |
| `app/Http/Controllers/StreamController.php` | 44 | `if ($stream->status === 'ended')` |
| `app/Http/Controllers/StreamController.php` | 51 | `'status' => 'ended'` on update |
| `app/Http/Controllers/StreamFeedController.php` | 20 | `abort_if($stream->status === 'ended', ...)` |
| `app/Http/Controllers/StreamFeedController.php` | 22 | `if ($stream->status === 'pending')` |
| `app/Http/Controllers/StreamFeedController.php` | 23 | `->update(['status' => 'active', ...])` |
| `app/Console/Commands/CheckStaleDevicesCommand.php` | 50 | `->whereIn('status', ['active', 'pending'])` |
| `app/Console/Commands/CheckStaleDevicesCommand.php` | 53 | `$q->where('status', 'active')` |
| `app/Console/Commands/CheckStaleDevicesCommand.php` | 56 | `$q->where('status', 'pending')` |
| `app/Console/Commands/CheckStaleDevicesCommand.php` | 63 | `->update(['status' => 'ended', ...])` |
| `app/Console/Commands/CheckStaleDevicesCommand.php` | 73 | `->where('status', 'ended')` |

**Left alone (documented in Out of Scope):**

- The migration default (`->default('pending')`) — historical DB
  constant, changing it retroactively would rewrite history.
- Tests — same argument as WOLF-105; tests assert the wire format
  and should keep asserting strings.
- The `'stopped'` reason passed to `broadcast(new StreamEnded($stream->id, 'stopped'))`
  in `StreamController::stop` — this is a *reason for termination*,
  not a status. Different concept; separate ticket if we want to
  formalize reasons.

## Failure modes / signal

1. **Typos silently break comparisons.** `if ($stream->status === 'endedd')`
   is legal PHP and always false — no compiler will catch it. Enums
   catch it at parse time.
2. **Rename desync.** If `'active'` is ever renamed to `'streaming'`,
   the migration + code + tests all have to move together. With an
   enum, most callers move via find-and-replace on the case name,
   and only the enum value + migration + tests remain as freeze points.
3. **State-graph legibility.** Reviewer skimming the enum file sees
   the total state space in three lines instead of having to grep
   across five files.
4. **IDE integration.** Autocomplete on `StreamStatus::` surfaces the
   valid cases; string literals get no completion.

## Solution

**New file** `app/Enums/StreamStatus.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum StreamStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Ended = 'ended';
}
```

**Model cast** (`app/Models/Stream.php`):

```php
protected $casts = [
    'status' => StreamStatus::class,
    'started_at' => 'datetime',
    'ended_at' => 'datetime',
];
```

**Controllers / command** — replace every literal per the survey table.
Write sites (`'status' => 'x'`) become `StreamStatus::X`; comparison
sites (`$stream->status === 'x'`) become `$stream->status === StreamStatus::X`
because the cast returns enum instances. Query-builder sites (
`->where('status', 'x')`) can accept either the enum instance (Laravel
11+ unwraps) or `->value`; use the enum instance for read-legibility
consistency across the file.

## Failure-mode consideration — will tests break?

Grep confirms **no test does `$stream->status === 'x'` comparisons**;
every test either:
- Sets status via factory / update using string literals (Laravel
  auto-casts on write to accept either).
- Asserts wire format via `assertJson(['status' => 'x'])` or
  `assertDatabaseHas(['status' => 'x'])` — both operate on the DB or
  the JSON-serialized string, unaffected by the cast.

So the cast is safe to add without a coordinated test update.

## Acceptance criteria

- [ ] `app/Enums/StreamStatus.php` exists with three cases:
      `Pending = 'pending'`, `Active = 'active'`, `Ended = 'ended'`.
- [ ] `app/Models/Stream.php` `$casts` array includes
      `'status' => StreamStatus::class`.
- [ ] All 5 production-code files (StreamController, StreamFeedController,
      CheckStaleDevicesCommand, and any import sites) reference
      `StreamStatus::Case` — no `'pending'`, `'active'`, `'ended'`
      string literals remain in production code paths under `app/`.
- [ ] `composer test` reports 145/145 unchanged (no wire-format break).
- [ ] Grep verification:
      `grep -rn "'pending'\|'active'\|'ended'" app/ | wc -l` returns
      only the enum case declarations themselves (3 lines in the enum
      file). Any other match is a bug.

## Out of scope

- **Migration string literal `->default('pending')`.** DB-side default
  is a historical constant.
- **Tests.** Same argument as WOLF-105 — tests assert the wire
  contract; leaving them as strings makes wire-shape breaks visible.
- **`'stopped'` reason in `broadcast(new StreamEnded(..., 'stopped'))`.**
  Different concept (termination cause, not lifecycle state).
- **Adding `label()`, `values()`, `options()` methods** like
  `DeviceType`. No picker UI consumes StreamStatus. Add only if a
  frontend surface needs it later.
- **State transition validation** (e.g. "cannot go from Ended back to
  Active"). Nice-to-have model concern; deferred.

## Effort breakdown

| Step | Estimate |
|---|---|
| Create `StreamStatus` enum | 3 min |
| Update `Stream` model cast | 2 min |
| Edit 3 files' status references (StreamController, StreamFeedController, CheckStaleDevicesCommand) | 8 min |
| Add `use App\Enums\StreamStatus;` imports where needed | 2 min |
| Run test suite | 5 min |

## Sequencing

Last ticket in Wave 1. Independent from every other ticket. Ships on
its own branch. Prerequisite for **WOLF-113 (extract StreamService)** —
the service layer will reference the enum, so landing it first keeps
that PR's diff small.

## Notes

- **Why cast to enum instances instead of using `->value` on writes?**
  Laravel's query builder and Eloquent both unwrap backed enums to
  their scalar values on write and rehydrate to enum instances on
  read. Passing the enum instance to writers keeps caller-side code
  homogeneous ("I always deal in enums").
- **Why not enforce the state graph in the enum?** Two reasons: (1)
  enums are values, not state machines — mixing responsibilities;
  (2) the transitions are called from disparate contexts (controller,
  console command). If we want a state machine, it belongs in the
  Stream model or a dedicated aggregate — Wave 3 territory at
  earliest.
