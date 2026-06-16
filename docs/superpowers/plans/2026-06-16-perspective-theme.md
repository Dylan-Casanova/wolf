# Perspective Theme Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

> **No-git-ops preference:** Dylan handles git operations personally. Skip the `git commit` step at the end of each task. Leave the working tree dirty for review.

**Goal:** Apply a unified "perspective" dark-mode theme (glass panels, atmospheric edge glows, indigo + red accents) across all authenticated Wolf web pages, replacing the current plain-Tailwind UI with a cinematic, brand-consistent shell.

**Architecture:** A reusable theme primitive set (`StageBackground`, `GlassPanel`, `Navbar1`, `Navbar2`, branding, status strip, trigger panel) lives under `resources/js/Components/Theme/`. A rewritten `AuthenticatedLayout` composes them into the shared chrome and provides a `trigger?` slot. Pages render their own main-area content + optional `trigger` element through the layout. DeviceClaim becomes an overlay modal accessible from the outer `Navbar1` rail, replacing the standalone `/devices/claim` page route.

**Tech Stack:** Laravel + Inertia.js + React 18 + TypeScript + Tailwind CSS. Existing Leaflet map continues to work (no parent transforms required since 3D tilt was explicitly dropped).

**Spec:** `docs/superpowers/specs/2026-06-16-perspective-theme-design.md`

---

## File Structure

**Backend (Laravel) — single file modified:**
- `routes/web.php` — remove the `GET /devices/claim` route (the modal supersedes it; POST stays for the modal to submit to)

**Frontend (new files under `resources/js/Components/Theme/`):**
- `StageBackground.tsx` — outermost canvas with atmospheric gradients and red top sliver
- `GlassPanel.tsx` — reusable glass surface (variants: panel / rail / card / pill)
- `WolfLogo.tsx` — top-left "WOLF / GARAGE CONTROL" branding
- `UserBadge.tsx` — top-right "Hi, <user>" greeting
- `Navbar1.tsx` — outer floating rail with Profile / Claim / Logout icons
- `Navbar2.tsx` — inner list nav (Dashboard / Geofence / Devices[admin])
- `StatusStrip.tsx` — thin name + online-status row between main and trigger areas
- `TriggerPanel.tsx` — glass container around per-view bottom actions
- `GarageOpenButton.tsx` — Dashboard primary action; reserved slot for the deferred complex animation
- `GeofenceActionRow.tsx` — Geofence three-button row (Enable Tracking / Update / Delete)
- `GeofenceMiniControl.tsx` — compact arm/disarm control for Dashboard's bottom half
- `DashboardCamFrame.tsx` — Dashboard top half: ESP32-cam live stream OR animation idle frame
- `DeviceClaimModal.tsx` — overlay modal triggered from Navbar1

**Frontend (modified):**
- `tailwind.config.js` — add `wolf-*` semantic theme tokens
- `resources/js/Layouts/AuthenticatedLayout.tsx` — full rewrite around the new chrome
- `resources/js/Pages/Dashboard.tsx` — use new components
- `resources/js/Pages/Geofence/Index.tsx` — use new chrome + `GeofenceActionRow`
- `resources/js/Pages/Devices/Index.tsx` (admin) — restyle the existing table to match theme
- `resources/js/Pages/Profile/Edit.tsx` — wrap in new chrome (no trigger panel)

**Frontend (deleted):**
- `resources/js/Pages/Devices/Claim.tsx` — replaced by modal

---

## Task 1: Tailwind theme tokens + atmospheric primitives

**Files:**
- Modify: `tailwind.config.js`
- Create: `resources/js/Components/Theme/StageBackground.tsx`
- Create: `resources/js/Components/Theme/GlassPanel.tsx`

- [ ] **Step 1: Extend Tailwind config with theme tokens**

Replace the `theme.extend` block in `tailwind.config.js`. If the file already extends other tokens, merge — don't overwrite.

```js
// tailwind.config.js
/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.{js,jsx,ts,tsx}',
    ],
    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', 'ui-sans-serif', 'system-ui', 'sans-serif'],
            },
            colors: {
                'wolf-void': '#050510',
                'wolf-glass': 'rgba(15, 20, 40, 0.55)',
                'wolf-glass-border': 'rgba(255, 255, 255, 0.08)',
                'wolf-card': 'rgba(255, 255, 255, 0.03)',
                'wolf-card-border': 'rgba(255, 255, 255, 0.06)',
                'wolf-active': 'rgba(99, 102, 241, 0.18)',
                'wolf-active-border': 'rgba(99, 102, 241, 0.4)',
            },
            borderRadius: {
                'wolf-panel': '32px',
                'wolf-rail': '22px',
                'wolf-card': '18px',
                'wolf-pill': '14px',
            },
            backdropBlur: {
                'wolf-panel': '28px',
                'wolf-rail': '20px',
            },
        },
    },
    plugins: [require('@tailwindcss/forms')],
};
```

- [ ] **Step 2: Create `StageBackground.tsx`**

Create `resources/js/Components/Theme/StageBackground.tsx`:

```tsx
import type { ReactNode } from 'react';

interface Props {
    children: ReactNode;
}

export function StageBackground({ children }: Props) {
    return (
        <div className="relative min-h-screen overflow-hidden bg-wolf-void">
            <div
                aria-hidden
                className="pointer-events-none absolute inset-0"
                style={{
                    background:
                        'radial-gradient(ellipse 60% 50% at 100% 100%, rgba(239,68,68,0.18) 0%, transparent 60%),' +
                        'radial-gradient(ellipse 50% 40% at 0% 30%, rgba(56,189,248,0.10) 0%, transparent 60%),' +
                        'radial-gradient(ellipse 80% 60% at 50% 0%, rgba(244,63,94,0.08) 0%, transparent 50%)',
                }}
            />
            <div
                aria-hidden
                className="pointer-events-none absolute inset-x-0 top-0 h-0.5"
                style={{
                    background:
                        'linear-gradient(90deg, transparent 0%, #ef4444 30%, #ef4444 70%, transparent 100%)',
                    boxShadow: '0 0 12px #ef4444',
                }}
            />
            <div className="relative">{children}</div>
        </div>
    );
}
```

- [ ] **Step 3: Create `GlassPanel.tsx`**

Create `resources/js/Components/Theme/GlassPanel.tsx`:

```tsx
import type { ReactNode } from 'react';

type Variant = 'panel' | 'rail' | 'card' | 'pill';

interface Props {
    children: ReactNode;
    variant?: Variant;
    className?: string;
}

const radius: Record<Variant, string> = {
    panel: 'rounded-wolf-panel',
    rail: 'rounded-wolf-rail',
    card: 'rounded-wolf-card',
    pill: 'rounded-wolf-pill',
};

const blur: Record<Variant, string> = {
    panel: 'backdrop-blur-wolf-panel',
    rail: 'backdrop-blur-wolf-rail',
    card: 'backdrop-blur-wolf-rail',
    pill: 'backdrop-blur-wolf-rail',
};

export function GlassPanel({
    children,
    variant = 'panel',
    className = '',
}: Props) {
    return (
        <div
            className={`bg-wolf-glass border border-wolf-glass-border shadow-[0_30px_80px_rgba(0,0,0,0.55),inset_0_1px_0_rgba(255,255,255,0.06)] ${radius[variant]} ${blur[variant]} ${className}`}
        >
            {children}
        </div>
    );
}
```

- [ ] **Step 4: Typecheck**

Run from `/Users/mr.casanova/Code/wolf`:

```bash
npx tsc --noEmit 2>&1 | head -10
```

Expected: empty (no TS errors).

- [ ] **Step 5: Commit (skip per Dylan's preference)**

```bash
git add tailwind.config.js resources/js/Components/Theme/StageBackground.tsx resources/js/Components/Theme/GlassPanel.tsx
git commit -m "feat(theme): add wolf tailwind tokens + StageBackground + GlassPanel primitives"
```

---

## Task 2: Branding primitives — WolfLogo, UserBadge

**Files:**
- Create: `resources/js/Components/Theme/WolfLogo.tsx`
- Create: `resources/js/Components/Theme/UserBadge.tsx`

- [ ] **Step 1: Create `WolfLogo.tsx`**

Create `resources/js/Components/Theme/WolfLogo.tsx`:

```tsx
export function WolfLogo() {
    return (
        <div>
            <div className="text-[22px] font-extrabold tracking-tight text-white">
                WOLF
            </div>
            <div className="mt-1 text-[10px] font-normal uppercase tracking-[4px] text-slate-400">
                Garage Control
            </div>
        </div>
    );
}
```

- [ ] **Step 2: Create `UserBadge.tsx`**

Create `resources/js/Components/Theme/UserBadge.tsx`:

```tsx
import type { PageProps } from '@/types';
import { usePage } from '@inertiajs/react';

export function UserBadge() {
    const { auth } = usePage<PageProps>().props;
    return (
        <div className="text-sm text-slate-300">
            Hi, <span className="text-white">{auth.user.name}</span>
        </div>
    );
}
```

- [ ] **Step 3: Typecheck**

```bash
npx tsc --noEmit 2>&1 | head -10
```

Expected: empty.

- [ ] **Step 4: Commit (skip)**

```bash
git add resources/js/Components/Theme/WolfLogo.tsx resources/js/Components/Theme/UserBadge.tsx
git commit -m "feat(theme): add WolfLogo and UserBadge primitives"
```

---

## Task 3: Navigation — Navbar1 (outer rail) + Navbar2 (inner list)

**Files:**
- Create: `resources/js/Components/Theme/Navbar1.tsx`
- Create: `resources/js/Components/Theme/Navbar2.tsx`

- [ ] **Step 1: Create `Navbar1.tsx`**

Create `resources/js/Components/Theme/Navbar1.tsx`. The Claim button receives an `onClaimClick` callback so the modal state lives in the parent (the layout).

```tsx
import { router } from '@inertiajs/react';
import { GlassPanel } from './GlassPanel';

interface Props {
    onClaimClick: () => void;
}

const iconClasses =
    'flex h-11 w-11 items-center justify-center rounded-xl border border-white/10 bg-white/5 text-slate-300 transition-colors hover:bg-white/10';

export function Navbar1({ onClaimClick }: Props) {
    const goToProfile = () => router.visit('/profile');
    const doLogout = () => router.post('/logout');

    return (
        <GlassPanel
            variant="rail"
            className="flex flex-col items-center gap-3.5 self-center p-2.5"
        >
            <button
                onClick={goToProfile}
                title="Profile"
                aria-label="Profile"
                className={iconClasses}
            >
                <svg
                    width="18"
                    height="18"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    strokeWidth="1.6"
                >
                    <circle cx="12" cy="8" r="4" />
                    <path d="M4 21c0-4 4-7 8-7s8 3 8 7" />
                </svg>
            </button>
            <button
                onClick={onClaimClick}
                title="Claim device"
                aria-label="Claim device"
                className={iconClasses}
            >
                <svg
                    width="18"
                    height="18"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    strokeWidth="1.6"
                >
                    <path d="M12 5v14M5 12h14" />
                </svg>
            </button>
            <button
                onClick={doLogout}
                title="Logout"
                aria-label="Logout"
                className={iconClasses}
            >
                <svg
                    width="18"
                    height="18"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    strokeWidth="1.6"
                >
                    <path d="M16 17l5-5-5-5M21 12H9M13 21H5a2 2 0 01-2-2V5a2 2 0 012-2h8" />
                </svg>
            </button>
        </GlassPanel>
    );
}
```

- [ ] **Step 2: Create `Navbar2.tsx`**

Create `resources/js/Components/Theme/Navbar2.tsx`:

```tsx
import type { PageProps } from '@/types';
import { Link, usePage } from '@inertiajs/react';

interface NavItem {
    label: string;
    href: string;
    routeMatch: string[];
    badge: string;
    adminOnly?: boolean;
}

const items: NavItem[] = [
    { label: 'Dashboard', href: '/dashboard', routeMatch: ['/dashboard'], badge: '⌂' },
    { label: 'Geofence', href: '/geofence', routeMatch: ['/geofence'], badge: '⊕' },
    { label: 'Devices', href: '/devices', routeMatch: ['/devices'], badge: '▦', adminOnly: true },
];

export function Navbar2() {
    const page = usePage<PageProps>();
    const isAdmin = page.props.auth.user.is_admin;
    const url = page.url;
    const visibleItems = items.filter((i) => !i.adminOnly || isAdmin);

    return (
        <nav className="flex w-[220px] shrink-0 flex-col gap-2.5">
            {visibleItems.map((item) => {
                const active = item.routeMatch.some((r) => url.startsWith(r));
                return (
                    <Link
                        key={item.href}
                        href={item.href}
                        className={`flex items-center gap-3 rounded-wolf-pill border px-3 py-2.5 transition-colors ${
                            active
                                ? 'border-wolf-active-border bg-wolf-active shadow-[0_6px_20px_rgba(99,102,241,0.18),inset_0_1px_0_rgba(255,255,255,0.08)]'
                                : 'border-wolf-card-border bg-wolf-card hover:bg-white/[0.05]'
                        }`}
                    >
                        <div
                            className={`flex h-7 w-7 items-center justify-center rounded-lg text-xs ${
                                active
                                    ? 'bg-gradient-to-br from-red-500 to-indigo-600 text-white'
                                    : 'border border-white/10 bg-white/5 text-slate-400'
                            }`}
                        >
                            {item.badge}
                        </div>
                        <span
                            className={`text-sm font-medium ${
                                active ? 'text-white' : 'text-slate-200'
                            }`}
                        >
                            {item.label}
                        </span>
                        {item.adminOnly && (
                            <span className="ml-auto text-[10px] text-slate-500">
                                admin
                            </span>
                        )}
                    </Link>
                );
            })}
        </nav>
    );
}
```

- [ ] **Step 3: Typecheck**

```bash
npx tsc --noEmit 2>&1 | head -10
```

Expected: empty.

- [ ] **Step 4: Commit (skip)**

```bash
git add resources/js/Components/Theme/Navbar1.tsx resources/js/Components/Theme/Navbar2.tsx
git commit -m "feat(theme): add Navbar1 outer rail + Navbar2 inner list"
```

---

## Task 4: TriggerPanel + StatusStrip + DeviceClaimModal scaffold

**Files:**
- Create: `resources/js/Components/Theme/TriggerPanel.tsx`
- Create: `resources/js/Components/Theme/StatusStrip.tsx`
- Create: `resources/js/Components/Theme/DeviceClaimModal.tsx`

- [ ] **Step 1: Create `TriggerPanel.tsx`**

Create `resources/js/Components/Theme/TriggerPanel.tsx`:

```tsx
import type { ReactNode } from 'react';

interface Props {
    children: ReactNode;
    label?: string;
}

export function TriggerPanel({ children, label }: Props) {
    return (
        <div className="mt-4">
            <div className="flex items-center justify-center gap-3.5 rounded-wolf-card border border-wolf-glass-border bg-white/[0.04] p-5">
                {children}
            </div>
            {label && (
                <div className="mt-2 text-center text-[10px] uppercase tracking-[2px] text-slate-500">
                    {label}
                </div>
            )}
        </div>
    );
}
```

- [ ] **Step 2: Create `StatusStrip.tsx`**

Create `resources/js/Components/Theme/StatusStrip.tsx`:

```tsx
interface Props {
    name: string;
    online?: boolean;
    meta?: string;
}

export function StatusStrip({ name, online, meta }: Props) {
    return (
        <div className="flex items-center justify-between px-1.5 text-[11px] text-slate-400">
            <div>
                <div className="text-[13px] font-semibold text-white">
                    {name}
                </div>
                <div className="mt-0.5">
                    {online !== undefined && (
                        <span
                            className="mr-1.5 inline-block h-1.5 w-1.5 rounded-full"
                            style={{
                                backgroundColor: online ? '#22c55e' : '#475569',
                                boxShadow: online ? '0 0 8px #22c55e' : 'none',
                            }}
                        />
                    )}
                    {online === undefined
                        ? meta
                        : `${online ? 'Online' : 'Offline'}${meta ? ` · ${meta}` : ''}`}
                </div>
            </div>
        </div>
    );
}
```

- [ ] **Step 3: Create `DeviceClaimModal.tsx`**

Create `resources/js/Components/Theme/DeviceClaimModal.tsx`. Uses axios POST to `/devices/claim` (the existing route in `routes/web.php`), then closes on success and reloads via Inertia.

```tsx
import { router } from '@inertiajs/react';
import axios from 'axios';
import { useState } from 'react';
import { createPortal } from 'react-dom';

interface Props {
    open: boolean;
    onClose: () => void;
}

export function DeviceClaimModal({ open, onClose }: Props) {
    const [deviceId, setDeviceId] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);

    if (!open) return null;
    if (typeof document === 'undefined') return null;

    const onSubmit = async (e: React.FormEvent) => {
        e.preventDefault();
        setError(null);
        if (!deviceId.trim()) {
            setError('Please enter a setup token.');
            return;
        }
        setSubmitting(true);
        try {
            await axios.post('/devices/claim', { device_id: deviceId.trim() });
            setDeviceId('');
            onClose();
            router.reload();
        } catch (err) {
            const msg =
                axios.isAxiosError(err) && err.response?.data?.message
                    ? String(err.response.data.message)
                    : 'Failed to claim device. Please try again.';
            setError(msg);
        } finally {
            setSubmitting(false);
        }
    };

    return createPortal(
        <div className="fixed inset-0 z-[1000] flex items-center justify-center bg-black/70 p-4">
            <div className="w-full max-w-md rounded-wolf-card border border-wolf-glass-border bg-wolf-glass p-6 shadow-[0_30px_80px_rgba(0,0,0,0.7)] backdrop-blur-wolf-panel">
                <h3 className="text-lg font-semibold text-white">
                    Claim a device
                </h3>
                <p className="mt-2 text-sm text-slate-400">
                    Enter the setup token printed on the device.
                </p>
                <form onSubmit={onSubmit} className="mt-4 flex flex-col gap-3">
                    <input
                        type="text"
                        autoFocus
                        value={deviceId}
                        onChange={(e) => setDeviceId(e.target.value)}
                        placeholder="e.g. wolf-abc-12345"
                        autoCapitalize="off"
                        autoCorrect="off"
                        spellCheck={false}
                        className="rounded-wolf-pill border border-wolf-glass-border bg-white/5 px-3 py-2 text-sm text-white placeholder-slate-500 focus:border-wolf-active-border focus:outline-none focus:ring-1 focus:ring-wolf-active-border"
                    />
                    {error && (
                        <p className="text-xs text-red-400">{error}</p>
                    )}
                    <div className="mt-2 flex justify-end gap-2">
                        <button
                            type="button"
                            onClick={onClose}
                            className="rounded-wolf-pill px-4 py-2 text-sm font-medium text-slate-300 hover:bg-white/5"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            disabled={submitting}
                            className="rounded-wolf-pill bg-gradient-to-br from-indigo-500 to-indigo-700 px-4 py-2 text-sm font-semibold text-white shadow-[0_8px_24px_rgba(99,102,241,0.4)] disabled:opacity-50"
                        >
                            {submitting ? 'Claiming…' : 'Claim'}
                        </button>
                    </div>
                </form>
            </div>
        </div>,
        document.body,
    );
}
```

- [ ] **Step 4: Typecheck**

```bash
npx tsc --noEmit 2>&1 | head -10
```

Expected: empty.

- [ ] **Step 5: Commit (skip)**

```bash
git add resources/js/Components/Theme/TriggerPanel.tsx resources/js/Components/Theme/StatusStrip.tsx resources/js/Components/Theme/DeviceClaimModal.tsx
git commit -m "feat(theme): add TriggerPanel + StatusStrip + DeviceClaimModal scaffolding"
```

---

## Task 5: AuthenticatedLayout rewrite

**Files:**
- Modify: `resources/js/Layouts/AuthenticatedLayout.tsx`

- [ ] **Step 1: Replace `AuthenticatedLayout.tsx` entirely**

The existing layout accepts a `header` prop; we drop it (the new chrome has a fixed header — WolfLogo + UserBadge). Pages that pass `<header>` will need that prop removed in their respective rewrites (Tasks 6-9).

Replace `resources/js/Layouts/AuthenticatedLayout.tsx` contents with:

```tsx
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
            <div className="mx-auto flex max-w-7xl gap-7 px-10 py-10">
                <Navbar1 onClaimClick={() => setClaimOpen(true)} />
                <GlassPanel className="flex-1 px-8 py-7">
                    <div className="mb-6 flex items-end justify-between">
                        <WolfLogo />
                        <UserBadge />
                    </div>
                    <div className="flex gap-5">
                        <Navbar2 />
                        <div className="flex flex-1 flex-col gap-3.5">
                            {children}
                        </div>
                    </div>
                    {trigger}
                </GlassPanel>
            </div>
            <DeviceClaimModal
                open={claimOpen}
                onClose={() => setClaimOpen(false)}
            />
        </StageBackground>
    );
}
```

- [ ] **Step 2: Typecheck**

This will surface errors in every page that imported the old `AuthenticatedLayout` with a `header` prop. That's expected — those pages get fixed in Tasks 6-9.

```bash
npx tsc --noEmit 2>&1 | head -40
```

Expected: errors mentioning `header` is not assignable, in `Dashboard.tsx`, `Geofence/Index.tsx`, `Devices/Index.tsx` (admin), `Profile/Edit.tsx`. Note them. NO errors in the layout itself or any Theme component.

- [ ] **Step 3: Commit (skip)**

```bash
git add resources/js/Layouts/AuthenticatedLayout.tsx
git commit -m "feat(theme): rewrite AuthenticatedLayout around glass chrome + DeviceClaim modal"
```

---

## Task 6: Dashboard rewrite — DashboardCamFrame + GeofenceMiniControl + GarageOpenButton

**Files:**
- Create: `resources/js/Components/Theme/DashboardCamFrame.tsx`
- Create: `resources/js/Components/Theme/GeofenceMiniControl.tsx`
- Create: `resources/js/Components/Theme/GarageOpenButton.tsx`
- Modify: `resources/js/Pages/Dashboard.tsx`

- [ ] **Step 1: Create `DashboardCamFrame.tsx`**

Top half of the Dashboard main area. Renders the ESP32-cam live stream when available, otherwise an "animation idle frame" placeholder. The placeholder is a static dark gradient surface for now; the animation slot is reserved by the dimensions and container shape so a future animation drops in cleanly.

```tsx
interface Device {
    id: number;
    type: 'esp32_cam' | 'esp8266';
    is_online: boolean;
}

interface Props {
    devices: Device[];
}

export function DashboardCamFrame({ devices }: Props) {
    const cam = devices.find(
        (d) => d.type === 'esp32_cam' && d.is_online,
    );

    if (cam) {
        // Live stream — the existing StreamView component handles signaling
        // and HLS playback. Slot it in here.
        return (
            <div className="flex h-full w-full items-center justify-center bg-black">
                <div className="text-xs uppercase tracking-[2px] text-slate-500">
                    Live stream slot (StreamView)
                </div>
            </div>
        );
    }

    // No cam — render the animation idle frame
    return (
        <div
            className="flex h-full w-full items-center justify-center"
            style={{
                background:
                    'radial-gradient(ellipse at 20% 30%, rgba(56,189,248,0.30) 0%, transparent 60%),' +
                    'radial-gradient(ellipse at 80% 70%, rgba(239,68,68,0.40) 0%, transparent 60%),' +
                    '#04060f',
            }}
        >
            <div className="text-[11px] uppercase tracking-[2px] text-slate-500">
                Animation idle frame
            </div>
        </div>
    );
}
```

- [ ] **Step 2: Create `GeofenceMiniControl.tsx`**

Bottom half of the Dashboard main area. A compact arm/disarm control mirroring (but smaller than) what the Geofence page offers.

```tsx
import type { Geofence } from '@/types';
import axios from 'axios';
import { useState } from 'react';

interface Props {
    geofence: Geofence | null;
}

export function GeofenceMiniControl({ geofence }: Props) {
    const [loading, setLoading] = useState(false);

    if (!geofence) {
        return (
            <div className="flex items-center justify-between border-t border-wolf-card-border px-4 py-3.5">
                <span className="text-[13px] text-slate-400">
                    No perimeter configured
                </span>
                <a
                    href="/geofence"
                    className="rounded-wolf-pill border border-wolf-active-border bg-wolf-active px-4 py-2 text-[12.5px] font-semibold text-indigo-200"
                >
                    Set up
                </a>
            </div>
        );
    }

    const armed = geofence.is_active;

    const toggle = async () => {
        setLoading(true);
        try {
            await axios.post(`/geo-fences/${geofence.id}/toggle`);
            window.location.reload();
        } catch {
            // surface a flash later; for now silent fail
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className="flex items-center justify-between border-t border-wolf-card-border px-4 py-3.5">
            <div className="flex items-center gap-2.5">
                <span
                    className="inline-block h-2 w-2 rounded-full"
                    style={{
                        backgroundColor: armed ? '#22c55e' : '#475569',
                        boxShadow: armed ? '0 0 12px #22c55e' : 'none',
                    }}
                />
                <span className="text-[13px] text-slate-300">
                    Geofence{' '}
                    <strong className="text-white">
                        {armed ? 'armed' : 'disarmed'}
                    </strong>
                </span>
            </div>
            <button
                onClick={toggle}
                disabled={loading}
                className="rounded-wolf-pill border border-wolf-active-border bg-wolf-active px-4 py-2 text-[12.5px] font-semibold text-indigo-200 disabled:opacity-50"
            >
                {armed ? 'Disarm' : 'Arm'}
            </button>
        </div>
    );
}
```

- [ ] **Step 3: Create `GarageOpenButton.tsx`**

The trigger button. The animation slot is reserved by the surrounding container — when the animation source arrives, it slots in as a child overlay.

```tsx
import axios from 'axios';
import { useState } from 'react';

interface Props {
    deviceId: number;
}

type State = 'idle' | 'sending' | 'sent';

export function GarageOpenButton({ deviceId }: Props) {
    const [state, setState] = useState<State>('idle');

    const onClick = async () => {
        if (state !== 'idle') return;
        setState('sending');
        try {
            await axios.post('/garage/trigger', { device_id: deviceId });
            setState('sent');
            setTimeout(() => setState('idle'), 1500);
        } catch {
            setState('idle');
        }
    };

    const label =
        state === 'sent'
            ? 'SENT ✓'
            : state === 'sending'
              ? 'SENDING…'
              : 'OPEN GARAGE';

    const background =
        state === 'sent'
            ? 'linear-gradient(135deg, #22c55e 0%, #15803d 100%)'
            : 'linear-gradient(135deg, #6366f1 0%, #4338ca 100%)';

    return (
        <button
            onClick={onClick}
            disabled={state !== 'idle'}
            className="rounded-wolf-card px-11 py-4 text-base font-bold tracking-wide text-white shadow-[0_8px_28px_rgba(99,102,241,0.45),inset_0_1px_0_rgba(255,255,255,0.18)] transition-all hover:brightness-110 active:brightness-95 disabled:opacity-90"
            style={{ background }}
        >
            {label}
        </button>
    );
}
```

- [ ] **Step 4: Replace `Dashboard.tsx`**

Replace `resources/js/Pages/Dashboard.tsx` entirely:

```tsx
import { DashboardCamFrame } from '@/Components/Theme/DashboardCamFrame';
import { GarageOpenButton } from '@/Components/Theme/GarageOpenButton';
import { GeofenceMiniControl } from '@/Components/Theme/GeofenceMiniControl';
import { StatusStrip } from '@/Components/Theme/StatusStrip';
import { TriggerPanel } from '@/Components/Theme/TriggerPanel';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import type { Geofence } from '@/types';
import { Head } from '@inertiajs/react';

interface DeviceInfo {
    id: number;
    name: string;
    device_id: string;
    type: 'esp32_cam' | 'esp8266';
    is_online: boolean;
}

interface Props {
    devices: DeviceInfo[];
    geofence: Geofence | null;
}

export default function Dashboard({ devices, geofence }: Props) {
    const primaryServo = devices.find((d) => d.type === 'esp8266');

    const trigger = primaryServo ? (
        <TriggerPanel label="Trigger Button">
            <GarageOpenButton deviceId={primaryServo.id} />
        </TriggerPanel>
    ) : null;

    return (
        <AuthenticatedLayout trigger={trigger}>
            <Head title="Dashboard" />
            <div className="overflow-hidden rounded-wolf-card border border-wolf-card-border bg-black/60">
                <div className="flex h-[260px] w-full overflow-hidden">
                    <DashboardCamFrame devices={devices} />
                </div>
                <GeofenceMiniControl geofence={geofence} />
            </div>
            {primaryServo && (
                <StatusStrip
                    name={primaryServo.name}
                    online={primaryServo.is_online}
                    meta="ESP8266"
                />
            )}
        </AuthenticatedLayout>
    );
}
```

- [ ] **Step 5: Typecheck**

```bash
npx tsc --noEmit 2>&1 | head -20
```

Expected: errors from the OTHER pages still using the old layout signature (Geofence, Devices, Profile). NO errors from Dashboard or any Theme component.

- [ ] **Step 6: Commit (skip)**

```bash
git add resources/js/Components/Theme/DashboardCamFrame.tsx resources/js/Components/Theme/GeofenceMiniControl.tsx resources/js/Components/Theme/GarageOpenButton.tsx resources/js/Pages/Dashboard.tsx
git commit -m "feat(theme): rewrite Dashboard with cam frame, mini geofence control, and open-garage trigger"
```

---

## Task 7: Geofence rewrite — GeofenceActionRow + page update

**Files:**
- Create: `resources/js/Components/Theme/GeofenceActionRow.tsx`
- Modify: `resources/js/Pages/Geofence/Index.tsx`

- [ ] **Step 1: Create `GeofenceActionRow.tsx`**

Three buttons: Enable Tracking (primary), Update (neutral), Delete (danger).

```tsx
interface Props {
    onEnable: () => void;
    onUpdate: () => void;
    onDelete: () => void;
    enableLabel?: string;
    updateDisabled?: boolean;
    updating?: boolean;
}

export function GeofenceActionRow({
    onEnable,
    onUpdate,
    onDelete,
    enableLabel = 'ENABLE TRACKING',
    updateDisabled,
    updating,
}: Props) {
    return (
        <div className="flex items-center justify-center gap-3">
            <button
                onClick={onEnable}
                className="rounded-wolf-card bg-gradient-to-br from-indigo-500 to-indigo-700 px-9 py-3.5 text-[15px] font-bold tracking-wide text-white shadow-[0_8px_24px_rgba(99,102,241,0.4),inset_0_1px_0_rgba(255,255,255,0.18)]"
            >
                {enableLabel}
            </button>
            <button
                onClick={onUpdate}
                disabled={updateDisabled || updating}
                className="rounded-wolf-card border border-wolf-glass-border bg-white/[0.07] px-9 py-3.5 text-[15px] font-bold tracking-wide text-white disabled:opacity-40"
            >
                {updating ? 'UPDATING…' : 'UPDATE'}
            </button>
            <button
                onClick={onDelete}
                className="rounded-wolf-card bg-gradient-to-br from-red-500 to-red-700 px-9 py-3.5 text-[15px] font-bold tracking-wide text-white shadow-[0_8px_24px_rgba(239,68,68,0.35)]"
            >
                DELETE
            </button>
        </div>
    );
}
```

- [ ] **Step 2: Replace `Geofence/Index.tsx`**

Drop the old `header` prop, keep AddressSearch + GeofenceMap, use `GeofenceActionRow` in the trigger panel.

Replace `resources/js/Pages/Geofence/Index.tsx`:

```tsx
import AddressSearch from '@/Components/AddressSearch';
import GeofenceMap from '@/Components/GeofenceMap';
import { GeofenceActionRow } from '@/Components/Theme/GeofenceActionRow';
import { StatusStrip } from '@/Components/Theme/StatusStrip';
import { TriggerPanel } from '@/Components/Theme/TriggerPanel';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import type { Geofence } from '@/types';
import { Head, router } from '@inertiajs/react';
import axios from 'axios';
import { useState } from 'react';

interface Props {
    geofence: Geofence | null;
}

export default function Index({ geofence }: Props) {
    const [center, setCenter] = useState<[number, number] | null>(null);
    const [bounds, setBounds] = useState<{
        north_lat: number;
        south_lat: number;
        east_lng: number;
        west_lng: number;
    } | null>(
        geofence
            ? {
                  north_lat: geofence.north_lat,
                  south_lat: geofence.south_lat,
                  east_lng: geofence.east_lng,
                  west_lng: geofence.west_lng,
              }
            : null,
    );
    const [addressPoint, setAddressPoint] = useState<[number, number] | null>(
        geofence?.address_lat != null && geofence?.address_lng != null
            ? [geofence.address_lat, geofence.address_lng]
            : null,
    );
    const [saving, setSaving] = useState(false);
    const [showMap, setShowMap] = useState(!!geofence);

    const handleAddressSelect = (lat: number, lng: number) => {
        setCenter([lat, lng]);
        setAddressPoint([lat, lng]);
        setShowMap(true);
    };

    const handleSave = async () => {
        if (!bounds) return;
        const payload = {
            ...bounds,
            address_lat: addressPoint?.[0] ?? null,
            address_lng: addressPoint?.[1] ?? null,
        };
        setSaving(true);
        try {
            if (geofence) {
                await axios.put(`/geo-fences/${geofence.id}`, payload);
            } else {
                await axios.post('/geo-fences', payload);
            }
            router.reload();
        } catch {
            // validation error
        } finally {
            setSaving(false);
        }
    };

    const handleDelete = async () => {
        if (!geofence) return;
        await axios.delete(`/geo-fences/${geofence.id}`);
        setShowMap(false);
        setBounds(null);
        setCenter(null);
        setAddressPoint(null);
        router.reload();
    };

    const handleEnable = async () => {
        if (!geofence) return;
        await axios.post(`/geo-fences/${geofence.id}/toggle`);
        router.reload();
    };

    const trigger = geofence ? (
        <TriggerPanel label="Geofence Actions">
            <GeofenceActionRow
                onEnable={handleEnable}
                onUpdate={handleSave}
                onDelete={handleDelete}
                enableLabel={
                    geofence.is_active ? 'DISABLE TRACKING' : 'ENABLE TRACKING'
                }
                updateDisabled={!bounds}
                updating={saving}
            />
        </TriggerPanel>
    ) : bounds ? (
        <TriggerPanel label="Geofence Actions">
            <button
                onClick={handleSave}
                disabled={saving}
                className="rounded-wolf-card bg-gradient-to-br from-indigo-500 to-indigo-700 px-9 py-3.5 text-[15px] font-bold tracking-wide text-white shadow-[0_8px_24px_rgba(99,102,241,0.4)] disabled:opacity-50"
            >
                {saving ? 'CREATING…' : 'CREATE PERIMETER'}
            </button>
        </TriggerPanel>
    ) : null;

    return (
        <AuthenticatedLayout trigger={trigger}>
            <Head title="Geofence" />
            {!showMap && !geofence ? (
                <div className="flex flex-col items-center gap-6 rounded-wolf-card border border-wolf-card-border bg-black/40 py-12">
                    <p className="text-slate-400">
                        No geofence configured. Search for an address to create
                        your perimeter.
                    </p>
                    <div className="w-full max-w-md">
                        <AddressSearch onSelect={handleAddressSelect} />
                    </div>
                </div>
            ) : (
                <div className="flex flex-col gap-3">
                    {!geofence && (
                        <AddressSearch onSelect={handleAddressSelect} />
                    )}
                    <div className="overflow-hidden rounded-wolf-card border border-wolf-card-border bg-black/40">
                        <GeofenceMap
                            geofence={geofence}
                            center={center}
                            userPosition={null}
                            addressPoint={addressPoint}
                            onBoundsChange={setBounds}
                        />
                    </div>
                    {geofence && (
                        <StatusStrip
                            name="Home Perimeter"
                            online={geofence.is_active}
                            meta={geofence.is_active ? 'Tracking' : 'Disarmed'}
                        />
                    )}
                </div>
            )}
        </AuthenticatedLayout>
    );
}
```

- [ ] **Step 3: Typecheck**

```bash
npx tsc --noEmit 2>&1 | head -20
```

Expected: errors only from `Devices/Index.tsx` (admin) and `Profile/Edit.tsx`. NO errors from Geofence page or any Theme component.

- [ ] **Step 4: Commit (skip)**

```bash
git add resources/js/Components/Theme/GeofenceActionRow.tsx resources/js/Pages/Geofence/Index.tsx
git commit -m "feat(theme): rewrite Geofence page with action row + status strip"
```

---

## Task 8: Devices admin restyle

**Files:**
- Modify: `resources/js/Pages/Devices/Index.tsx`

- [ ] **Step 1: Find the current Devices admin index file**

Read the existing file to confirm location and shape:

```bash
ls resources/js/Pages/Devices/
```

Open `resources/js/Pages/Devices/Index.tsx` (the admin one) and note its current props and structure.

- [ ] **Step 2: Replace the Devices/Index.tsx file**

Drop the `header` prop, wrap the existing admin device table in the new layout, omit the `trigger` prop so the bottom panel collapses (main area grows automatically). Keep all existing data-handling logic — only the chrome and Tailwind classes change.

Replace `resources/js/Pages/Devices/Index.tsx` with:

```tsx
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import type { Device } from '@/types';
import { Head, Link } from '@inertiajs/react';

interface Props {
    devices: Device[];
}

export default function Index({ devices }: Props) {
    return (
        <AuthenticatedLayout>
            <Head title="Devices" />
            <div className="rounded-wolf-card border border-wolf-card-border bg-black/40 p-4">
                <div className="mb-3 grid grid-cols-[2fr_1.4fr_1fr_0.8fr_auto] gap-3 border-b border-wolf-card-border pb-3 text-[10px] uppercase tracking-[2px] text-slate-400">
                    <div>Name</div>
                    <div>Device ID</div>
                    <div>Type</div>
                    <div>Status</div>
                    <div></div>
                </div>
                {devices.length === 0 ? (
                    <div className="py-12 text-center text-sm text-slate-400">
                        No devices in the system yet.
                    </div>
                ) : (
                    <div className="flex flex-col gap-2">
                        {devices.map((device) => (
                            <div
                                key={device.id}
                                className="grid grid-cols-[2fr_1.4fr_1fr_0.8fr_auto] items-center gap-3 rounded-wolf-pill border border-wolf-card-border bg-white/[0.03] px-3.5 py-2.5 text-[12.5px] text-slate-300"
                            >
                                <div className="font-medium text-white">
                                    {device.name}
                                </div>
                                <div className="font-mono text-slate-400">
                                    {device.device_id}
                                </div>
                                <div>{device.type}</div>
                                <div className="flex items-center gap-1.5">
                                    <span
                                        className="inline-block h-1.5 w-1.5 rounded-full"
                                        style={{
                                            backgroundColor: device.is_online
                                                ? '#22c55e'
                                                : '#475569',
                                            boxShadow: device.is_online
                                                ? '0 0 8px #22c55e'
                                                : 'none',
                                        }}
                                    />
                                    <span
                                        className={
                                            device.is_online
                                                ? 'text-green-400'
                                                : 'text-slate-500'
                                        }
                                    >
                                        {device.is_online
                                            ? 'Online'
                                            : 'Offline'}
                                    </span>
                                </div>
                                <Link
                                    href={`/devices/${device.id}/edit`}
                                    className="rounded-wolf-pill border border-wolf-glass-border bg-white/[0.05] px-3 py-1.5 text-[11px] font-medium text-slate-300 hover:bg-white/[0.08]"
                                >
                                    Edit
                                </Link>
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
```

- [ ] **Step 3: Update Devices/Edit.tsx and Devices/Create.tsx (if they exist)**

These admin pages use `AuthenticatedLayout` with the now-removed `header` prop. They need that prop dropped. Keep their form content as-is — only the layout signature needs fixing.

```bash
ls resources/js/Pages/Devices/
```

For each of `Edit.tsx` and `Create.tsx` that exists:

1. Open the file
2. Find any `<AuthenticatedLayout header={...}>` usage
3. Remove the `header` prop entirely (drop the JSX block it wrapped — the new layout has a fixed header)
4. Wrap the form content in a glass card for consistency:

Replace any existing root container in the page body with:

```tsx
<div className="rounded-wolf-card border border-wolf-card-border bg-black/40 p-6 text-slate-200">
    {/* existing form content */}
</div>
```

If the page used any input/label classes that don't match the dark theme, leave them — the form fields are styled by `@tailwindcss/forms` plus any inline styles. Re-styling forms in detail is out of scope; that's a Phase 2 polish pass.

- [ ] **Step 4: Typecheck**

```bash
npx tsc --noEmit 2>&1 | head -20
```

Expected: errors only from `Profile/Edit.tsx`. NO errors from any Devices admin page.

- [ ] **Step 5: Commit (skip)**

```bash
git add resources/js/Pages/Devices/Index.tsx resources/js/Pages/Devices/Edit.tsx resources/js/Pages/Devices/Create.tsx
git commit -m "feat(theme): restyle Devices admin pages for perspective theme"
```

---

## Task 9: Profile rewrite

**Files:**
- Modify: `resources/js/Pages/Profile/Edit.tsx`

- [ ] **Step 1: Open the current Profile/Edit.tsx**

The existing file passes a `header` prop and renders multiple form partial components (`UpdateProfileInformationForm`, `UpdatePasswordForm`, `DeleteUserForm`). We keep the partials as-is, drop the `header` prop, wrap them in a glass card.

- [ ] **Step 2: Replace `Profile/Edit.tsx`**

```tsx
import DeleteUserForm from '@/Pages/Profile/Partials/DeleteUserForm';
import UpdatePasswordForm from '@/Pages/Profile/Partials/UpdatePasswordForm';
import UpdateProfileInformationForm from '@/Pages/Profile/Partials/UpdateProfileInformationForm';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';

interface Props {
    mustVerifyEmail: boolean;
    status?: string;
}

export default function Edit({ mustVerifyEmail, status }: Props) {
    return (
        <AuthenticatedLayout>
            <Head title="Profile" />
            <div className="flex flex-col gap-4">
                <div className="rounded-wolf-card border border-wolf-card-border bg-black/40 p-6 text-slate-200">
                    <UpdateProfileInformationForm
                        mustVerifyEmail={mustVerifyEmail}
                        status={status}
                        className="max-w-xl"
                    />
                </div>
                <div className="rounded-wolf-card border border-wolf-card-border bg-black/40 p-6 text-slate-200">
                    <UpdatePasswordForm className="max-w-xl" />
                </div>
                <div className="rounded-wolf-card border border-wolf-card-border bg-black/40 p-6 text-slate-200">
                    <DeleteUserForm className="max-w-xl" />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
```

- [ ] **Step 3: Typecheck**

```bash
npx tsc --noEmit 2>&1 | head -10
```

Expected: empty. All layout-signature errors should be gone now.

- [ ] **Step 4: Commit (skip)**

```bash
git add resources/js/Pages/Profile/Edit.tsx
git commit -m "feat(theme): wrap Profile/Edit in new chrome"
```

---

## Task 10: Remove standalone DeviceClaim page + route

**Files:**
- Modify: `routes/web.php`
- Delete: `resources/js/Pages/Devices/Claim.tsx`

- [ ] **Step 1: Remove the GET route for /devices/claim**

Open `routes/web.php`. Find this section:

```php
// Device claiming (any logged-in user)
Route::get('/devices/claim', [DeviceClaimController::class, 'create'])->name('devices.claim');
Route::post('/devices/claim', [DeviceClaimController::class, 'store']);
```

Remove the GET line, keep the POST. The POST is what the modal submits to.

Result:

```php
// Device claiming (POST only — UI lives in the Navbar1 modal)
Route::post('/devices/claim', [DeviceClaimController::class, 'store']);
```

- [ ] **Step 2: Sync routes/web.php to container and clear route cache**

```bash
docker compose cp routes/web.php app:/var/www/routes/web.php
docker compose exec app php artisan route:clear
```

- [ ] **Step 3: Verify the GET route is gone**

```bash
docker compose exec app php artisan route:list --path=devices/claim 2>&1 | head -5
```

Expected: only one POST route shown. No GET.

- [ ] **Step 4: Delete the orphan page component**

```bash
rm resources/js/Pages/Devices/Claim.tsx
```

- [ ] **Step 5: Check for any orphan imports**

```bash
grep -rn "Devices/Claim" resources/js
grep -rn "devices/claim" resources/js
```

Expected: no results (we already removed all references when we deleted the page and modified the layout).

- [ ] **Step 6: Run the existing test suite to confirm no regressions**

```bash
docker compose exec app php artisan test 2>&1 | tail -5
```

Expected: all tests pass. If any test referenced the GET route, fix it inline (most likely a feature test that loaded the page — replace with a POST test or remove).

- [ ] **Step 7: Commit (skip)**

```bash
git add routes/web.php
git rm resources/js/Pages/Devices/Claim.tsx
git commit -m "feat(theme): replace device claim page with Navbar1-triggered modal"
```

---

## Task 11: Final verification + manual smoke test

**Files:** none

- [ ] **Step 1: Sanity sweep — any remaining `header=` prop on AuthenticatedLayout?**

```bash
grep -rn "<AuthenticatedLayout" resources/js | grep -i "header"
```

Expected: zero matches. If anything appears, drop the `header` prop on that page. (Most likely candidates: any auth page I didn't explicitly cover above.)

- [ ] **Step 2: Run full TypeScript check**

```bash
npx tsc --noEmit
```

Expected: zero errors, empty output.

- [ ] **Step 3: Run linter**

```bash
npm run lint 2>&1 | tail -15
```

Expected: no errors (warnings tolerated if pre-existing).

- [ ] **Step 4: Run the existing test suite**

```bash
docker compose exec app php artisan test 2>&1 | tail -10
```

Expected: all tests pass.

- [ ] **Step 5: Manual smoke test — start the dev stack**

```bash
docker compose up -d
```

In a browser, log in as an admin user (or claim an admin) and walk through these views:

**Dashboard:**
- [ ] Stage background renders with the dark navy + atmospheric glows + red top sliver
- [ ] Navbar1 floating rail on the left has Profile / Claim / Logout icons
- [ ] Main panel shows WolfLogo + UserBadge at top, Navbar2 on the left, content on the right
- [ ] Top half of main area shows either the cam stream slot OR the animation idle frame placeholder
- [ ] Bottom half of main area shows the geofence mini-control with Arm / Disarm button
- [ ] Trigger panel at the bottom shows the big "OPEN GARAGE" button (only if an ESP8266 is registered)
- [ ] Tapping the button cycles idle → sending → sent → idle

**Geofence:**
- [ ] Same chrome
- [ ] Main area shows the Leaflet map with the perimeter and address marker
- [ ] StatusStrip shows "Home Perimeter" + Tracking/Disarmed status
- [ ] Trigger panel shows three buttons: Enable Tracking, Update, Delete (with appropriate colors)
- [ ] Buttons function: Enable/Disable toggles the geofence, Update saves perimeter changes, Delete clears the geofence

**Devices (as admin):**
- [ ] Same chrome
- [ ] Main area shows the device table with name, device_id, type, status, Edit button
- [ ] Trigger panel is GONE — bottom of main panel ends with the table
- [ ] Edit links navigate to existing edit pages

**Profile:**
- [ ] Same chrome
- [ ] Main area shows the three form cards (Profile info, Password, Delete)
- [ ] Trigger panel is GONE

**DeviceClaim modal:**
- [ ] Click the Profile/Claim/Logout icons in Navbar1 — Profile navigates, Logout signs out, Claim opens the modal
- [ ] Modal overlays the current view (whatever Navbar2 item is active)
- [ ] Entering an invalid setup token shows an error message
- [ ] Entering a valid setup token closes the modal and triggers a reload (new device appears in the list)
- [ ] Cancel button closes the modal without submitting

- [ ] **Step 6: Browser DevTools — check Lighthouse Accessibility score**

In Chrome DevTools, run a Lighthouse Accessibility audit on the Dashboard page. Target score: 90+. Fix any contrast errors flagged on text against the glass surfaces (likely candidates: meta labels, slate-500 text on dark backgrounds).

- [ ] **Step 7: Confirm `wolf-ios` is unaffected**

The iOS app is a separate repo and is explicitly out of scope. Quick check:

```bash
ls /Users/mr.casanova/Code/wolf-ios/.expo 2>&1 | head -3
```

Expected: directory exists, untouched. This is sanity — no files in the iOS project should have been modified by this work.

- [ ] **Step 8: Commit (skip)**

No new files in this task; nothing to commit.

---

## Done

When all eleven tasks are complete and Task 11's manual checklist passes:

- All authenticated pages share the new visual identity
- DeviceClaim is a modal accessible from any view
- The Devices admin table fits the theme without a redundant bottom panel
- Profile and Geofence sit comfortably in the new chrome
- The Dashboard primary action (OPEN GARAGE) is the visual focal point at the bottom of the screen
- The animation slot in `GarageOpenButton` and `DashboardCamFrame` is reserved for the deferred animation work — when Dylan provides the source, it slots in without further layout changes.
