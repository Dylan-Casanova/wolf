import { useBusy } from '@/Stores/busyStore';

/**
 * Full-screen overlay rendered whenever any caller has wrapped work in
 * withBusy(). Sits above everything else (z-[2000]) so it covers Leaflet,
 * modals, etc.
 */
export function BusyOverlay() {
    const busy = useBusy();
    if (!busy) return null;

    return (
        <div
            role="status"
            aria-live="polite"
            aria-busy="true"
            className="fixed inset-0 z-[2000] flex items-center justify-center bg-black/40 backdrop-blur-sm"
        >
            <div className="h-12 w-12 animate-spin rounded-full border-4 border-indigo-500 border-t-transparent" />
            <span className="sr-only">Loading…</span>
        </div>
    );
}
