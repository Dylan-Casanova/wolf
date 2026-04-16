# Device Registration (Admin-Only) — Design Spec

## Overview

Admin-only device management page where admins can create, view, edit, and delete ESP32-CAM devices and assign them to users. One device per user in V1.0.

## Auth & Roles

- New `is_admin` boolean column on `users` table (default `false`).
- New `AdminMiddleware` that returns 403 if `!auth()->user()->is_admin`.
- "Devices" nav link only rendered when `auth.user.is_admin` is `true`.

## Routes

All routes use `auth`, `verified`, and `admin` middleware.

| Method | Route                               | Controller Method               | Purpose           |
|--------|-------------------------------------|---------------------------------|--------------------|
| GET    | `/devices`                          | `DeviceController@index`        | List all devices   |
| GET    | `/devices/create`                   | `DeviceController@create`       | Create form        |
| POST   | `/devices`                          | `DeviceController@store`        | Store new device   |
| GET    | `/devices/{device}/edit`            | `DeviceController@edit`         | Edit form          |
| PUT    | `/devices/{device}`                 | `DeviceController@update`       | Update device      |
| DELETE | `/devices/{device}`                 | `DeviceController@destroy`      | Delete device      |
| POST   | `/devices/{device}/regenerate-token`| `DeviceController@regenerateToken` | Regenerate token |

## Backend

### DeviceController

Standard resource controller plus a `regenerateToken` method.

- **index**: Query all devices with their `user` relationship, pass to Inertia `Devices/Index` page.
- **create**: Pass list of users (id, name, email) to Inertia `Devices/Create` page. Only include users who do not already have a device (one device per user in V1.0).
- **store**: Validate via `StoreDeviceRequest`, create device, call `$device->generateToken()`, redirect to index with plain-text token flashed.
- **edit**: Pass device (with user) and list of users to Inertia `Devices/Edit` page. Only include users who do not already have a device, plus the device's current user.
- **update**: Validate via `UpdateDeviceRequest`, update device fields, redirect to index.
- **destroy**: Delete device, redirect to index.
- **regenerateToken**: Call `$device->generateToken()`, redirect back with plain-text token flashed.

### Form Request Validation

**StoreDeviceRequest:**
- `name`: required, string, max:255
- `device_id`: required, string, max:255, unique:devices,device_id
- `user_id`: required, exists:users,id, unique:devices,user_id (one device per user)
- `type`: sometimes, string, max:255, defaults to "esp32-cam"

**UpdateDeviceRequest:**
- `name`: required, string, max:255
- `device_id`: required, string, max:255, unique:devices,device_id,{current_device_id}
- `user_id`: required, exists:users,id, unique:devices,user_id,{current_device_id}
- `type`: sometimes, string, max:255

### Migration

Add `is_admin` boolean column to `users` table:

```php
Schema::table('users', function (Blueprint $table) {
    $table->boolean('is_admin')->default(false)->after('email_verified_at');
});
```

### Middleware

`AdminMiddleware` registered in `bootstrap/app.php`:

```php
public function handle(Request $request, Closure $next): Response
{
    if (! $request->user()?->is_admin) {
        abort(403);
    }
    return $next($request);
}
```

## Frontend

### Pages

All pages use `AuthenticatedLayout`.

#### Devices/Index (`/devices`)

Table listing all devices:

| Column        | Content                                      |
|---------------|----------------------------------------------|
| Name          | Device name                                  |
| Device ID     | MQTT identifier (e.g. "esp32-001")           |
| Assigned User | User name and email                          |
| Type          | Device type                                  |
| Status        | Online/offline badge (`DeviceStatusBadge`)   |
| Last Seen     | Relative timestamp or "Never"                |
| Actions       | Edit link, Delete button                     |

- "Add Device" button in top-right corner.
- Delete triggers a confirmation modal, then sends DELETE request.
- `DeviceTokenBanner` shown at top when flash contains a token (after create or regenerate).

#### Devices/Create (`/devices/create`)

Form with fields:
- **Name** — text input
- **Device ID** — text input (the MQTT identifier)
- **Assign to User** — dropdown/select of available users (those without a device)
- **Type** — text input, pre-filled with "esp32-cam"

Submit POSTs to `/devices`. On success, redirects to index with token flash.

#### Devices/Edit (`/devices/{device}/edit`)

Same fields as create, with current values pre-filled. `device_id` is editable.

Additional element:
- **"Regenerate Token"** button with confirmation dialog. POSTs to `/devices/{device}/regenerate-token`. On success, redirects back with token flash.

Submit PUTs to `/devices/{device}`.

### Components

#### DeviceTokenBanner

Amber/yellow alert banner displayed when flash data contains a device token.

Contents:
- Heading: "Device Token Generated"
- Warning: "Copy this token now. It will not be shown again."
- Token displayed in monospace font
- "Copy" button that copies token to clipboard
- Dismissible

#### DeviceStatusBadge

Small pill/badge component:
- Green with "Online" text when `is_online` is true
- Gray with "Offline" text when `is_online` is false

### Navigation

In `AuthenticatedLayout`:
- Add "Devices" `NavLink` pointing to `/devices`, rendered only when `auth.user.is_admin` is `true`.
- Same conditional link in the responsive/mobile navigation section.

### TypeScript Types

Add to `resources/js/types/index.d.ts`:

```typescript
interface Device {
    id: number;
    user_id: number;
    name: string;
    device_id: string;
    type: string;
    is_online: boolean;
    last_seen_at: string | null;
    meta: Record<string, unknown> | null;
    user?: User;
}
```

Update existing `User` interface:

```typescript
interface User {
    id: number;
    name: string;
    email: string;
    email_verified_at?: string;
    is_admin: boolean;
}
```

## Data Flow

1. Admin visits `/devices` → sees table of all devices with status.
2. Admin clicks "Add Device" → fills form, picks user from dropdown → submits.
3. Backend creates device, generates token, redirects to index with token flashed.
4. `DeviceTokenBanner` appears at top of index page — admin copies token for the ESP32.
5. If token is lost later, admin edits the device and clicks "Regenerate Token" → same banner appears.
6. Regular users never see the Devices nav link or page — middleware returns 403 if they try to access directly.

## Scope Boundaries

**In scope (V1.0):**
- Admin CRUD for devices
- One device per user
- Token generation and regeneration
- `is_admin` boolean role system

**Out of scope:**
- User self-service device registration
- Multiple devices per user
- Device firmware OTA updates
- Device configuration/settings beyond name/type
- Role-based permissions beyond admin/user
