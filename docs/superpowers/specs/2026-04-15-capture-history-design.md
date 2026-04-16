# Capture History Page — Design Spec

## Overview

A paginated capture history page accessible to all authenticated users. Regular users see their own captures; admins see all captures with an extra User column. Reuses existing data infrastructure (API endpoint exists for mobile; this adds an Inertia web view).

## Routes & Controller

New controller `CaptureHistoryController` with a single `index` method. Kept separate from `DeviceCaptureController` (which handles triggering and uploads).

| Method | Route | Controller | Middleware |
|--------|-------|------------|------------|
| GET | `/captures` | `CaptureHistoryController@index` | `auth`, `verified` |

### Controller Logic

```php
public function index(Request $request)
{
    $user = $request->user();

    $captures = $user->is_admin
        ? DeviceCapture::with(['user', 'device'])->latest()->paginate(20)
        : $user->captures()->with('device')->latest()->paginate(20);

    return Inertia::render('Captures/History', [
        'captures' => CaptureResource::collection($captures),
        'isAdmin' => $user->is_admin,
    ]);
}
```

## Frontend

### Page: `resources/js/Pages/Captures/History.tsx`

Paginated table layout using `AuthenticatedLayout`.

**Columns:**

| Column | Regular User | Admin |
|--------|-------------|-------|
| Thumbnail | ✓ image preview or camera icon | ✓ |
| Status | ✓ `CaptureStatusBadge` | ✓ |
| Trigger | ✓ "Manual" or "Geo-fence" | ✓ |
| Device | ✓ device name | ✓ |
| Captured At | ✓ formatted timestamp | ✓ |
| User | — | ✓ name + email |

**Thumbnail rules:**
- `status === 'success'` + `media_type === 'image'` → `<img>` thumbnail (small, fixed size)
- `status === 'success'` + `media_type === 'video'` → camera icon
- `status === 'pending'` → spinner
- `status === 'failed'` → error icon

**Empty state:** "No captures yet." centered message when `captures.data` is empty.

**Pagination:** Links rendered at bottom of table using `captures.links` array from Laravel paginator.

### Component: `resources/js/Components/CaptureStatusBadge.tsx`

Pill badge component:

| Status | Color | Label |
|--------|-------|-------|
| `pending` | Yellow | Pending |
| `success` | Green | Success |
| `failed` | Red | Failed |

### Navigation

Add "History" `NavLink` to `AuthenticatedLayout` for all authenticated users, between Dashboard and Devices (Devices only shows for admins).

Desktop nav order: **Dashboard → History → Devices (admin only)**
Mobile nav: same order.

## TypeScript Types

Add to `resources/js/types/index.d.ts`:

```typescript
export interface PaginatedCaptures {
    data: CaptureData[];
    links: { url: string | null; label: string; active: boolean }[];
    meta: {
        current_page: number;
        last_page: number;
        total: number;
    };
}
```

Update `CaptureData` to include optional device and user info:

```typescript
export interface CaptureData {
    id: number;
    trigger_source: string;
    media_type: 'image' | 'video';
    media_url: string | null;
    status: 'pending' | 'success' | 'failed';
    error_message: string | null;
    captured_at: string;
    device?: { name: string };
    user?: { name: string; email: string };
}
```

## Data Flow

1. User visits `/captures`
2. `CaptureHistoryController@index` queries captures based on role
3. Paginated results + `isAdmin` flag passed to Inertia page
4. Table renders with conditional User column based on `isAdmin`
5. Pagination links navigate to `/captures?page=N`

## Tests

New `tests/Feature/CaptureHistoryTest.php`:

- Regular user can access `/captures` and sees only their own captures
- Admin can access `/captures` and sees all captures
- Unauthenticated user is redirected to login
- Pagination works (returns 20 per page)

## Scope

**In scope:**
- Web Inertia page for capture history
- Role-based data filtering (user vs admin)
- `CaptureStatusBadge` component
- Navigation link for all users
- Feature tests

**Out of scope:**
- Filtering by date range, status, or device
- Sorting controls
- Capture detail/show page
- Export/download
