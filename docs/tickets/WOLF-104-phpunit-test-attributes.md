# WOLF-104 · Migrate test suite to PHPUnit `#[Test]` attributes

| Field | Value |
|---|---|
| **Type** | Refactor / Test style |
| **Priority** | Medium (visual signal — reviewers grep tests first) |
| **Status** | To Do |
| **Component** | Test suite |
| **Estimate** | 45 min |
| **Reporter** | Dylan |
| **Spec** | – |
| **Related** | Production-hardening batch (Wave 1) |

## Summary

Every test method in the suite (145 across 22 files) declares itself as a
test via the legacy `test_` method-name prefix. PHPUnit 10+ prefers the
`#[Test]` attribute for explicit intent, and PHPUnit 12 (which this repo
runs) documents the attribute style as the modern default. Convert every
test method to use `#[Test]` and drop the redundant `test_` prefix from
the method name.

## Background

Current state — sample from `tests/Feature/ProfileTest.php`:

```php
public function test_profile_page_is_displayed(): void
public function test_profile_information_can_be_updated(): void
```

Target state:

```php
#[Test]
public function profile_page_is_displayed(): void

#[Test]
public function profile_information_can_be_updated(): void
```

Post-migration each test file gains one import:

```php
use PHPUnit\Framework\Attributes\Test;
```

Discovery survey (executed against a clean working tree):

- 145 test methods across 22 feature/unit files
- 0 `@test` docblock annotations (no legacy annotations to migrate)
- 0 `@dataProvider` annotations (`#[DataProvider]` migration not needed)
- All method names are snake_case with `test_` prefix (uniform)

## Failure modes this prevents / signal delivered

1. **Reviewer-visible modernness.** Interview reviewers scan test files
   first. `test_` prefix reads as PHPUnit 8-era; `#[Test]` attribute reads
   as current-generation.
2. **Explicit intent under refactor.** With `#[Test]`, renaming a method
   to `authenticated_user_can_trigger_garage` (dropping the `test_`
   prefix) doesn't unregister it from the runner. The current convention
   *silently loses* a test if a rename accidentally drops the prefix.
3. **Enables future attribute additions.** Once `#[Test]` is present,
   adding `#[Group('geofence')]`, `#[DataProvider('...')]`, or
   `#[Depends]` requires no additional import.

## Acceptance criteria

- [ ] Every test method in `tests/` has a `#[Test]` attribute on the
      line immediately above the method signature.
- [ ] The `test_` prefix is dropped from every method name (
      `test_profile_page_is_displayed` → `profile_page_is_displayed`).
- [ ] Snake_case is preserved — do not camelCase-rename in the same
      pass. Renaming style is a separate stylistic call and mixing it
      into this ticket inflates the diff.
- [ ] Every test file imports `PHPUnit\Framework\Attributes\Test`,
      inserted alphabetically within the existing `use` block (PSR-12
      order).
- [ ] `composer test` (or `vendor/bin/phpunit`) reports the same
      `145 tests, 381 assertions` — no discovery drop, no accidental
      unregistration.
- [ ] No new dependencies added; no `phpunit.xml` changes.
- [ ] Non-test methods (setUp/tearDown, private helpers, etc.) are
      untouched.

## Out of scope

- **CamelCase renaming.** Style call for a separate pass. Snake_case
  reads better for long behavior-describing test names; if we ever
  standardize on camelCase, do it as one focused ticket.
- **`#[Group]`, `#[CoversClass]`, `#[Depends]`, `#[DataProvider]`
  attribute additions.** No existing usage to migrate; adding new
  grouping is a design decision, not a mechanical migration.
- **Reordering imports beyond inserting the new `use`.** Do not
  reflow the whole use block — that pollutes the diff with unrelated
  churn.
- **Splitting fat test files.** Some files (`GeoFenceTest.php`) are
  large; splitting is a separate design decision.

## Effort breakdown

| Step | Estimate |
|---|---|
| Script the transformation (Python regex over 22 files) | 15 min |
| Manual spot-check on 3–5 representative files | 10 min |
| Run `vendor/bin/phpunit`, confirm 145/145 | 5 min |
| Investigate + fix any discovery drops or naming collisions | 15 min buffer |

## Sequencing

Runs second in Wave 1, after WOLF-103 lands. Independent from every
other ticket, but does touch every test file — merging it early minimizes
conflict surface with WOLF-109/110/111 (Wave 2) and WOLF-112/113/114
(Wave 3), which will add new tests.

## Notes

- **Rollback:** trivial — `git revert` the branch. No schema, no config.
- **Rename safety:** if two methods in the same file only differ by the
  `test_` prefix (e.g. a private helper `test_helper` — unlikely but
  worth grep-checking), the script must skip the private helper and only
  rename the public one.
- **PSR-12 use-order:** insertion should keep alphabetical order:
  `PHPUnit\Framework\Attributes\Test` lands after any `Mockery` and
  before any `Tests\` imports in most files.
- **`#[Test]` under strict types:** the attribute has no runtime effect
  on typed code; WOLF-103 (already merged locally) is unaffected.
