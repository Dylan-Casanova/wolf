import GarageButton from '@/Components/GarageButton';
import StreamView, { StreamViewHandle } from '@/Components/StreamView';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { useRef, useState } from 'react';

interface DeviceInfo {
    id: number;
    name: string;
    device_id: string;
    type: 'esp32_cam' | 'esp8266';
    is_online: boolean;
}

interface DashboardProps {
    devices: DeviceInfo[];
}

export default function Dashboard({ devices }: DashboardProps) {
    const [selectedIndex, setSelectedIndex] = useState(0);
    const streamRef = useRef<StreamViewHandle>(null);
    const wasStreamingRef = useRef(false);

    const device = devices[selectedIndex] ?? null;
    const hasCamera = device?.type === 'esp32_cam';

    const handleTriggerStart = () => {
        if (hasCamera && streamRef.current?.isStreaming()) {
            wasStreamingRef.current = true;
            streamRef.current.stopStream();
        }
    };

    const handleTriggerComplete = () => {
        if (hasCamera && wasStreamingRef.current && streamRef.current) {
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
                        <>
                            {devices.length > 1 && (
                                <div className="mb-4">
                                    <select
                                        value={selectedIndex}
                                        onChange={(e) =>
                                            setSelectedIndex(
                                                Number(e.target.value),
                                            )
                                        }
                                        className="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    >
                                        {devices.map((d, i) => (
                                            <option key={d.id} value={i}>
                                                {d.name} ({d.device_id}){' '}
                                                {d.is_online ? '' : '— offline'}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                            )}
                            <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                                <div className="flex flex-col items-center gap-4 p-6 text-gray-900">
                                    {hasCamera && (
                                        <StreamView ref={streamRef} />
                                    )}
                                    {device && (
                                        <GarageButton
                                            deviceId={device.id}
                                            onTriggerStart={handleTriggerStart}
                                            onTriggerComplete={
                                                handleTriggerComplete
                                            }
                                        />
                                    )}
                                </div>
                            </div>
                        </>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
