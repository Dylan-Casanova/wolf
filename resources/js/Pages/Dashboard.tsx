import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import StreamView, { StreamViewHandle } from '@/Components/StreamView';
import GarageButton from '@/Components/GarageButton';
import { Head } from '@inertiajs/react';
import { useRef } from 'react';

interface DashboardProps {
    deviceId: number | null;
    deviceType: 'esp32_cam' | 'esp8266' | null;
}

export default function Dashboard({ deviceId, deviceType }: DashboardProps) {
    const streamRef = useRef<StreamViewHandle>(null);
    const wasStreamingRef = useRef(false);
    const hasCamera = deviceType === 'esp32_cam';

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
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8">
                    <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                        <div className="p-6 text-gray-900 flex flex-col items-center gap-4">
                            {hasCamera && <StreamView ref={streamRef} />}
                            {deviceId && (
                                <GarageButton
                                    deviceId={deviceId}
                                    onTriggerStart={handleTriggerStart}
                                    onTriggerComplete={handleTriggerComplete}
                                />
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
