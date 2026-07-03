# WOLF-116 · Enforce `declare(strict_types=1);` via CI guardrail

| Field | Value |
|---|---|
| **Type** | Task / CI |
| **Priority** | Medium |
| **Status** | To Do |
| **Component** | CI / Lint |
| **Estimate** | 20 min |
| **Reporter** | Dylan |
| **Spec** | – |
| **Related** | [WOLF-103](WOLF-103-declare-strict-types.md) (initial adoption) |

## Summary

WOLF-103 added `declare(strict_types=1);` to every first-party PHP file.
Without a CI guardrail, a new file added tomorrow (or a `make:controller`
scaffold from Artisan, or a `git revert`) can silently drop the
declaration in one file and nobody notices until strict-type coercion
surfaces a bug in production. Add a portable shell check, wire it as a
Composer script for local use, and add a CI step that runs it on every
push and PR.

## Background

Today's CI (`.github/workflows/ci.yml`) has three jobs:

- `test` — runs `php artisan test`
- `lint` — runs `./vendor/bin/pint --test`
- `build` — runs `npm run build`

Pint has excellent formatting rules but does **not** enforce
`declare(strict_types=1);` at the top of files. There is no existing
mechanism — pre-commit hook, git hook, CI job — that would catch a
missing declaration in a new file.

The pre-commit hook (`.githooks/pre-commit`) runs Pint on staged files
only. Even if Pint were extended, the hook operates on staged files, so
a file added via a Composer script that isn't staged separately would
slip through.

## Failure modes this prevents

1. **New file drops strict_types.** A developer runs
   `php artisan make:controller Foo`; Laravel's stub does not include
   `declare(strict_types=1);`; the file lands in a PR; reviewer misses
   it; strict types quietly erode.
2. **Merge / revert drops the declaration.** A rebase or revert removes
   the declaration on a subset of files; without a check, this is only
   discoverable by an eyeball diff, not automatable.
3. **Silent coercion regressions.** Once strict types are absent, PHP
   accepts silent string → int/float coercions across function
   boundaries. In this repo the highest-risk site is the geo math
   (`GeoFence::contains`, `distanceFromCenter`) — losing strict types
   there would silently accept non-numeric input as `0.0`.

## Solution

Three additions:

1. **`scripts/check-strict-types.sh`** — portable bash script that
   greps every first-party PHP file (`app/`, `tests/`, `database/`,
   `bootstrap/`, `config/`, `routes/`) for `declare(strict_types=1);`,
   prints the offenders, and exits non-zero if any are missing. Excludes
   `bootstrap/cache/*` (framework-generated).
2. **Composer script alias `lint:strict-types`** in `composer.json`
   so devs can run `composer lint:strict-types` locally. Also invoked
   by CI to keep one source of truth for the command.
3. **New step in the `lint` CI job** that runs
   `composer lint:strict-types`, colocated with the existing
   `./vendor/bin/pint --test` step.

## Acceptance criteria

- [x] `scripts/check-strict-types.sh` exists, is executable, and:
  - Exits `0` when every first-party PHP file has
    `declare(strict_types=1);`.
  - Exits `1` with a readable list of offenders otherwise.
  - Correctly excludes `bootstrap/cache/*.php`.
- [x] `composer.json` includes a script alias:
  `"lint:strict-types": ["bash scripts/check-strict-types.sh"]`.
- [x] `.github/workflows/ci.yml`'s `lint` job runs
  `composer lint:strict-types` after `./vendor/bin/pint --test`.
- [x] On the current master (which has WOLF-103 merged),
  `composer lint:strict-types` reports **0 offenders** and exits 0.
- [x] Manual regression: mutated `app/Models/User.php` to drop the
      declaration; `composer lint:strict-types` exited **1** and
      printed `- app/Models/User.php` as the offender with a
      reproduce hint. File restored; re-check passes.
- [ ] Existing CI passes end-to-end after the addition (verify on the
      PR — cannot verify locally).

## Out of scope

- **Extending the pre-commit hook.** Nice-to-have follow-up; the CI
  guardrail is the enforcement floor. Adding it to `pre-commit` too
  is a separate ticket because it needs to work with staged files
  only.
- **Enforcing strict_types on `vendor/`, `node_modules/`, or the
  `bootstrap/cache/*` files.** Not first-party code.
- **PHPStan / Psalm integration.** Would give richer type safety but
  is a larger design decision — separate ticket.
- **Failing CI on `resources/views/*.blade.php`.** Blade templates are
  not PHP files subject to strict types.

## Effort breakdown

| Step | Estimate |
|---|---|
| Write `scripts/check-strict-types.sh` | 5 min |
| Add composer script alias + verify locally | 5 min |
| Add CI step to `lint` job in `.github/workflows/ci.yml` | 5 min |
| Manual regression verification (remove a declaration, run check, restore) | 5 min |

## Sequencing

Depends on WOLF-103 being merged (so the guardrail passes on master).
Independent from every other Wave 1–3 ticket.

## Notes

- **Rollback:** trivial. Delete the script + composer alias + CI step.
- The script uses only POSIX tools (`find`, `grep`) — works on the
  Ubuntu CI runner and macOS dev machines without further deps.
- We deliberately do **not** integrate this into Pint's rule set even
  though Pint has a `declare_strict_types` rule. That rule would
  auto-fix by inserting the declaration silently, which defeats the
  guardrail's purpose: we want the check to fail loudly and force a
  human decision.
