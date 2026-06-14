# Wolf iOS — React Native App Spec

**For the implementing agent:** This is a build spec for a new React Native iOS app that consumes the existing Wolf Laravel backend. You have **read-only access to the `wolf/` repository** for reference (API contracts, data shapes, UX patterns). All new code goes into a separate project directory — do not modify `wolf/`.

---

## Mission

Build a React Native iOS app that mirrors the Wolf web dashboard's functionality with **one critical architectural difference**: garage triggering uses **OS-level geofencing via Core Location** instead of the web's time-based scheduling fallback.

The web app fights a platform limit (browsers can't do reliable background GPS); the iOS app sidesteps it entirely by letting the OS handle boundary detection. The app does effectively zero polling — it sleeps until iOS hands it a "user crossed the fence" callback, then makes one API call.

## Constraints

- **iOS only** for this first deliverable. Android is out of scope.
- **Read-only access to `wolf/`** — reference for API shapes, data models, web UX. No edits.
- **Free Apple Developer tier acceptable for now** — Dylan will sideload via Xcode for personal testing; paid program activates only when distribution becomes the goal.
- **Backend has some gaps** — a small number of new endpoints need to be added on the wolf side (listed below). The mobile agent does NOT implement these; flag them for Dylan to handle separately.

---

## Tech Stack

### Required
- **React Native via Expo** (managed workflow). Provides location + background tasks + notifications without ejecting.
- **TypeScript** (strict mode)
- **React Navigation 7** — stack + bottom tabs
- **TanStack Query v5** — server state, caching, retry, refetch
- **Zustand** — minimal client state (auth token, UI flags). Avoid Redux for this scope.
- **axios** — HTTP client
- **NativeWind** — Tailwind classes for React Native (UX parity with web's Tailwind look)

### Required Expo modules
- `expo-secure-store` — store the Sanctum API token in iOS Keychain
- `expo-location` — foreground + background location, geofencing primitives
- `expo-task-manager` — background task registration for geofence callbacks
- `expo-notifications` — local notifications for trigger confirmations
- `expo-camera` (optional, for QR-based device claim scanning)
- `expo-image-picker` (optional, profile photo)

### Required RN libraries
- `react-native-maps` — uses Apple Maps on iOS, identical UX surface to the web's Leaflet map
- `react-native-video` OR `expo-av` — ESP32-cam stream playback
- `@laravel/echo` + `pusher-js` — WebSocket subscription to the Reverb backend for camera stream signaling

### Forbidden / Avoid
- Don't use Redux unless you can justify it; Zustand is enough.
- Don't bring in a UI kit (NativeBase, RN Paper, etc.); use NativeWind + your own primitives. The web is Tailwind + custom — match that pattern.
- Don't eject from Expo unless absolutely necessary. Background geofencing works in managed workflow via `expo-location`.

---

## Project Layout

Suggest: a sibling directory to `wolf/`.

```
/Users/mr.casanova/Code/
  wolf/              ← existing Laravel backend (read-only for you)
  wolf-ios/          ← new React Native app (your work goes here)
```

Inside `wolf-ios/`, follow Expo + RN conventions:

```
wolf-ios/
  app.json                    # Expo config
  package.json
  tsconfig.json
  babel.config.js
  src/
    api/                      # axios client, typed endpoint wrappers
      client.ts
      auth.ts
      devices.ts
      geofence.ts
      types.ts                # mirrors wolf's response shapes
    components/               # reusable UI (Button, Card, Toggle, etc.)
    screens/                  # one file per screen
      auth/
        LoginScreen.tsx
        RegisterScreen.tsx
        ForgotPasswordScreen.tsx
      dashboard/
        DashboardScreen.tsx
      geofence/
        GeofenceScreen.tsx
        GeofenceMapScreen.tsx
      devices/
        DeviceClaimScreen.tsx
      profile/
        ProfileScreen.tsx
    navigation/
      RootNavigator.tsx       # auth vs. app stack switch
      AppNavigator.tsx        # bottom tabs
    stores/
      authStore.ts            # Zustand: token, user
    hooks/
      useDevices.ts           # TanStack Query wrappers
      useGeofence.ts
    services/
      geofenceManager.ts      # OS-level geofence registration + lifecycle
      pushNotifications.ts
    tasks/
      geofenceTask.ts         # background task handler
    utils/
  __tests__/                  # Jest + RN Testing Library
```

---

## Feature Parity Matrix

| Feature | Web (today) | iOS (this build) |
|---|---|---|
| Login | Session cookie | Sanctum token |
| Register | Session cookie | Sanctum token |
| Logout | Cookie clear | Token revoke |
| Device list | Inertia prop | TanStack Query |
| Garage trigger button | POST `/garage/trigger` | Same endpoint, token auth |
| ESP32-cam live stream | HLS via Reverb signaling | Same Reverb signaling, HLS playback in `react-native-video` |
| Geofence create | Address search + Leaflet rectangle | Address search + react-native-maps rectangle |
| Geofence edit/delete | Same | Same |
| **Geofence trigger** | **Time-based scheduling (web limit workaround)** | **OS-level geofencing via Core Location** |
| Geofence arm/disarm | POST `/toggle` | POST `/toggle` + register/unregister OS region |
| Device claim | Manual token entry | QR scan OR manual token entry |
| Profile edit | Form post | Same endpoints |

The trigger flow is the central architectural difference; everything else is the same backend with a token instead of a cookie.

---

## The Core Geofence Flow (iOS-specific)

This is the most important section of the spec. Get this right and the rest is straightforward.

### When the user arms the geofence

```
1. User taps "Arm Geofence" in the iOS app
2. App requests "Always" location permission (if not already granted)
3. App POSTs /geo-fences/{id}/toggle to set is_active=true on the server
4. App registers a CLCircularRegion (via expo-location's startGeofencingAsync)
   - Center: ((north_lat + south_lat) / 2, (east_lng + west_lng) / 2)
   - Radius: max distance from center to any corner
   - Identifier: `wolf-fence-${id}`
5. App stores the active geofence ID in AsyncStorage for the background task
```

### When iOS detects boundary entry (app may be backgrounded or terminated)

```
1. iOS wakes the background task registered via expo-task-manager
2. Background task handler runs:
   - Reads current device location
   - Reads the active fence ID + auth token from secure storage
   - POSTs /geo-fences/{id}/check with { lat, lng }
   - On 200 with triggered=true:
     - Shows local notification: "Garage opening"
     - Unregisters the OS geofence (server set is_active=false)
   - On 200 with triggered=false (server says we're not actually inside):
     - Logs the false positive; no notification
3. Background task completes within iOS's time budget (~30s)
```

### When the user disarms

```
1. User taps "Disarm" in the iOS app
2. App POSTs /geo-fences/{id}/toggle to set is_active=false
3. App calls stopGeofencingAsync to remove the OS region
4. Clears the active fence ID from storage
```

### Key implementation notes

- **iOS limits: max 20 monitored regions per app.** Not a problem (we have one fence per user), but worth knowing.
- **"Always" location permission required.** The "When in Use" permission won't deliver region monitoring events when the app is backgrounded.
- **Background task execution budget is ~30 seconds.** The check POST must complete fast — set axios timeout to 15s.
- **Server-side double-check is intentional.** OS can fire `didEnterRegion` from stale GPS (especially indoors near boundaries). The `/check` endpoint does its own bounds check using the reported coordinates and only triggers if the server agrees. Defense in depth.
- **No client-side bounds calculation needed.** The server already does this in `GeoFenceController::check`. The mobile app just reports its position; the server decides.
- **iOS may not fire `didEnterRegion` if the app installs the geofence while the user is already inside.** This is documented Apple behavior. Not a defect to fix — just be aware. The user will need to leave and re-enter to trigger, or use a manual trigger button.

---

## API Contract

The iOS app talks to the existing `wolf` backend via Sanctum API tokens. All requests except auth endpoints include `Authorization: Bearer {token}` and `Accept: application/json`.

**Base URL:** Set via `EXPO_PUBLIC_API_URL` env var. For local dev pointing at the Docker stack: `http://localhost:8000` (or your machine's LAN IP if testing on a physical device).

### Auth endpoints (NEW — need backend work; see "Backend Changes Required" below)

```
POST /api/v1/auth/login
  Body: { email: string, password: string, device_name: string }
  Response 200: { token: string, user: User }
  Response 422: { errors: { email?: string[], password?: string[] } }
  Response 401: { message: "Invalid credentials" }

POST /api/v1/auth/register
  Body: { name: string, email: string, phone_number: string, password: string, password_confirmation: string, device_name: string }
  Response 201: { token: string, user: User }
  Response 422: { errors: {...} }

POST /api/v1/auth/logout
  Headers: Authorization: Bearer {token}
  Response 204: (no body)

GET /api/v1/auth/user
  Headers: Authorization: Bearer {token}
  Response 200: User
  Response 401: { message: "Unauthenticated" }
```

The `device_name` field on login/register is the standard Sanctum pattern — e.g., "Dylan's iPhone 16". Used so users can see/revoke tokens individually.

### Existing endpoints (work via token auth, no backend change needed)

These already exist in `routes/web.php` under the `auth` middleware. With a Sanctum token in the Authorization header, they accept token auth and respond JSON.

```
GET /api/v1/geo-fences
  Response 200: Geofence[]   # array with 0 or 1 element

POST /api/v1/geo-fences
  Body: { north_lat: number, south_lat: number, east_lng: number, west_lng: number }
  Response 201: Geofence

PUT /api/v1/geo-fences/{id}
  Body: { north_lat, south_lat, east_lng, west_lng }
  Response 200: Geofence

DELETE /api/v1/geo-fences/{id}
  Response 200: { message: "Geofence deleted." }

POST /api/v1/geo-fences/{id}/toggle
  Response 200: { is_active: boolean }
  # Toggles arm/disarm. iOS calls this when user taps the arm button.

POST /api/v1/geo-fences/{id}/check
  Body: { lat: number, lng: number }
  Response 200: { triggered: boolean, distance_meters: number }
  # Background task calls this on OS boundary entry.
  # Server verifies position is inside, fires servo, sets is_active=false.
```

```
POST /api/v1/garage/trigger
  Response 200: { success: boolean }
  # Manual servo trigger (the "open garage now" button).

GET /api/v1/devices
  Response 200: Device[]

POST /api/v1/devices/claim
  Body: { setup_token: string }
  Response 200: Device
  Response 404: { message: "Invalid setup token" }
```

### Data types (TypeScript)

Mirror what wolf returns. Source of truth: `wolf/resources/js/types/index.d.ts`. Reproduce:

```ts
export interface User {
    id: number;
    name: string;
    email: string;
    phone_number?: string;
    email_verified_at?: string;
    is_admin: boolean;
}

export interface Device {
    id: number;
    user_id: number | null;
    name: string;
    device_id: string;
    type: 'esp32_cam' | 'esp8266';
    is_online: boolean;
    last_seen_at: string | null;
    meta: Record<string, unknown> | null;
}

export interface Geofence {
    id: number;
    user_id: number;
    north_lat: number;
    south_lat: number;
    east_lng: number;
    west_lng: number;
    is_active: boolean;
    // pending_scheduled_trigger is web-only; mobile ignores it
}
```

### Streaming (ESP32-cam)

The web uses Laravel Reverb (WebSocket server) to signal stream start/stop. The actual video is HLS served from the ESP32-cam.

For iOS:
- Use `@laravel/echo` + `pusher-js` configured to point at the Reverb endpoint
- Subscribe to `presence-stream.{streamId}` channel
- When the channel signals an active stream, render `react-native-video` pointing at the HLS URL
- When user backgrounds the app, stop the stream (mirror the web's behavior)

The exact Reverb config (host, port, app key) is in `wolf/.env` as `REVERB_*` and exposed to the web via `VITE_REVERB_*` vars. iOS will need the same values — pass them as `EXPO_PUBLIC_REVERB_*`.

---

## Backend Changes Required (Dylan implements separately — NOT in this iOS build)

These four auth endpoints don't exist yet. Stub them in `wolf/routes/api.php` and `wolf/app/Http/Controllers/Api/V1/AuthController.php`:

```php
// routes/api.php
Route::prefix('v1/auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/register', [AuthController::class, 'register']);
    
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/user', [AuthController::class, 'user']);
    });
});
```

Plus: expose all the existing geofence/device/garage routes under `/api/v1/` with `auth:sanctum` middleware. The cleanest move is to create a `routes/api.php` group that mirrors the web's `auth` group but uses `auth:sanctum` and a `v1` prefix. The controllers are reused — no duplication.

**For the iOS agent:** assume these endpoints exist. If you hit 404s during development, flag it in your status report so Dylan can add them.

---

## Phased Implementation Plan

Each phase produces a working, testable slice. Don't move to phase N+1 until N runs on a device (simulator OK for phases 0-5).

### Phase 0: Project scaffolding (~half a day)

- `npx create-expo-app wolf-ios --template blank-typescript`
- Configure TS strict mode, NativeWind, paths
- Set up axios client with base URL from env
- Stub `RootNavigator` (Stack: Auth | App)
- Zustand `authStore` (token, user, setToken, clearToken)
- Persist token via `expo-secure-store`
- Smoke test: app boots, navigates between blank screens

### Phase 1: Auth flow (~1-2 days)

- LoginScreen: email + password form, POST /auth/login, store token, navigate to App
- RegisterScreen: full form, POST /auth/register
- ForgotPasswordScreen: stub (web hits a Laravel endpoint; mobile can defer)
- Auto-login: on app boot, if token in secure store + GET /auth/user returns 200, navigate to App
- Logout: clear token + secure store, navigate to Auth
- Tests: a mock server + Jest covers happy path + 401 + 422

### Phase 2: Dashboard MVP (~1-2 days)

- DashboardScreen: TanStack Query for `useDevices`
- Render device list (online status pill, type label)
- Garage button for ESP8266: POST /garage/trigger with optimistic UI
- DeviceClaimScreen: manual setup-token entry, POST /devices/claim, refetch list

### Phase 3: Geofence setup (~2-3 days)

- GeofenceScreen: shows current fence or "no fence" empty state
- Address search bar (use Apple's MapKit local search, or OpenStreetMap Nominatim — match what wolf's `AddressSearch.tsx` does)
- Map view with a draggable rectangle handle (`react-native-maps` + custom overlay)
- Save/Update buttons → POST/PUT /geo-fences
- Delete with confirmation

### Phase 4: OS geofencing (the meaty one) (~3-4 days)

This is the architectural centerpiece. Reference the "Core Geofence Flow" section above.

- Request "Always" location permission with clear pre-permission rationale
- Implement `services/geofenceManager.ts`:
  - `armGeofence(geofence: Geofence): Promise<void>` — POST /toggle + `startGeofencingAsync`
  - `disarmGeofence(geofence: Geofence): Promise<void>` — POST /toggle + `stopGeofencingAsync`
- Implement `tasks/geofenceTask.ts`:
  - Define the background task via `TaskManager.defineTask`
  - On `didEnter` event: POST /check with current position
  - On 200 + triggered=true: schedule local notification, clear active fence ID
- Wire arm/disarm UI in GeofenceScreen
- **Test path:** simulator's "Simulate Location" + "Edit Location" lets you fake entries
- **Real-device test:** Dylan needs to walk in/out of the fence with the app installed (this is the milestone moment)

### Phase 5: Live streaming (~2 days)

- Reverb echo client setup (`@laravel/echo` + `pusher-js`)
- Subscribe to stream presence channel
- StreamView component: render `react-native-video` when stream active
- Lifecycle: stop stream when app backgrounds, restart when foregrounded
- Mirror the web's "garage triggered → pause stream → resume" pattern

### Phase 6: Profile + polish (~1-2 days)

- ProfileScreen: edit name/email/phone, change password
- Logout button
- App icon, splash screen, launch screen (use Expo's config)
- Final pass on loading states, error messages, empty states
- TestFlight upload preparation (informational only — Dylan flips to paid Apple Dev when ready to distribute)

---

## Testing Strategy

- **Unit:** Jest + React Native Testing Library for components and hooks
- **Integration:** Mock the wolf API with `msw` or a static handler; cover auth flows, geofence CRUD, error paths
- **E2E (later):** Detox is the canonical choice; defer until Phase 6 unless something feels fragile
- **Manual:** the geofence flow MUST be tested on a real device (Phase 4 milestone); simulator can fake the location event but iOS background scheduling has subtleties that only appear on hardware

---

## Getting Started Commands

For the agent starting fresh in `/Users/mr.casanova/Code/wolf-ios/`:

```bash
# In /Users/mr.casanova/Code/
npx create-expo-app wolf-ios --template blank-typescript
cd wolf-ios

# Core deps
npx expo install expo-secure-store expo-location expo-task-manager expo-notifications
npx expo install react-native-maps react-native-video
npm install @tanstack/react-query zustand axios
npm install @react-navigation/native @react-navigation/native-stack @react-navigation/bottom-tabs
npx expo install react-native-screens react-native-safe-area-context
npm install nativewind tailwindcss

# Reverb / streaming
npm install laravel-echo pusher-js

# Dev
npm install --save-dev @types/react @types/react-native eslint prettier
```

Configure `app.json` with iOS permissions strings (location-always, notifications). Configure `tsconfig.json` for strict mode. Set up `babel.config.js` for NativeWind. Set up `tailwind.config.js` to match Wolf's web color palette (indigo-600 primary, etc. — reference `wolf/tailwind.config.js`).

---

## What to Read in `wolf/` Before Starting

You have read-only access. These files are highest-value reference:

1. **`wolf/resources/js/types/index.d.ts`** — data shapes for User, Device, Geofence
2. **`wolf/app/Http/Controllers/GeoFenceController.php`** — the canonical geofence logic, especially the `check` and `toggle` methods you'll be calling
3. **`wolf/app/Models/GeoFence.php`** — `distanceFromCenter` and `contains` show how the server validates positions
4. **`wolf/resources/js/Pages/Dashboard.tsx`** — UX reference for the device list + geofence card layout
5. **`wolf/resources/js/Pages/Geofence/Index.tsx`** — UX reference for the address search + map + perimeter UI
6. **`wolf/resources/js/Components/GeofenceMap.tsx`** — how the web does the Leaflet rectangle; mimic the interaction pattern in react-native-maps
7. **`wolf/routes/web.php`** — see how endpoints are wired; mobile will hit equivalents under `/api/v1/`
8. **`wolf/.env.example`** — Reverb config keys

**Do NOT modify any of these files.** If you find issues, flag them in your status report; Dylan will address separately.

---

## Open Questions to Surface Before Phase 4

These are decisions Dylan should make before you start the geofencing work:

1. **Push notifications:** When the OS detects boundary entry, do we also want to push a server-initiated notification ("Wolf detected you near home")? This requires APNs setup. Default: defer to a follow-up; Phase 4 ships with local notifications only.
2. **Multi-device support:** What if Dylan has Wolf installed on both iPhone and iPad? Sanctum supports multiple tokens per user. The phone might detect the boundary while the iPad won't (different OS region). The server currently has no concept of which device triggered the check — it just sees a token. Probably fine to defer, but worth noting.
3. **iOS deployment target:** Recommend iOS 15.0+ as the minimum. Apple's geofencing APIs are stable from iOS 13 but newer SwiftUI/Combine patterns are cleaner from 15.

---

## Reporting Back

When you complete each phase, report:

- **Status:** DONE | DONE_WITH_CONCERNS | BLOCKED | NEEDS_CONTEXT
- **What you built:** summary of screens, components, hooks
- **What you tested:** test commands + output
- **Backend gaps surfaced:** 404s or missing endpoints (Dylan handles separately)
- **Open questions:** anything you needed to decide that should have user input

For the geofence flow specifically (Phase 4), include a video or screenshot of the simulator firing a `didEnterRegion` event and the resulting notification + servo trigger. That's the milestone proof.

---

## Why This Project Matters (Context for the Agent)

Dylan is building Wolf as a portfolio piece. The "web is time-based, mobile is location-based" architectural split is itself a strong interview narrative — the web ships first with a deliberate compromise around browser background limits, then the native app is the canonical experience using OS primitives. Your work here is the second half of that story.

Be especially deliberate about Phase 4's geofencing implementation. The code should be readable, the comments should explain *why* (especially around the iOS background-task lifecycle, the server-side double-check rationale, and the "OS won't fire if already inside" quirk). This is the centerpiece module for a hiring-manager demo.
