import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import StreamView, { StreamViewHandle } from '@/Components/StreamView';
import GarageButton from '@/Components/GarageButton';
import { Head } from '@inertiajs/react';
import { useRef } from 'react';

export default function Dashboard({ deviceId }: { deviceId: number | null }) {
    const streamRef = useRef<StreamViewHandle>(null);
    const wasStreamingRef = useRef(false);

    const handleTriggerStart = () => {
        if (streamRef.current?.isStreaming()) {
            wasStreamingRef.current = true;
            streamRef.current.stopStream();
        }
    };

    const handleTriggerComplete = () => {
        if (wasStreamingRef.current && streamRef.current) {
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
                            <StreamView ref={streamRef} />
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
