# Interview Prep Workflow

Working system for the Senior Software Engineer "bring your own code" interview.
Runs section-by-section over the Wolf repo, producing both a production-hardened
codebase and complete author-level ownership of every subsystem.

| Field | Value |
|---|---|
| **Target interview window** | 2026-07-04 to 2026-07-06 |
| **Repo under review** | `Dylan-Casanova/wolf` |
| **Current branch base** | `master` (prod-ready cut) |
| **Working branch prefix** | `feature/wolf-{ticket}` (matches existing convention) |

---

## Two tracks, run in parallel

### 1. Refactor track — "make it production-ready"

For each subsystem, apply a small set of best-practice fixes that a senior reviewer
would immediately spot. One ticket per fix, one PR per ticket, small enough to
eyeball-verify.

### 2. Ownership track — "explain every line"

For each subsystem, produce a walkthrough note the author can defend cold.
Notes live in `docs/interview-prep/walkthrough/<section>.md`.

The two tracks converge: **the refactor forces the deep understanding, the notes
capture what was learned**.

---

## The bucketing rule

For every finding in code review, assign exactly one of:

| Bucket | Meaning | Artifact |
|---|---|---|
| **Must-fix** | A real bug, an unwired security control, an inconsistent public shape, or dead code that would be flagged under questioning. | Ticket + PR. |
| **Defensible** | Not a bug; a deliberate tradeoff or acceptable-for-scale choice. Interviewer may still ask about it. | Q&A entry in `docs/interview-prep/INTERVIEW-QA.md`. |
| **YAGNI** | Over-engineering suggestion, aesthetic preference, or hypothetical-future guard. | Ignore. |

**Guardrail:** never accept a change unless the author can defend it in one sentence
without prompting. If a change can't be owned, it becomes an anti-signal — better to
leave the original code and prep the "why" answer.

---

## Section list

Progress tracked in [STATUS.md](STATUS.md).

| # | Section |
|---|---|
| 1 | Bootstrap & request lifecycle |
| 2 | Config layer |
| 3 | Auth — web (Breeze/session) + mobile (Sanctum V1) |
| 4 | Middleware & authorization |
| 5 | Device domain |
| 6 | Streaming |
| 7 | Garage control |
| 8 | Geofence system |
| 9 | MQTT integration |
| 10 | Broadcasting (Reverb + channels + Echo) |
| 11 | API layer |
| 12 | Frontend (Inertia + React + Tailwind + Leaflet) |
| 13 | Migrations & DB evolution |
| 14 | Testing strategy |
| 15 | Deployment & ops |
| 16 | Critical review / interview attack surface |

---

## Ticket lifecycle

```
draft (WOLF-XXX)  →  numbered (WOLF-100+)  →  in-progress  →  ready-for-review  →  merged
```

- Draft tickets live under `docs/tickets/WOLF-XXX-*.md` until numbered.
- Once assigned a number, rename the file and set **Status** in the header table.
- On merge, add a `## Test results` and `## Deployment notes` section following the
  post-implementation format used by [WOLF-066](../tickets/WOLF-066-geofence-is-active-schema-split.md).

Template: [TICKET-TEMPLATE.md](TICKET-TEMPLATE.md).

---

## Commit convention

- One ticket → one branch (`feature/wolf-{n}`) → one PR.
- Commit message: `WOLF-{n}: <one-line summary>` on the first commit; freeform after.
- PR title mirrors ticket title.
- PR body links to the ticket file in `docs/tickets/`.

---

## Sharing plan with the interviewers

Two artifacts to hand over ahead of the review:

1. **`docs/interview-prep/STATUS.md`** — high-level board showing what was reviewed,
   what was fixed, what was intentionally left alone.
2. **The ticket folder itself** — evidence of structured, PR-sized production
   hardening with rationale, acceptance criteria, and test coverage.

Explicitly do **not** share the walkthrough notes — those are private study aids and
would give the impression the author needs a script to explain their own code.

---

## Non-goals

- Not a rewrite. Do not restructure folders, rename models, or migrate to a
  different framework version.
- Not a feature push. The refactor track only closes existing gaps; new features
  wait until after the interview.
- Not exhaustive test coverage. Add tests where a must-fix creates a testable
  behavior; do not chase coverage numbers.
