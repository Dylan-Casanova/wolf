import ScheduleModal from '@/Components/ScheduleModal';
import { withBusy } from '@/Stores/busyStore';
import type { Geofence } from '@/types';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { useEffect, useState } from 'react';

interface Props {
    geofence: Geofence | null;
}

interface Origin {
    lat: number;
    lng: number;
}

interface Estimate {
    distance_miles: number;
    estimated_minutes: number;
}

const formatRemaining = (ms: number): string => {
    if (ms <= 0) return '00:00';
    const total = Math.floor(ms / 1000);
    const hours = Math.floor(total / 3600);
    const minutes = Math.floor((total % 3600) / 60);
    const seconds = total % 60;
    const mm = String(minutes).padStart(2, '0');
    const ss = String(seconds).padStart(2, '0');
    return hours > 0 ? `${hours}:${mm}:${ss}` : `${mm}:${ss}`;
};

export function GeofenceMiniControl({ geofence }: Props) {
    const pendingTrigger = geofence?.pending_scheduled_trigger ?? null;

    const [enableLoading, setEnableLoading] = useState(false);
    const [origin, setOrigin] = useState<Origin | null>(null);
    const [estimate, setEstimate] = useState<Estimate | null>(null);
    const [scheduleOpen, setScheduleOpen] = useState(false);

    // Live countdown ticker for any pending scheduled trigger
    const [now, setNow] = useState(() => Date.now());
    useEffect(() => {
        if (!pendingTrigger) return;
        const id = setInterval(() => setNow(Date.now()), 1000);
        return () => clearInterval(id);
    }, [pendingTrigger]);

    const remainingMs = pendingTrigger
        ? new Date(pendingTrigger.scheduled_at).getTime() - now
        : 0;

    // When the timer hits zero, reload after a beat so the queue worker has
    // time to fire the job + update the server-side state.
    useEffect(() => {
        if (!pendingTrigger) return;
        if (remainingMs > 0) return;
        const t = setTimeout(() => router.reload(), 3000);
        return () => clearTimeout(t);
    }, [pendingTrigger, remainingMs > 0]); // eslint-disable-line react-hooks/exhaustive-deps

    // No perimeter — guide user to set one up
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

    const handleCancel = async () => {
        try {
            await axios.delete(`/geo-fences/${geofence.id}/scheduled-trigger`);
            router.reload();
        } catch {
            // silent — user will see no state change and can retry
        }
    };

    const handleArm = async () => {
        if (!navigator.geolocation) return;
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
            // surface a flash later
        } finally {
            setEnableLoading(false);
        }
    };

    const handleScheduleConfirm = async (minutes: number) => {
        if (!origin) return;
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
            // silent — user can retry
        } finally {
            setEnableLoading(false);
        }
    };

    // Pending trigger active: show countdown + cancel
    if (pendingTrigger) {
        return (
            <>
                <div className="flex items-center justify-between border-t border-wolf-card-border px-4 py-3.5">
                    <div className="flex items-center gap-2.5">
                        <span
                            className="inline-block h-2 w-2 rounded-full"
                            style={{
                                backgroundColor: '#22c55e',
                                boxShadow: '0 0 12px #22c55e',
                            }}
                        />
                        <span className="text-[13px] text-slate-300">
                            Opens in{' '}
                            <strong className="tabular-nums text-white">
                                {formatRemaining(remainingMs)}
                            </strong>
                        </span>
                    </div>
                    <button
                        onClick={handleCancel}
                        className="rounded-wolf-pill border border-amber-400/40 bg-amber-500/20 px-4 py-2 text-[12.5px] font-semibold text-amber-200 hover:bg-amber-500/25"
                    >
                        Cancel
                    </button>
                </div>
            </>
        );
    }

    // Disarmed — let user schedule from here
    return (
        <>
            <div className="flex items-center justify-between border-t border-wolf-card-border px-4 py-3.5">
                <div className="flex items-center gap-2.5">
                    <span
                        className="inline-block h-2 w-2 rounded-full"
                        style={{ backgroundColor: '#475569' }}
                    />
                    <span className="text-[13px] text-slate-300">
                        Geofence{' '}
                        <strong className="text-white">disarmed</strong>
                    </span>
                </div>
                <button
                    onClick={handleArm}
                    disabled={enableLoading}
                    className="rounded-wolf-pill border border-wolf-active-border bg-wolf-active px-4 py-2 text-[12.5px] font-semibold text-indigo-200 disabled:opacity-50"
                >
                    {enableLoading ? 'Working…' : 'Schedule'}
                </button>
            </div>

            {scheduleOpen && estimate && (
                <ScheduleModal
                    distanceMiles={estimate.distance_miles}
                    estimatedMinutes={estimate.estimated_minutes}
                    onConfirm={handleScheduleConfirm}
                    onClose={() => setScheduleOpen(false)}
                />
            )}
        </>
    );
}
