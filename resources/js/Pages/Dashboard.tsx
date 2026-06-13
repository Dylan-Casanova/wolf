import GarageButton from '@/Components/GarageButton';
import GeofenceToggle from '@/Components/GeofenceToggle';
import StreamView, { StreamViewHandle } from '@/Components/StreamView';
import useGeolocation from '@/hooks/useGeolocation';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Geofence } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { useRef } from 'react';

interface DeviceInfo {
    id: number;
    name: string;
    device_id: string;
    type: 'esp32_cam' | 'esp8266';
    is_online: boolean;
}

interface DashboardProps {
    devices: DeviceInfo[];
    geofence: Geofence | null;
}

export default function Dashboard({ devices, geofence }: DashboardProps) {
    const streamRef = useRef<StreamViewHandle>(null);
    const wasStreamingRef = useRef(false);

    const { tracking, startTracking, stopTracking } = useGeolocation({
        geofenceId: geofence?.id ?? 0,
        isActive: geofence?.is_active ?? false,
        onTriggered: () => router.reload(),
    });

    // Sort: esp32_cam first, esp8266 last
    const sorted = [...devices].sort((a, b) => {
        if (a.type === 'esp32_cam' && b.type !== 'esp32_cam') return -1;
        if (a.type !== 'esp32_cam' && b.type === 'esp32_cam') return 1;
        return 0;
    });

    const esp32 = sorted.find((d) => d.type === 'esp32_cam');

    const handleTriggerStart = () => {
        if (esp32 && streamRef.current?.isStreaming()) {
            wasStreamingRef.current = true;
            streamRef.current.stopStream();
        }
    };

    const handleTriggerComplete = () => {
        if (esp32 && wasStreamingRef.current && streamRef.current) {
            wasStreamingRef.current = false;
            streamRef.current.startStream();
        }
    };

    return (
        <AuthenticatedLayout>
            <Head title="Dashboard" />
            <div className="py-12">
                <div className="mx-auto max-w-7xl sm:px-6 lg:px-8">
                    {devices.length === 0 ? (
                        <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                            <div className="p-6 text-center text-gray-500">
                                <p className="mb-4">
                                    No devices linked to your account.
                                </p>
                                <Link
                                    href="/devices/claim"
                                    className="inline-flex items-center rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700"
                                >
                                    Claim a Device
                                </Link>
                            </div>
                        </div>
                    ) : (
                        <div className="flex flex-col gap-6">
                            {sorted.map((device) => (
                                <div
                                    key={device.id}
                                    className="overflow-hidden bg-white shadow-sm sm:rounded-lg"
                                >
                                    <div className="border-b border-gray-100 px-6 py-3">
                                        <div className="flex items-center justify-between">
                                            <h3 className="text-sm font-semibold text-gray-700">
                                                {device.name}
                                                <span className="ml-2 text-xs font-normal text-gray-400">
                                                    {device.device_id}
                                                </span>
                                            </h3>
                                            <span
                                                className={`inline-flex items-center gap-1.5 text-xs font-medium ${
                                                    device.is_online
                                                        ? 'text-green-600'
                                                        : 'text-gray-400'
                                                }`}
                                            >
                                                <span
                                                    className={`h-2 w-2 rounded-full ${
                                                        device.is_online
                                                            ? 'bg-green-500'
                                                            : 'bg-gray-300'
                                                    }`}
                                                />
                                                {device.is_online
                                                    ? 'Online'
                                                    : 'Offline'}
                                            </span>
                                        </div>
                                    </div>
                                    <div className="flex flex-col items-center gap-4 p-6 text-gray-900">
                                        {device.type === 'esp32_cam' && (
                                            <StreamView ref={streamRef} />
                                        )}
                                        <GarageButton
                                            deviceId={device.id}
                                            onTriggerStart={handleTriggerStart}
                                            onTriggerComplete={
                                                handleTriggerComplete
                                            }
                                        />
                                    </div>
                                </div>
                            ))}
                            {geofence && (
                                <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                                    <div className="border-b border-gray-100 px-6 py-3">
                                        <h3 className="text-sm font-semibold text-gray-700">
                                            Geofence
                                        </h3>
                                    </div>
                                    <div className="flex items-center justify-between p-6">
                                        <GeofenceToggle
                                            geofenceId={geofence.id}
                                            initialActive={geofence.is_active}
                                            onToggle={(active) => {
                                                if (active) startTracking();
                                                else stopTracking();
                                            }}
                                        />
                                        {tracking && (
                                            <span className="text-xs text-green-600">
                                                Tracking location...
                                            </span>
                                        )}
                                    </div>
                                </div>
                            )}
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
