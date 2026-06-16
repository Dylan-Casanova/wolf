# Perspective Theme — Wolf Web Design Spec

**Date:** 2026-06-16
**Surface:** Wolf web (Laravel + Inertia + React + Tailwind)
**Out of scope (future work):** Wolf iOS (React Native), authenticated-page mobile-portrait responsive treatment, the trigger button's complex animation (deferred pending animation source assets)

## Goal

Replace Wolf web's current plain-Tailwind authenticated UI with a unified theme inspired by the "ART VR / The Virtual Gallery" perspective layout (referenced by Dylan via screenshots; designer attribution: "Sophia Lin"-styled). The theme establishes a cinematic dark-mode shell with floating glass panels, atmospheric edge color leaks, and a consistent left-rail + center-panel + bottom-trigger structure that adapts per route.

The goal is **brand identity through visual consistency** — Wolf should feel like a single product rather than a Laravel admin scaffold. The redesign covers all authenticated pages so that once a user logs in, they're inside the "Wolf experience" until they log out.

## Scope

### In scope

All authenticated pages, sharing the same layout chrome:

- **Dashboard** — primary garage control surface
- **Geofence** — perimeter configuration + tracking control
- **Devices** (admin-only) — device management table
- **Profile** — user account form
- **DeviceClaim** — currently a separate route; this spec promotes it to an overlay modal triggered from Navbar1

### Out of scope (explicitly excluded)

- **Login, Register, Forgot Password** — these intentionally stay on plain Tailwind. Rationale: unauthenticated users don't need the heavy atmospheric treatment, and the auth flow should be fast and uncluttered.
- **Wolf iOS app** — the same theme will be re-applied in a future React Native pass once the web version is stable.
- **The trigger button's animation itself** — design specifies the *container* and *idle state* for the animation, but the animation source (video, Lottie, CSS, etc.) is deferred until Dylan provides the animation reference screenshots.
- **Mobile portrait responsive layout** — the design is built for desktop and tablet-landscape. A separate mobile-portrait pass will be planned later; for now, mobile users will see a degraded but functional layout (probably the panel stacks vertically and the Navbar1 rail collapses to a top header).

## Visual vocabulary

### Color palette

| Role | Value | Usage |
|---|---|---|
| **Void background** | `#050510` (near-black with cool tint) | Outermost canvas, behind everything |
| **Panel surface** | `rgba(15, 20, 40, 0.55)` | Main glass panels — Navbar1 rail and the main panel |
| **Panel border** | `rgba(255, 255, 255, 0.08)` | 1px borders that catch ambient light |
| **Inner card** | `rgba(255, 255, 255, 0.03)` | Inactive nav items, table rows |
| **Active accent** | `rgba(99, 102, 241, 0.18)` background, `rgba(99, 102, 241, 0.4)` border | Selected nav item, primary CTAs |
| **Primary action** | `linear-gradient(135deg, #6366f1 0%, #4338ca 100%)` | Trigger buttons, primary CTAs |
| **Danger action** | `linear-gradient(135deg, #ef4444 0%, #b91c1c 100%)` | Delete buttons, destructive actions |
| **Online status** | `#22c55e` with glow | Device-online indicators |
| **Offline status** | `#475569` (slate) | Device-offline indicators |
| **Text primary** | `#fff` (white) | Headings, names |
| **Text secondary** | `#cbd5e1` (slate-300) | Body text |
| **Text tertiary** | `#94a3b8` (slate-400) | Labels, meta info |
| **Text muted** | `#64748b` (slate-500) | Captions, disabled states |

### Atmospheric edge glows

Layered radial gradients on the outer scene background, creating the cinematic "dimly-lit room" feel:

```css
background:
  radial-gradient(ellipse 60% 50% at 100% 100%, rgba(239,68,68,0.18) 0%, transparent 60%),
  radial-gradient(ellipse 50% 40% at 0% 30%, rgba(56,189,248,0.10) 0%, transparent 60%),
  radial-gradient(ellipse 80% 60% at 50% 0%, rgba(244,63,94,0.08) 0%, transparent 50%),
  #050510;
```

Plus a thin red sliver at the top edge as a signature decorative element (~2px high `linear-gradient` with `box-shadow: 0 0 12px #ef4444`).

### Glass treatment

All major panels use:

- `background: rgba(15, 20, 40, 0.55)` — translucent navy fill
- `backdrop-filter: blur(20px to 28px)` (and `-webkit-backdrop-filter`)
- `border: 1px solid rgba(255, 255, 255, 0.08)` — subtle bevel highlight
- `border-radius: 22px` (Navbar1 rail) or `32px` (main panel) — generous rounding
- `box-shadow: 0 30px 80px rgba(0,0,0,0.55), inset 0 1px 0 rgba(255,255,255,0.06)` — depth + inner top highlight

### Typography

- **System font stack:** `ui-sans-serif, system-ui, sans-serif` (no webfont download — matches current Wolf decision)
- **Logo title (WOLF):** 22px, 800 weight, white, `letter-spacing: 0.5px`
- **Logo tagline (GARAGE CONTROL):** 10px, regular weight, slate-400, `letter-spacing: 4px`, uppercase
- **Section headers:** 14px, 600 weight, white
- **Body text:** 13-14px, 400-500 weight, slate-300
- **Meta / labels:** 10-11px, slate-400, often `letter-spacing: 2px` and uppercase

### Border radii system

| Element | Radius |
|---|---|
| Outer main panel | 32px |
| Navbar1 rail | 22px |
| Main display area / large inner card | 18px |
| Nav row / button | 14-16px |
| Number badge / small chip | 8-10px |

### Decision: no 3D perspective tilt

The reference theme uses a Y-axis rotation transform to make panels feel like they're floating in 3D space. **We are explicitly NOT replicating that.** Tradeoffs that drove the decision:

- Leaflet's drag handles and the geofence rectangle don't play well with tilted parents
- Form inputs (login, register, profile) have hit-test inconsistencies in transformed containers
- Mobile responsive scaling is dramatically harder with rotated content
- The atmospheric edge glows + glassmorphism do most of the spatial work already

The flat treatment retains all the premium feel without the implementation cost.

## Layout architecture

### Shared chrome (applies to all authenticated pages)

```
┌──────────────────────────────────────────────────────────────────┐
│ STAGE BACKGROUND (atmospheric gradients + red top sliver)        │
│                                                                  │
│  ┌──────┐   ┌──────────────────────────────────────────────┐    │
│  │      │   │ MAIN PANEL                                   │    │
│  │      │   │ ┌─────────────────────────────────────────┐  │    │
│  │ NAV  │   │ │ Logo + tagline           Hi, <user>    │  │    │
│  │ BAR  │   │ └─────────────────────────────────────────┘  │    │
│  │  1   │   │ ┌─────────┐ ┌─────────────────────────────┐  │    │
│  │      │   │ │ NAVBAR2 │ │ MAIN AREA                   │  │    │
│  │      │   │ │  list   │ │ (view-specific content)     │  │    │
│  │      │   │ │ Dashb.  │ │                             │  │    │
│  │      │   │ │ Geofen. │ ├─────────────────────────────┤  │    │
│  │      │   │ │ Devices │ │ status / device name strip  │  │    │
│  │      │   │ │ (admin) │ └─────────────────────────────┘  │    │
│  │      │   │ └─────────┘                                  │    │
│  │      │   │ ┌────────────────────────────────────────┐   │    │
│  │      │   │ │ TRIGGER BUTTON AREA                    │   │    │
│  │      │   │ │ (view-specific, hidden on Devices)    │   │    │
│  │      │   │ └────────────────────────────────────────┘   │    │
│  │      │   └──────────────────────────────────────────────┘    │
│  └──────┘                                                        │
└──────────────────────────────────────────────────────────────────┘
```

### Navbar1 (outer rail)

- Floating glass pill, vertically oriented
- Position: outside the main panel, far left
- Three icon buttons (vertically stacked):
  1. **Profile** — opens Profile page
  2. **Claim device** — opens DeviceClaim modal overlay
  3. **Logout** — triggers logout action

### Navbar2 (inner left column of main panel)

- Vertical list of view selectors
- Items:
  - **Dashboard** (always visible)
  - **Geofence** (always visible)
  - **Devices** (admin role only — hidden for non-admin users)
- Active row: lighter background, gradient badge (red→indigo), subtle inner glow
- Inactive rows: muted background, plain number badge

### Main area (per-view)

#### Dashboard

- **Vertical split:** top half + bottom half
- **Top half (animation/cam area):**
  - If user has an online ESP32-cam: live camera stream
  - If no cam: static idle frame of the trigger button animation (cohesive with the eventual animation, so the transition into the animation is seamless)
- **Bottom half (geofence mini-control):**
  - Compact status + arm/disarm toggle, mirroring the Geofence page's tracking control but in a smaller footprint
  - Shows: armed/disarmed state, distance from perimeter, arm/disarm button
  - Tapping does NOT navigate to the Geofence page — it acts locally
- **Status strip below main area:** active device name + online indicator

#### Geofence

- **Main area:** the full Leaflet map (perimeter rectangle, drag handles, red address marker — all the work already done)
- **Status strip below:** perimeter status (Active / Disarmed), perimeter dimensions

#### Devices (admin)

- **Main area:** device table with columns Name, Device ID, Type, Status
- **No bottom status strip** (the table itself is the content)
- **Trigger button area HIDDEN** — main area grows to fill freed vertical space

#### Profile

- **Main area:** the existing profile form (name, email, phone, password change)
- **No bottom status strip**
- **Trigger button area HIDDEN** — same treatment as Devices

#### DeviceClaim (overlay modal)

- Renders as a centered modal overlay on top of whichever view is currently active
- Glass surface (matches the theme), darker backdrop
- Setup token input + Claim button + Cancel
- Triggered from Navbar1's "Claim device" icon
- On success: invalidates devices query, closes modal, surfaces the new device in whichever list it belongs to

### Trigger button area (per-view)

| View | Content |
|---|---|
| Dashboard | One big primary button: **OPEN GARAGE** (with the complex animation when tapped, see "Animation" section) |
| Geofence | Three buttons in a row: **ENABLE TRACKING** (primary), **UPDATE** (neutral outline), **DELETE** (danger red) |
| Devices | Hidden — area is removed from DOM, main area grows |
| Profile | Hidden |
| DeviceClaim | N/A — modal overlay, no chrome change |

### Status strip (between main area and trigger button area)

A small one-line row that shows the current "subject" of the page:

- Dashboard: active device name + online indicator
- Geofence: perimeter name (e.g., "Home Perimeter") + active status + perimeter size
- Devices, Profile: hidden

## Component breakdown

New components to create:

| Component | Location | Responsibility |
|---|---|---|
| `StageBackground` | `resources/js/Components/Theme/StageBackground.tsx` | Renders the atmospheric outer canvas (gradients + red top sliver). Used by the layout. |
| `GlassPanel` | `resources/js/Components/Theme/GlassPanel.tsx` | Reusable glass surface with configurable radius, padding, and backdrop blur. Used for main panel, Navbar1 rail, inner cards. |
| `Navbar1` | `resources/js/Components/Theme/Navbar1.tsx` | The outer icon rail with Profile / Claim / Logout actions. |
| `Navbar2` | `resources/js/Components/Theme/Navbar2.tsx` | The inner list nav with Dashboard / Geofence / Devices(admin) items. Active state managed via Inertia's current page. |
| `WolfLogo` | `resources/js/Components/Theme/WolfLogo.tsx` | The "WOLF / GARAGE CONTROL" branding block, top-left of the main panel. |
| `UserBadge` | `resources/js/Components/Theme/UserBadge.tsx` | "Hi, <username>" greeting, top-right of the main panel. |
| `StatusStrip` | `resources/js/Components/Theme/StatusStrip.tsx` | The thin one-line "name + online status" strip. |
| `TriggerPanel` | `resources/js/Components/Theme/TriggerPanel.tsx` | Glass container that wraps the trigger area content. Conditionally rendered per view. |
| `GarageOpenButton` | `resources/js/Components/Theme/GarageOpenButton.tsx` | The big primary "OPEN GARAGE" button. Animation slot is inside this component — when animation source arrives, it slots in here. |
| `GeofenceActionRow` | `resources/js/Components/Theme/GeofenceActionRow.tsx` | The three-button row for the Geofence view (Enable / Update / Delete). |
| `DeviceClaimModal` | `resources/js/Components/Theme/DeviceClaimModal.tsx` | Overlay-style claim form, replacing the current `/devices/claim` page route. |
| `GeofenceMiniControl` | `resources/js/Components/Theme/GeofenceMiniControl.tsx` | The compact arm/disarm control used in the Dashboard's bottom half. |
| `DashboardCamFrame` | `resources/js/Components/Theme/DashboardCamFrame.tsx` | The Dashboard top-half — renders the live cam stream OR the static animation idle frame depending on whether an online ESP32-cam exists. |

Layout to modify:

| Component | Location | Change |
|---|---|---|
| `AuthenticatedLayout` | `resources/js/Layouts/AuthenticatedLayout.tsx` | Full rewrite: renders `<StageBackground>`, the main panel chrome (Logo, UserBadge, Navbar1, Navbar2), and slots in the page content for the active view's main area + trigger panel. |

Pages to update:

| Page | Change |
|---|---|
| `Dashboard.tsx` | Render `DashboardCamFrame` + `GeofenceMiniControl` + `GarageOpenButton`, integrate with the new layout |
| `Geofence/Index.tsx` | Use the existing map but wrap it in the new chrome; replace the inline action buttons with `GeofenceActionRow` |
| `Devices/Index.tsx` (admin) | Render the existing devices table in the new chrome; signal to layout that no trigger panel should be shown |
| `Profile/Edit.tsx` | Wrap the existing form in the new chrome |

Pages to remove or repurpose:

| Page | Action |
|---|---|
| `Devices/Claim.tsx` | Remove the page route. Replace with `DeviceClaimModal` triggered from Navbar1. Update `routes/web.php` accordingly. |

## Tailwind config additions

Extend `tailwind.config.js` with semantic theme tokens so we're not littering arbitrary RGBA values across components:

```js
theme: {
  extend: {
    colors: {
      'wolf-void': '#050510',
      'wolf-glass': 'rgba(15, 20, 40, 0.55)',
      'wolf-glass-border': 'rgba(255, 255, 255, 0.08)',
      'wolf-card': 'rgba(255, 255, 255, 0.03)',
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
  }
}
```

## Implementation strategy

### Phasing

1. **Theme primitives first.** Build `StageBackground`, `GlassPanel`, `WolfLogo`, `UserBadge`. No page wiring yet. Demonstrate the visual on a single page first.
2. **Layout rewrite.** Replace `AuthenticatedLayout` to render the new chrome. Pages still render their old content inside the new chrome's main slot — visually consistent, no functional regressions.
3. **Dashboard.** Build `DashboardCamFrame`, `GeofenceMiniControl`, the static-idle-frame placeholder (which becomes the animation slot later). Rewrite `Dashboard.tsx` to use them.
4. **Geofence.** Build `GeofenceActionRow`, integrate with the existing map. Replace the current inline buttons.
5. **Devices (admin).** Re-style the existing devices table to match the theme. Hide the trigger panel.
6. **Profile.** Re-style the existing form.
7. **DeviceClaim → modal.** Build `DeviceClaimModal`. Update Navbar1 to trigger it. Remove the standalone `/devices/claim` route (or keep it as a fallback for direct URL access).
8. **Animation slot.** Wait for animation source from Dylan, then integrate it into `GarageOpenButton`.

Each phase is independently mergeable — the prior visual works at every step.

### Performance considerations

- `backdrop-filter: blur()` is GPU-accelerated but **expensive on weaker devices**. We're using it on at most 2-3 panels per page, which is well within budget for modern browsers.
- The atmospheric gradients are CSS-only, no images or canvas. Cheap.
- The map (Leaflet) renders as before — no parent transform, no contortions.
- The animation slot is the highest-risk performance item; we'll evaluate it once the animation source is available (video vs. Lottie vs. CSS vs. canvas all have very different cost profiles).

### Mobile fallback (not implemented in this pass)

Below `lg` breakpoint (~1024px), the layout should:

- Hide Navbar1 outer rail, replacing it with a hamburger menu in the top-right
- Collapse Navbar2 into a horizontal tab row above the main area
- Main area + trigger button area stack vertically as before
- Atmospheric edge glows scale down proportionally

A separate ticket will cover this once the desktop version is locked.

## What the user gains

| Today | With theme |
|---|---|
| Plain Tailwind dashboard, looks generic | Cinematic dark mode with brand identity from login forward |
| Each page has its own ad-hoc layout | Consistent chrome across all authenticated pages |
| Garage trigger is a basic indigo button | Big primary action with reserved slot for a complex animation |
| Geofence buttons sit inline next to the map | Dedicated action row makes them more discoverable |
| Devices claim is a full-page redirect | Modal overlay keeps you in context |

## Risks and open questions

| Risk / Question | Plan |
|---|---|
| `backdrop-filter` performance on low-end hardware | Acceptable for Wolf's audience (motorcycle riders with modern phones); revisit if reports come in |
| Animation source not yet available | All animation work blocks on Dylan providing the source. The static idle-frame container can be built now and accept the animation when ready. |
| Mobile portrait isn't handled | Separate ticket. Desktop-first is acceptable for Wolf's primary use case (riders set up at home before riding). |
| Hover/focus states not fully spec'd | Will follow the same color system (active = `wolf-active`, focus ring = indigo glow). Implementer to handle inline. |
| Inertia router transitions between views | Use Inertia's standard page transitions; no custom transition needed for v1. |
| Accessibility (screen readers, keyboard nav) | All buttons keep focus ring, semantic HTML, ARIA where appropriate. Color contrast (white on rgba(15,20,40,0.55)) needs verification against WCAG AA. |

## Testing strategy

- **Manual visual smoke test** across all five views (Dashboard, Geofence, Devices, Profile, DeviceClaim modal) at desktop resolution.
- **Existing test suite** must continue to pass — the redesign is presentational, no backend or behavioral changes.
- **Lighthouse accessibility audit** before merge to verify contrast and semantic structure.
- **Browser matrix:** test in Chrome, Safari, and Firefox latest (no IE / legacy support needed).
