#!/usr/bin/env bash
# WOLF-079 — Enforce declare(strict_types=1); across first-party PHP files.
#
# Exits 0 when every scanned file has the declaration; exits 1 with a
# readable list of offenders otherwise.
#
# Scope: app/, tests/, database/, bootstrap/, config/, routes/
# Excludes: bootstrap/cache/* (framework-generated)

set -euo pipefail

ROOTS=(app tests database bootstrap config routes)
EXCLUDE_PATH="bootstrap/cache"
NEEDLE="declare(strict_types=1);"

# Change to repo root regardless of invocation directory.
cd "$(dirname "$0")/.."

# Collect first-party PHP files, excluding the cache directory.
missing=$(
    find "${ROOTS[@]}" -type f -name '*.php' -not -path "${EXCLUDE_PATH}/*" -print0 \
        | xargs -0 grep -L -- "${NEEDLE}" \
        || true
)

if [ -n "${missing}" ]; then
    echo "Files missing '${NEEDLE}':" >&2
    echo "${missing}" | sed 's/^/  - /' >&2
    echo "" >&2
    echo "Run \`composer lint:strict-types\` locally to reproduce." >&2
    exit 1
fi

echo "OK — every first-party PHP file declares strict_types."
