import AddressSearch from '@/Components/AddressSearch';
import GeofenceMap from '@/Components/GeofenceMap';
import ScheduleModal from '@/Components/ScheduleModal';
import { DeleteGeofenceModal } from '@/Components/Theme/DeleteGeofenceModal';
import { GeofenceActionRow } from '@/Components/Theme/GeofenceActionRow';
import { StatusStrip } from '@/Components/Theme/StatusStrip';
import { TriggerPanel } from '@/Components/Theme/TriggerPanel';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { withBusy } from '@/Stores/busyStore';
import type { Geofence } from '@/types';
import { Head, router } from '@inertiajs/react';
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
    const [deleteOpen, setDeleteOpen] = useState(false);

    // Time-based trigger flow (web-only). Native app uses /toggle directly
    // for live-location triggering; web schedules a delayed servo trigger.
    const [origin, setOrigin] = useState<Origin | null>(null);
    const [estimate, setEstimate] = useState<Estimate | null>(null);
    const [scheduleOpen, setScheduleOpen] = useState(false);
    const [enableLoading, setEnableLoading] = useState(false);
    const [enableError, setEnableError] = useState<string | null>(null);

    // Show the user's current location on the map as a blue dot. Fetched once
    // on mount; silently does nothing if permission is denied or unavailable.
    const [userPosition, setUserPosition] = useState<[number, number] | null>(
        null,
    );
    useEffect(() => {
        if (!navigator.geolocation) return;
        navigator.geolocation.getCurrentPosition(
            (pos) => {
                setUserPosition([pos.coords.latitude, pos.coords.longitude]);
            },
            () => {
                /* permission denied or timeout — no marker */
            },
            { enableHighAccuracy: false, timeout: 5000 },
        );
    }, []);

    const pendingTrigger = geofence?.pending_scheduled_trigger ?? null;

    // Live countdown for any pending scheduled trigger
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
    // job has time to run and update the geofence's pending_scheduled_trigger
    // to null (status -> 'fired').
    useEffect(() => {
        if (!pendingTrigger) return;
        if (remainingMs > 0) return;
        const t = setTimeout(() => router.reload(), 3000);
        return () => clearTimeout(t);
    }, [pendingTrigger, remainingMs > 0]); // eslint-disable-line react-hooks/exhaustive-deps

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

    const handleDelete = () => setDeleteOpen(true);

    const confirmDelete = async () => {
        if (!geofence) return;
        setDeleteOpen(false);
        await withBusy(() => axios.delete(`/geo-fences/${geofence.id}`));
        setShowMap(false);
        setBounds(null);
        setCenter(null);
        setAddressPoint(null);
        router.reload();
    };

    /**
     * When a pending trigger exists, the enable button becomes a cancel
     * action. Otherwise it kicks off the schedule flow: get GPS once, ask
     * the backend to estimate distance and travel time, then open the modal.
     */
    const handleEnable = async () => {
        if (!geofence) return;

        if (pendingTrigger) {
            // Cancel the scheduled trigger
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

        // Start the schedule flow
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

    const handleScheduleConfirm = async (minutes: number) => {
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

    const enableLabel = pendingTrigger
        ? `CANCEL · ${formatRemaining(remainingMs)}`
        : 'ENABLE TRACKING';

    const trigger = geofence ? (
        <TriggerPanel label="Geofence Actions">
            <GeofenceActionRow
                onEnable={handleEnable}
                onUpdate={handleSave}
                onDelete={handleDelete}
                enableLabel={enableLabel}
                enableLoading={enableLoading}
                enableVariant={pendingTrigger ? 'cancel' : 'primary'}
                updateDisabled={!bounds}
                updating={saving}
            />
        </TriggerPanel>
    ) : bounds ? (
        <TriggerPanel label="Geofence Actions">
            <button
                onClick={handleSave}
                disabled={saving}
                className="rounded-wolf-pill bg-gradient-to-br from-indigo-500 to-indigo-700 px-3.5 py-2 text-[11px] font-bold tracking-wide text-white shadow-[0_8px_24px_rgba(99,102,241,0.4)] disabled:opacity-50 sm:rounded-wolf-card sm:px-9 sm:py-3.5 sm:text-[15px]"
            >
                {saving ? 'CREATING…' : 'CREATE PERIMETER'}
            </button>
        </TriggerPanel>
    ) : null;

    const statusMeta = pendingTrigger
        ? `Opens in ${formatRemaining(remainingMs)}`
        : 'Disarmed';

    return (
        <AuthenticatedLayout trigger={trigger}>
            <Head title="Geofence" />
            {!showMap && !geofence ? (
                <div className="flex flex-col items-center gap-6 rounded-wolf-card border border-wolf-card-border bg-black/40 px-4 py-12">
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
                            userPosition={userPosition}
                            addressPoint={addressPoint}
                            onBoundsChange={setBounds}
                        />
                    </div>
                    {geofence && (
                        <StatusStrip
                            name="Home Perimeter"
                            online={!!pendingTrigger}
                            meta={statusMeta}
                        />
                    )}
                    {enableError && (
                        <p className="text-xs text-red-400">{enableError}</p>
                    )}
                </div>
            )}

            {scheduleOpen && estimate && (
                <ScheduleModal
                    distanceMiles={estimate.distance_miles}
                    estimatedMinutes={estimate.estimated_minutes}
                    onConfirm={handleScheduleConfirm}
                    onClose={() => setScheduleOpen(false)}
                />
            )}

            <DeleteGeofenceModal
                open={deleteOpen}
                onClose={() => setDeleteOpen(false)}
                onConfirm={confirmDelete}
            />
        </AuthenticatedLayout>
    );
}
