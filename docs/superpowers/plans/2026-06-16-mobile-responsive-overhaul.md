# Mobile Responsive Overhaul Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the Wolf web app usable on phones (320–414px) without sacrificing the desktop layout. Every authenticated page should be reachable, readable, and tappable on an iPhone SE / Android equivalent.

**Architecture:** Mobile-first refactor of `AuthenticatedLayout`. The three-column desktop layout (Navbar1 icon rail + GlassPanel + Navbar2 sidebar + content) collapses below `lg` into: stacked single column, Navbar1 rendered horizontally inside the top header, and Navbar2 replaced by a fixed bottom tab bar. After the shell is responsive, fix the per-page hotspots: `Devices/Index` grid table (becomes cards on mobile), `TriggerPanel` button rows (wrap), `Dashboard` cam frame (aspect-ratio), and `Welcome.tsx` hero sizing.

**Tech Stack:** React 18, Inertia.js, Tailwind CSS, TypeScript.

## Global Constraints

- **Do NOT run git operations.** The user handles all `git add` / `commit` / `push` manually. End each task by stopping and reporting status — never auto-commit.
- Tailwind breakpoints in this codebase: `sm` 640, `md` 768, `lg` 1024, `xl` 1280. "Mobile" = `< lg` for this plan.
- Visual QA at four widths after every UI task: **320, 375, 768, 1024** px.
- Reuse existing Wolf theme tokens (`wolf-glass-border`, `rounded-wolf-pill`, `wolf-card`, etc.). No new colors.
- No new dependencies.
- Touch targets ≥ 44px on every tappable element.
- Bottom tab bar must not cover content — main content needs `pb-20` (80px) on mobile to leave room.

## Design Decisions (locked unless user redirects)

1. **Mobile primary nav** = fixed **bottom tab bar** with Dashboard / Geofence / Devices (admin-only).
2. **Mobile secondary actions** (Profile / Claim device / Logout) = horizontal icon row in the header; same `Navbar1` component with responsive flex direction.
3. **Devices/Index < md** = card list; ≥ md = current grid table.
4. **Verification** = manual at 320/375/768/1024 in dev. No Playwright/Dusk setup.

---

## File Inventory

**Create:**
- `resources/js/Components/Theme/NavItems.ts` — shared nav-item data + `NavItem` interface (Navbar2 and BottomTabBar both consume this).
- `resources/js/Components/Theme/BottomTabBar.tsx` — mobile-only fixed bottom navigation bar.

**Modify:**
- `resources/js/Layouts/AuthenticatedLayout.tsx` — mobile-first stacking, mount `BottomTabBar` below `lg`.
- `resources/js/Components/Theme/Navbar1.tsx` — responsive flex direction (row on mobile, col on `lg+`).
- `resources/js/Components/Theme/Navbar2.tsx` — hide below `lg` (still the desktop sidebar).
- `resources/js/Components/Theme/TriggerPanel.tsx` — `flex-wrap` + smaller mobile padding.
- `resources/js/Pages/Devices/Index.tsx` — card view < md, grid view ≥ md.
- `resources/js/Pages/Dashboard.tsx` — replace `h-[260px]` with `aspect-video`.
- `resources/js/Pages/Welcome.tsx` — step down hero from `text-5xl` to `text-4xl` on smallest screens.

---

### Task 1: Extract nav items + build BottomTabBar

**Files:**
- Create: `resources/js/Components/Theme/NavItems.ts`
- Create: `resources/js/Components/Theme/BottomTabBar.tsx`

**Interfaces:**
- Consumes: nothing new
- Produces:
  - `NavItem` interface and `navItems` array exported from `NavItems.ts`
  - `<BottomTabBar />` component (no props; reads admin from `usePage`)

- [ ] **Step 1: Create `NavItems.ts`**

Path: `resources/js/Components/Theme/NavItems.ts`

```ts
export interface NavItem {
    label: string;
    href: string;
    routeMatch: string[];
    badge: string;
    adminOnly?: boolean;
}

export const navItems: NavItem[] = [
    {
        label: 'Dashboard',
        href: '/dashboard',
        routeMatch: ['/dashboard'],
        badge: '⌂',
    },
    {
        label: 'Geofence',
        href: '/geofence',
        routeMatch: ['/geofence'],
        badge: '⊕',
    },
    {
        label: 'Devices',
        href: '/devices',
        routeMatch: ['/devices'],
        badge: '▦',
        adminOnly: true,
    },
];
```

- [ ] **Step 2: Update `Navbar2.tsx` to import from `NavItems.ts`**

In `resources/js/Components/Theme/Navbar2.tsx`:
- Delete the local `NavItem` interface (lines 5–11) and `items` array (lines 13–33).
- Replace with:

```ts
import { navItems, type NavItem } from './NavItems';
```

- Change `items.filter(...)` to `navItems.filter(...)` (one occurrence).

- [ ] **Step 3: Create `BottomTabBar.tsx`**

Path: `resources/js/Components/Theme/BottomTabBar.tsx`

```tsx
import type { PageProps } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { navItems, type NavItem } from './NavItems';

/**
 * Fixed bottom navigation bar for mobile (< lg). Mirrors Navbar2's items
 * and active-state behavior, but renders as a horizontal strip with
 * stacked icon-over-label tabs.
 */
export function BottomTabBar() {
    const page = usePage<PageProps>();
    const isAdmin = page.props.auth.user.is_admin;
    const url = page.url;
    const visibleItems = navItems.filter((i) => !i.adminOnly || isAdmin);

    const [intentHref, setIntentHref] = useState<string | null>(null);

    const isActive = (item: NavItem) => {
        if (intentHref) return item.href === intentHref;
        return item.routeMatch.some((r) => url.startsWith(r));
    };

    return (
        <nav
            aria-label="Primary"
            className="fixed inset-x-0 bottom-0 z-30 border-t border-wolf-glass-border bg-wolf-glass backdrop-blur-wolf-panel lg:hidden"
        >
            <ul className="flex h-16 items-stretch justify-around px-2 pb-[env(safe-area-inset-bottom)]">
                {visibleItems.map((item) => {
                    const active = isActive(item);
                    return (
                        <li key={item.href} className="flex-1">
                            <Link
                                href={item.href}
                                onStart={() => setIntentHref(item.href)}
                                onFinish={() => setIntentHref(null)}
                                aria-current={active ? 'page' : undefined}
                                className={`flex h-full flex-col items-center justify-center gap-1 rounded-wolf-pill transition-colors ${
                                    active
                                        ? 'text-white'
                                        : 'text-slate-400 hover:text-slate-200'
                                }`}
                            >
                                <span
                                    className={`flex h-7 w-7 items-center justify-center rounded-lg text-sm ${
                                        active
                                            ? 'bg-gradient-to-br from-red-500 to-indigo-600 text-white'
                                            : 'border border-white/10 bg-white/5'
                                    }`}
                                >
                                    {item.badge}
                                </span>
                                <span className="text-[10px] font-medium uppercase tracking-wider">
                                    {item.label}
                                </span>
                            </Link>
                        </li>
                    );
                })}
            </ul>
        </nav>
    );
}
```

- [ ] **Step 4: Verify TypeScript build**

Run: `npx tsc --noEmit`
Expected: No new errors. (Pre-existing errors elsewhere are fine — just make sure your new files don't add any.)

- [ ] **Step 5: Stop and report**

Do NOT commit. Report:
- Files created: `NavItems.ts`, `BottomTabBar.tsx`
- File modified: `Navbar2.tsx` (now imports shared items)

---

### Task 2: Make Navbar1 responsive (row on mobile, col on lg+)

**Files:**
- Modify: `resources/js/Components/Theme/Navbar1.tsx`

**Interfaces:**
- Consumes: nothing new
- Produces: `<Navbar1 />` behavior unchanged on `lg+`; horizontal on `< lg`

- [ ] **Step 1: Update the GlassPanel wrapper classes**

In `resources/js/Components/Theme/Navbar1.tsx`, replace the className on the `GlassPanel` (currently lines 38–39):

```tsx
<GlassPanel
    variant="rail"
    className="relative z-20 flex flex-row items-center gap-2.5 self-stretch p-2 lg:flex-col lg:gap-3.5 lg:self-center lg:p-2.5"
>
```

- [ ] **Step 2: Update the tooltip to position below on mobile, right on desktop**

In the same file, replace the `<span>` tooltip element (currently lines 25–30) with:

```tsx
<span
    aria-hidden
    className="border-wolf-glass-border bg-wolf-glass backdrop-blur-wolf-rail pointer-events-none absolute left-1/2 top-full z-50 mt-2 -translate-x-1/2 translate-y-1 whitespace-nowrap rounded-md border px-2.5 py-1 text-xs font-medium text-slate-200 opacity-0 transition-all duration-150 group-hover:translate-y-0 group-hover:opacity-100 lg:left-full lg:top-1/2 lg:ml-3 lg:mt-0 lg:-translate-x-1 lg:-translate-y-1/2"
>
    {label}
</span>
```

- [ ] **Step 3: Visual check at desktop only (next task handles layout)**

Run: `npm run dev`
Open `/dashboard` at 1024px+. Expected: Navbar1 still renders as a vertical icon rail on the left, tooltips pop to the right on hover. **Identical to before.**

- [ ] **Step 4: Stop and report**

Do NOT commit. Note that mobile rendering still looks wrong — that's expected; Task 3 wires it in.

---

### Task 3: Refactor AuthenticatedLayout for mobile-first stacking

**Files:**
- Modify: `resources/js/Layouts/AuthenticatedLayout.tsx`

**Interfaces:**
- Consumes: `BottomTabBar` from Task 1, responsive `Navbar1` from Task 2
- Produces: layout that stacks below `lg`, hides Navbar2 below `lg`, mounts `BottomTabBar` below `lg`

- [ ] **Step 1: Rewrite `AuthenticatedLayout.tsx`**

Replace the entire file with:

```tsx
import { BottomTabBar } from '@/Components/Theme/BottomTabBar';
import { BusyOverlay } from '@/Components/Theme/BusyOverlay';
import { DeviceClaimModal } from '@/Components/Theme/DeviceClaimModal';
import { GlassPanel } from '@/Components/Theme/GlassPanel';
import { Navbar1 } from '@/Components/Theme/Navbar1';
import { Navbar2 } from '@/Components/Theme/Navbar2';
import { StageBackground } from '@/Components/Theme/StageBackground';
import { UserBadge } from '@/Components/Theme/UserBadge';
import { WolfLogo } from '@/Components/Theme/WolfLogo';
import type { ReactNode } from 'react';
import { useState } from 'react';

interface Props {
    children: ReactNode;
    trigger?: ReactNode;
}

export default function AuthenticatedLayout({ children, trigger }: Props) {
    const [claimOpen, setClaimOpen] = useState(false);

    return (
        <StageBackground>
            <div className="mx-auto flex max-w-7xl flex-col gap-4 px-4 pb-24 pt-4 lg:flex-row lg:gap-7 lg:px-10 lg:pb-10 lg:pt-10">
                {/* Mobile header: logo + user badge + horizontal Navbar1 */}
                <div className="flex items-center justify-between gap-3 lg:hidden">
                    <WolfLogo />
                    <UserBadge />
                </div>
                <div className="lg:hidden">
                    <Navbar1 onClaimClick={() => setClaimOpen(true)} />
                </div>

                {/* Desktop left rail */}
                <div className="hidden lg:block">
                    <Navbar1 onClaimClick={() => setClaimOpen(true)} />
                </div>

                <GlassPanel className="flex-1 px-4 py-5 lg:px-8 lg:py-7">
                    {/* Desktop header inside the panel */}
                    <div className="mb-6 hidden items-end justify-between lg:flex">
                        <WolfLogo />
                        <UserBadge />
                    </div>
                    <div className="flex gap-5">
                        <div className="hidden lg:block">
                            <Navbar2 />
                        </div>
                        <div className="flex flex-1 flex-col gap-3.5">
                            {children}
                        </div>
                    </div>
                    {trigger}
                </GlassPanel>
            </div>

            <BottomTabBar />

            <DeviceClaimModal
                open={claimOpen}
                onClose={() => setClaimOpen(false)}
            />
            <BusyOverlay />
        </StageBackground>
    );
}
```

- [ ] **Step 2: Visual QA at 320/375/768/1024**

Run `npm run dev`. Log in. Open `/dashboard`. Resize Chrome devtools.

| Width | Expected |
|---|---|
| 320 | WolfLogo top-left, UserBadge top-right, Navbar1 row of 3 icons below, GlassPanel below that, BottomTabBar fixed at bottom with 2–3 tabs, content not cut off, bottom padding clears the tab bar |
| 375 | Same as 320, slightly more breathing room |
| 768 | Same as 320 (still < lg) |
| 1024 | **Original** desktop layout: Navbar1 rail on the left, GlassPanel containing WolfLogo+UserBadge header, Navbar2 sidebar, content. No bottom tab bar visible. |

Click each bottom tab — navigation should work, active state should update.

- [ ] **Step 3: Stop and report**

Do NOT commit. Note any layouts that look broken at any breakpoint — they'll be fixed in later tasks.

---

### Task 4: Make TriggerPanel wrap on mobile

**Files:**
- Modify: `resources/js/Components/Theme/TriggerPanel.tsx`

**Interfaces:**
- Consumes: nothing new
- Produces: `<TriggerPanel>` that flex-wraps its children below `sm`

- [ ] **Step 1: Update the inner container**

In `resources/js/Components/Theme/TriggerPanel.tsx`, replace the inner `<div>` className:

```tsx
<div className="flex flex-wrap items-center justify-center gap-3 rounded-wolf-card border border-wolf-glass-border bg-white/[0.04] p-4 sm:gap-3.5 sm:p-5">
    {children}
</div>
```

- [ ] **Step 2: Visual QA**

Run `npm run dev`. Go to `/geofence` (with a geofence already configured so all three buttons render).

| Width | Expected |
|---|---|
| 320 | Three action buttons wrap onto two rows, all reachable, no horizontal scroll |
| 375 | Same |
| 768+ | Buttons render in a single row (current behavior) |

- [ ] **Step 3: Stop and report**

Do NOT commit.

---

### Task 5: Make Devices/Index mobile-friendly (cards on < md)

**Files:**
- Modify: `resources/js/Pages/Devices/Index.tsx`

**Interfaces:**
- Consumes: nothing new
- Produces: same page, with mobile card list under `md` and grid table at `md+`

- [ ] **Step 1: Read the current file once**

`resources/js/Pages/Devices/Index.tsx` — note the row rendering (the loop that maps `devices.map`) and the action buttons (Edit / Delete / Regenerate token). Each device row must keep the same actions in the mobile card.

- [ ] **Step 2: Add a `<DeviceCard>` local component above the default export**

In `resources/js/Pages/Devices/Index.tsx`, immediately above `export default function Index(...)`, add:

```tsx
function DeviceCard({
    device,
    formatCentralTime,
}: {
    device: Device;
    formatCentralTime: (iso: string | null) => string;
}) {
    return (
        <div className="rounded-wolf-card border border-wolf-card-border bg-black/40 p-4">
            <div className="flex items-start justify-between gap-3">
                <div>
                    <div className="text-sm font-semibold text-white">
                        {device.name}
                    </div>
                    <div className="mt-0.5 font-mono text-xs text-slate-400">
                        {device.device_id}
                    </div>
                </div>
                <span
                    className={`shrink-0 rounded-wolf-pill border px-2 py-0.5 text-[10px] uppercase tracking-wider ${
                        device.is_online
                            ? 'border-emerald-400/40 bg-emerald-400/10 text-emerald-300'
                            : 'border-white/10 bg-white/5 text-slate-400'
                    }`}
                >
                    {device.is_online ? 'Online' : 'Offline'}
                </span>
            </div>
            <dl className="mt-3 grid grid-cols-2 gap-2 text-xs">
                <div>
                    <dt className="text-slate-500">Type</dt>
                    <dd className="text-slate-200">{device.type}</dd>
                </div>
                <div>
                    <dt className="text-slate-500">Last seen</dt>
                    <dd className="text-slate-200">
                        {formatCentralTime(device.last_seen_at)}
                    </dd>
                </div>
            </dl>
            <div className="mt-4 flex flex-wrap gap-2">
                <Link
                    href={`/devices/${device.id}/edit`}
                    className="rounded-wolf-pill border border-wolf-card-border bg-white/[0.05] px-3 py-1.5 text-xs font-medium text-slate-200 hover:bg-white/10"
                >
                    Edit
                </Link>
            </div>
        </div>
    );
}
```

> Note: only the Edit action is replicated above. If the current Devices/Index has additional inline actions (delete, regenerate-token), add matching buttons inside the `flex flex-wrap gap-2` block, copying the handlers/links verbatim from the existing grid row.

- [ ] **Step 3: Wrap the existing grid table in a `hidden md:block` container**

Find the existing `<div className="rounded-wolf-card border border-wolf-card-border bg-black/40 p-4">` that wraps the grid header + rows. Add `hidden md:block` to its className so it only renders at `md+`:

```tsx
<div className="hidden rounded-wolf-card border border-wolf-card-border bg-black/40 p-4 md:block">
    {/* existing grid header + device rows unchanged */}
</div>
```

- [ ] **Step 4: Add the mobile card list immediately above the wrapped grid**

Right above the `md:block` grid container, render:

```tsx
<div className="flex flex-col gap-3 md:hidden">
    {devices.map((device) => (
        <DeviceCard
            key={device.id}
            device={device}
            formatCentralTime={formatCentralTime}
        />
    ))}
    {devices.length === 0 && (
        <div className="rounded-wolf-card border border-wolf-card-border bg-black/40 p-6 text-center text-sm text-slate-400">
            No devices yet.
        </div>
    )}
</div>
```

- [ ] **Step 5: Visual QA**

Run `npm run dev`. Log in as admin. Open `/devices`.

| Width | Expected |
|---|---|
| 320 | Stacked cards. Each card shows name, device ID, online badge, type, last-seen, and Edit link. No horizontal scroll. |
| 375 | Same |
| 768 | Original grid table |
| 1024 | Original grid table |

- [ ] **Step 6: Stop and report**

Do NOT commit. Flag any actions you couldn't replicate (e.g. if the current row has more than just Edit).

---

### Task 6: Fix Dashboard cam frame aspect ratio

**Files:**
- Modify: `resources/js/Pages/Dashboard.tsx`

**Interfaces:**
- Consumes: nothing new
- Produces: cam frame that scales with viewport instead of fixed 260px height

- [ ] **Step 1: Replace the fixed-height wrapper**

In `resources/js/Pages/Dashboard.tsx`, replace the cam frame wrapper (currently `<div className="flex h-[260px] w-full overflow-hidden">`) with:

```tsx
<div className="flex aspect-video w-full overflow-hidden sm:aspect-[16/8] lg:h-[260px] lg:aspect-auto">
    <DashboardCamFrame devices={devices} />
</div>
```

- [ ] **Step 2: Visual QA**

Run `npm run dev`. Open `/dashboard`.

| Width | Expected |
|---|---|
| 320 | Cam frame ≈ 320 × 180 (16:9), placeholder text visible |
| 375 | ≈ 375 × 187 (16:8 on `sm`) |
| 768 | ≈ 16:8 ratio |
| 1024 | Fixed 260px height (current behavior) |

- [ ] **Step 3: Stop and report**

Do NOT commit.

---

### Task 7: Polish — landing hero + final QA sweep

**Files:**
- Modify: `resources/js/Pages/Welcome.tsx`

**Interfaces:** none

- [ ] **Step 1: Step down the landing hero on the smallest screens**

In `resources/js/Pages/Welcome.tsx`, find the `<h1>` (currently `text-5xl font-extrabold leading-[1.05] tracking-tight text-white sm:text-6xl lg:text-7xl`). Replace its className with:

```tsx
className="mt-6 text-4xl font-extrabold leading-[1.05] tracking-tight text-white sm:text-5xl md:text-6xl lg:text-7xl"
```

- [ ] **Step 2: Full-app QA sweep at 320 / 375 / 768 / 1024**

Run `npm run dev`. Walk through every page below at each width. Note anything that overflows or feels cramped.

Pages to walk:
- `/` (logged out)
- `/login`, `/register`
- `/dashboard`
- `/geofence` (both with and without a geofence)
- `/devices` (as admin)
- `/profile`

For each: scroll the full page, click the primary action, open any modals (Schedule modal, Device claim modal). Confirm no element forces horizontal scroll.

- [ ] **Step 3: Write a short QA report**

In your task report, list each page × width that you walked, and any issue you spotted. If everything passed, say so explicitly.

- [ ] **Step 4: Stop and report**

Do NOT commit.

---

## Self-Review (filled in)

**Spec coverage** (against the audit findings shared earlier):
- 🔴 `AuthenticatedLayout` desktop-first → **Task 3**
- 🔴 `Navbar2` fixed `w-[220px]` → **Task 1 + 3** (BottomTabBar + hidden on `< lg`)
- 🔴 `Devices/Index` 6-col grid → **Task 5**
- 🟡 `Dashboard` `h-[260px]` → **Task 6**
- 🟡 `TriggerPanel` button row overflow → **Task 4**
- 🟡 `Geofence/Index` button row → covered by **Task 4** (Geofence uses `TriggerPanel`)
- 🟢 `Welcome` hero `text-5xl` on smallest screens → **Task 7**
- 🟢 Navbar1 tooltips on hover → flagged as harmless; not addressed by design

**Placeholder scan:** none. Each step has exact code or exact verification commands.

**Type consistency:** `NavItem` interface defined in Task 1 (`NavItems.ts`) is imported by both `Navbar2.tsx` (Task 1, Step 2) and `BottomTabBar.tsx` (Task 1, Step 3). `intentHref` state pattern reused verbatim from Navbar2 in BottomTabBar.

---

## Execution Notes

- No automated tests for this work. Visual verification is the bar.
- Do not commit between tasks. The user reviews each task's changes manually and handles git themselves.
- If a task's "expected" visual doesn't match what you see, **stop and report** rather than tweaking. Layout regressions cascade quickly.
