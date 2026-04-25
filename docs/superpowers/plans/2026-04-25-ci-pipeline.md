# CI Pipeline Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a GitHub Actions CI pipeline that runs tests, linting, and build checks on every push and PR to `main`.

**Architecture:** Single workflow file with 3 parallel jobs: test (PHPUnit with SQLite in-memory), lint (Pint code style check), and build (TypeScript + Vite frontend compilation). Tests use SQLite in-memory per `phpunit.xml` — no MySQL or Redis services needed in CI.

**Tech Stack:** GitHub Actions, PHP 8.4, Node 20, Composer, npm

**Constraints:**
- Do NOT run `git add`, `git commit`, or `git push` — the user handles all git operations manually.
- All code changes happen in `/Users/mr.casanova/Code/wolf`.
- Repo: `Dylan-Casanova/wolf` on GitHub, main branch is `main`.

---

## File Map

| File | Action | Responsibility |
|---|---|---|
| `.github/workflows/ci.yml` | Create | GitHub Actions workflow — 3 parallel jobs: test, lint, build |

---

### Task 1: Create CI Workflow

**Files:**
- Create: `.github/workflows/ci.yml`

- [ ] **Step 1: Create the workflows directory**

```bash
mkdir -p .github/workflows
```

- [ ] **Step 2: Create the CI workflow file**

Create `.github/workflows/ci.yml`:

```yaml
name: CI

on:
  push:
    branches: [main]
  pull_request:
    branches: [main]

jobs:
  test:
    name: Tests
    runs-on: ubuntu-latest

    steps:
      - uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
          extensions: mbstring, sqlite3, pdo_sqlite, bcmath, pcntl, sockets, intl, gd, zip
          coverage: none

      - name: Cache Composer dependencies
        uses: actions/cache@v4
        with:
          path: vendor
          key: composer-${{ hashFiles('composer.lock') }}
          restore-keys: composer-

      - name: Install Composer dependencies
        run: composer install --no-interaction --prefer-dist

      - name: Prepare environment
        run: |
          cp .env.example .env
          php artisan key:generate

      - name: Run tests
        run: php artisan test

  lint:
    name: Lint
    runs-on: ubuntu-latest

    steps:
      - uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
          coverage: none

      - name: Cache Composer dependencies
        uses: actions/cache@v4
        with:
          path: vendor
          key: composer-${{ hashFiles('composer.lock') }}
          restore-keys: composer-

      - name: Install Composer dependencies
        run: composer install --no-interaction --prefer-dist

      - name: Run Pint
        run: ./vendor/bin/pint --test

  build:
    name: Build
    runs-on: ubuntu-latest

    steps:
      - uses: actions/checkout@v4

      - name: Setup Node
        uses: actions/setup-node@v4
        with:
          node-version: 20
          cache: npm

      - name: Install npm dependencies
        run: npm ci

      - name: Build frontend
        run: npm run build
```

- [ ] **Step 3: Verify the file is valid YAML**

```bash
cat .github/workflows/ci.yml
```

Visually confirm indentation is correct and no syntax errors.

- [ ] **Step 4: Verify the workflow will trigger correctly**

The workflow triggers on:
- `push` to `main` — runs after merging a PR
- `pull_request` to `main` — runs when a PR is opened or updated

The 3 jobs run in parallel:
- `test` — installs PHP 8.4, Composer deps, copies `.env.example`, generates app key, runs `php artisan test` (SQLite in-memory per `phpunit.xml`)
- `lint` — installs PHP 8.4, Composer deps, runs `./vendor/bin/pint --test` (fails if code style issues exist)
- `build` — installs Node 20, npm deps, runs `npm run build` (TypeScript check + Vite build)

---

## Verification

After the file is created, the user pushes to GitHub and opens a PR. The workflow should:
- Appear in the Actions tab of the `Dylan-Casanova/wolf` repo
- Show 3 parallel jobs: Tests, Lint, Build
- All 3 should pass (40 tests, clean Pint, successful build)
