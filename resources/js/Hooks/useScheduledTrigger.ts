import { withBusy } from '@/Stores/busyStore';
import type { Geofence } from '@/types';
import { formatRemaining } from '@/utils/time';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { useEffect, useState } from 'react';

interface Origin {
    lat: number;
    lng: number;
}

interface Estimate {
    distance_miles: number;
    estimated_minutes: number;
}

/**
 * Owns the "enable → GPS → estimate → schedule → cancel" flow for a
 * geofence page. Bundles:
 *   - countdown tick + auto-reload when a pending trigger fires
 *   - enable-button label / loading / error / variant
 *   - schedule-modal open/close and confirmation
 *
 * The page consumes the returned shape as UI-render inputs; it doesn't
 * touch trigger-state directly.
 */
export function useScheduledTrigger(geofence: Geofence | null) {
    const pendingTrigger = geofence?.pending_scheduled_trigger ?? null;

    // Live countdown while a trigger is pending. Ticks once per second.
    const [now, setNow] = useState(() => Date.now());
    useEffect(() => {
        if (!pendingTrigger) return;
        const id = setInterval(() => setNow(Date.now()), 1000);
        return () => clearInterval(id);
    }, [pendingTrigger]);

    const remainingMs = pendingTrigger
        ? new Date(pendingTrigger.scheduled_at).getTime() - now
        : 0;

    // When the countdown hits zero, wait a beat then reload so the server-side
    // job has time to run and flip the trigger row to `fired`.
    useEffect(() => {
        if (!pendingTrigger) return;
        if (remainingMs > 0) return;
        const t = setTimeout(() => router.reload(), 3000);
        return () => clearTimeout(t);
    }, [pendingTrigger, remainingMs > 0]); // eslint-disable-line react-hooks/exhaustive-deps

    // Enable/schedule flow state
    const [origin, setOrigin] = useState<Origin | null>(null);
    const [estimate, setEstimate] = useState<Estimate | null>(null);
    const [scheduleOpen, setScheduleOpen] = useState(false);
    const [enableLoading, setEnableLoading] = useState(false);
    const [enableError, setEnableError] = useState<string | null>(null);

    const enableLabel = pendingTrigger
        ? `CANCEL · ${formatRemaining(remainingMs)}`
        : 'ENABLE TRACKING';

    const enableVariant: 'primary' | 'cancel' = pendingTrigger
        ? 'cancel'
        : 'primary';

    /**
     * When a pending trigger exists, the enable button becomes a cancel
     * action. Otherwise it kicks off the schedule flow: get GPS once, ask
     * the backend to estimate distance and travel time, then open the modal.
     */
    const handleEnable = async (): Promise<void> => {
        if (!geofence) return;

        if (pendingTrigger) {
            try {
                await axios.delete(
                    `/geo-fences/${geofence.id}/scheduled-trigger`,
                );
                router.reload();
            } catch {
                setEnableError('Failed to cancel the scheduled trigger.');
            }
            return;
        }

        setEnableError(null);
        if (!navigator.geolocation) {
            setEnableError('Your browser does not support location services.');
            return;
        }

        setEnableLoading(true);
        try {
            await withBusy(async () => {
                const position = await new Promise<GeolocationPosition>(
                    (resolve, reject) => {
                        navigator.geolocation.getCurrentPosition(
                            resolve,
                            reject,
                            { enableHighAccuracy: true, timeout: 10000 },
                        );
                    },
                );

                const lat = position.coords.latitude;
                const lng = position.coords.longitude;

                const response = await axios.post(
                    `/geo-fences/${geofence.id}/estimate`,
                    { lat, lng },
                );

                setOrigin({ lat, lng });
                setEstimate({
                    distance_miles: response.data.distance_miles,
                    estimated_minutes: response.data.estimated_minutes,
                });
                setScheduleOpen(true);
            });
        } catch {
            setEnableError(
                'Failed to get your location. Please allow access and try again.',
            );
        } finally {
            setEnableLoading(false);
        }
    };

    const handleScheduleConfirm = async (minutes: number): Promise<void> => {
        if (!geofence || !origin) return;
        setScheduleOpen(false);
        setEnableLoading(true);
        try {
            await withBusy(() =>
                axios.post(`/geo-fences/${geofence.id}/schedule-trigger`, {
                    minutes,
                    origin_lat: origin.lat,
                    origin_lng: origin.lng,
                }),
            );
            router.reload();
        } catch {
            setEnableError('Failed to schedule the trigger.');
        } finally {
            setEnableLoading(false);
        }
    };

    const closeSchedule = (): void => setScheduleOpen(false);

    return {
        remainingMs,
        enableLabel,
        enableLoading,
        enableError,
        enableVariant,
        handleEnable,
        scheduleOpen,
        estimate,
        handleScheduleConfirm,
        closeSchedule,
    };
}
