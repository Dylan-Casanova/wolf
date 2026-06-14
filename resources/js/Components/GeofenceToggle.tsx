import ScheduleModal from '@/Components/ScheduleModal';
import { Geofence, PageProps } from '@/types';
import { router, usePage } from '@inertiajs/react';
import axios from 'axios';
import { useEffect, useState } from 'react';

interface GeofenceToggleProps {
    geofence: Geofence;
}

export default function GeofenceToggle({ geofence }: GeofenceToggleProps) {
    const { server_now } = usePage<PageProps>().props;

    // Compute the client/server clock offset ONCE on mount.
    // Used to render an accurate countdown even if the user's clock is skewed.
    const [serverOffsetMs] = useState(() =>
        server_now ? new Date(server_now).getTime() - Date.now() : 0,
    );

    const [loading, setLoading] = useState(false);
    const [modalOpen, setModalOpen] = useState(false);
    const [estimate, setEstimate] = useState<{
        distance_miles: number;
        estimated_minutes: number;
    } | null>(null);
    const [origin, setOrigin] = useState<{ lat: number; lng: number } | null>(
        null,
    );
    const [error, setError] = useState<string | null>(null);
    const [countdownText, setCountdownText] = useState<string>('');

    useEffect(() => {
        const pending = geofence.pending_scheduled_trigger;
        if (!pending) {
            setCountdownText('');
            return;
        }
        const fireAt = new Date(pending.scheduled_at).getTime();
        let intervalId: ReturnType<typeof setInterval> | null = null;
        let reloadTimer: ReturnType<typeof setTimeout> | null = null;

        const tick = () => {
            const ms = fireAt - (Date.now() + serverOffsetMs);
            if (ms <= 0) {
                setCountdownText('Triggering...');
                // Stop counting; give the queue worker a moment, then
                // reload so the server-side trigger result propagates.
                if (intervalId) {
                    clearInterval(intervalId);
                    intervalId = null;
                }
                if (!reloadTimer) {
                    reloadTimer = setTimeout(() => router.reload(), 3000);
                }
                return;
            }
            const mins = Math.floor(ms / 60000);
            const secs = Math.floor((ms % 60000) / 1000);
            setCountdownText(`${mins}:${secs.toString().padStart(2, '0')}`);
        };
        tick();
        intervalId = setInterval(tick, 1000);
        return () => {
            if (intervalId) clearInterval(intervalId);
            if (reloadTimer) clearTimeout(reloadTimer);
        };
    }, [geofence.pending_scheduled_trigger, serverOffsetMs]);

    const getCurrentPosition = (): Promise<GeolocationPosition> => {
        return new Promise((resolve, reject) => {
            navigator.geolocation.getCurrentPosition(resolve, reject, {
                enableHighAccuracy: true,
                timeout: 10000,
            });
        });
    };

    const handleEnable = async () => {
        setError(null);
        if (!navigator.geolocation) {
            setError('Your browser does not support location services.');
            return;
        }
        setLoading(true);
        try {
            const pos = await getCurrentPosition();
            const lat = pos.coords.latitude;
            const lng = pos.coords.longitude;
            setOrigin({ lat, lng });
            const response = await axios.post(
                `/geo-fences/${geofence.id}/estimate`,
                { lat, lng },
            );
            setEstimate(response.data);
            setModalOpen(true);
        } catch {
            setError(
                'Failed to read your location. Please allow access and try again.',
            );
        } finally {
            setLoading(false);
        }
    };

    const handleConfirm = async (minutes: number) => {
        if (!origin) return;
        setLoading(true);
        try {
            await axios.post(`/geo-fences/${geofence.id}/schedule-trigger`, {
                minutes,
                origin_lat: origin.lat,
                origin_lng: origin.lng,
            });
            setModalOpen(false);
            router.reload();
        } catch {
            setError('Failed to schedule trigger.');
        } finally {
            setLoading(false);
        }
    };

    const handleCancelScheduled = async () => {
        setLoading(true);
        try {
            await axios.delete(`/geo-fences/${geofence.id}/scheduled-trigger`);
            router.reload();
        } catch {
            setError('Failed to cancel.');
        } finally {
            setLoading(false);
        }
    };

    const isArmed = geofence.is_active && !!geofence.pending_scheduled_trigger;

    return (
        <div className="flex flex-col gap-2">
            <button
                onClick={isArmed ? handleCancelScheduled : handleEnable}
                disabled={loading}
                className={`inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold transition-colors ${
                    isArmed
                        ? 'bg-green-600 text-white hover:bg-green-700'
                        : 'bg-gray-200 text-gray-700 hover:bg-gray-300'
                } disabled:opacity-50`}
            >
                <span
                    className={`h-2.5 w-2.5 rounded-full ${
                        isArmed ? 'animate-pulse bg-white' : 'bg-gray-400'
                    }`}
                />
                {loading
                    ? 'Working...'
                    : isArmed
                      ? countdownText === 'Triggering...'
                          ? 'Triggering...'
                          : `Opens in ${countdownText} (tap to cancel)`
                      : 'Enable Geofence Tracking'}
            </button>
            {error && <p className="text-xs text-red-500">{error}</p>}
            {modalOpen && estimate && (
                <ScheduleModal
                    distanceMiles={estimate.distance_miles}
                    estimatedMinutes={estimate.estimated_minutes}
                    onConfirm={handleConfirm}
                    onClose={() => setModalOpen(false)}
                />
            )}
        </div>
    );
}
