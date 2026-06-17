import { useSyncExternalStore } from 'react';

/**
 * Global "busy" state. Uses reference counting so concurrent operations don't
 * step on each other — the overlay stays up until the LAST in-flight op
 * finishes, not the first.
 *
 * Usage:
 *   await withBusy(async () => {
 *       const pos = await getCurrentPosition();
 *       await axios.post('/api/endpoint', pos);
 *   });
 *
 * Or read the state to disable UI:
 *   const isBusy = useBusy();
 */

let busyCount = 0;
const listeners = new Set<() => void>();

function notify() {
    listeners.forEach((l) => l());
}

export function startBusy(): void {
    busyCount += 1;
    notify();
}

export function stopBusy(): void {
    busyCount = Math.max(0, busyCount - 1);
    notify();
}

export function useBusy(): boolean {
    return useSyncExternalStore(
        (l) => {
            listeners.add(l);
            return () => {
                listeners.delete(l);
            };
        },
        () => busyCount > 0,
        () => false,
    );
}

/**
 * Wrap an async function so the busy overlay shows for the duration. The
 * stopBusy() call is guaranteed even if `fn` throws.
 */
export async function withBusy<T>(fn: () => Promise<T>): Promise<T> {
    startBusy();
    try {
        return await fn();
    } finally {
        stopBusy();
    }
}
