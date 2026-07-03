# WOLF-103 · Add `declare(strict_types=1);` to all app + test PHP files

| Field | Value |
|---|---|
| **Type** | Refactor / Type safety |
| **Priority** | Medium |
| **Status** | To Do |
| **Component** | Codebase-wide (PHP) |
| **Estimate** | 15 min |
| **Reporter** | Dylan |
| **Spec** | – |
| **Related** | Production-hardening batch (Wave 1) |

## Summary

Add `declare(strict_types=1);` as the first statement in every first-party
PHP file (under `app/`, `database/`, `tests/`, `bootstrap/`, `config/`, and
`routes/`). Enforces strict scalar type-coercion rules per PHP 8.3 best
practice: parameters and return values must match declared types exactly
rather than being silently coerced (e.g. `int` accepting a numeric string).

## Background

None of the project's PHP files currently declare strict types. Sampled:

```
$ grep -rL 'declare(strict_types=1);' app/ tests/ | head
app/Console/Commands/CheckStaleDevicesCommand.php
app/Console/Commands/MqttListenCommand.php
app/Contracts/DeviceInterface.php
app/Enums/DeviceType.php
app/Events/DeviceStatusChanged.php
...
```

Without `strict_types`, PHP silently coerces types across function
boundaries. Concrete example from this repo: `GeoFence::contains(float,
float): bool` (`app/Models/GeoFence.php:67`) will accept `contains("40.7",
"-74")` today because PHP coerces the strings into floats. That works —
until a caller passes a truly non-numeric string and the coercion becomes
`0.0`, silently placing every point at `(0, 0)` and returning wrong
"inside/outside" answers.

Strict types would raise a `TypeError` at the boundary instead of silently
coercing, which is the correct failure mode for hardware-triggering code.

## Failure modes this prevents

1. **Silent coercion in geo math.** As described above — non-numeric strings
   becoming `0.0` inside distance / containment calculations.
2. **Silent coercion in device IDs.** `Device::verifyToken(string)` would
   coerce non-strings today; strict mode surfaces the misuse immediately.
3. **Regression under refactor.** Extracting logic into services (Wave 3)
   without strict typing means signatures drift silently over time.

## Acceptance criteria

- [ ] Every file under `app/`, `tests/`, `database/`, `bootstrap/`,
      `config/`, and `routes/` begins with `<?php\n\ndeclare(strict_types=1);\n`
      as the first two lines (after `<?php`).
- [ ] `artisan` and any framework-owned files that require specific
      shebang / header shapes are left alone.
- [ ] `composer test` still passes with zero regressions.
- [ ] No files are missed — a grep confirms:
      `grep -rL 'declare(strict_types=1);' app/ tests/ database/ bootstrap/ config/ routes/ | wc -l` returns `0`.

## Out of scope

- **`vendor/` and `node_modules/`** — third-party code, do not touch.
- **PHPStan strict-mode configuration** — full type-static analysis is a
  separate concern; this ticket only asks for the runtime declaration.
- **Fixing any type errors that surface** — the acceptance criterion is
  that tests still pass. If tests fail because a coercion was hiding a real
  bug, that bug moves to its own follow-up ticket (do not silently `(string)`-cast around it).

## Effort breakdown

| Step | Estimate |
|---|---|
| Mechanical add of `declare(strict_types=1);` (script-assisted) | 5 min |
| Run full test suite | 5 min |
| Fix any narrow signature mismatches surfaced (if trivial) | 5 min |

## Sequencing

Runs first in Wave 1 alongside WOLF-104 (test attributes). Both are
codebase-wide mechanical touches — landing them early avoids merge
conflicts with later, semantically-focused tickets.

## Notes

- Rollback is a `git revert` — the change is purely additive text.
- If a test does surface a coercion-hidden bug, do not paper over it. Log
  the file/line in the PR body and open a follow-up ticket.
- This ticket **cannot** be authored via a blanket `sed` on the vendor
  directory or on files that already have `declare(strict_types=1);` — the
  implementer must scope to first-party paths only.
