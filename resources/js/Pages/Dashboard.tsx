import CaptureButton from '@/Components/CaptureButton';
import MediaDisplay from '@/Components/MediaDisplay';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { CaptureData, PageProps } from '@/types';
import { Head, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

export default function Dashboard() {
    const { props } = usePage<PageProps>();
    const [capture, setCapture] = useState<CaptureData | null>(null);

    // Populate from Inertia flash on redirect (mock / sync path)
    useEffect(() => {
        if (props.flash?.capture) {
            setCapture(props.flash.capture);
        }
    }, [props.flash?.capture]);

    // Listen for async CaptureReady broadcast via Reverb WebSocket (MQTT / real device path)
    useEffect(() => {
        const userId = props.auth.user.id;
        if (!window.Echo) return;

        const channel = window.Echo.private(`user.${userId}`);
        channel.listen('.CaptureReady', (data: CaptureData) => {
            setCapture(data);
        });

        return () => {
            channel.stopListening('.CaptureReady');
        };
    }, [props.auth.user.id]);

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Dashboard
                </h2>
            }
        >
            <Head title="Dashboard" />

            <div className="py-12">
                <div className="mx-auto max-w-2xl px-4 sm:px-6 lg:px-8">
                    <div className="flex flex-col items-center gap-10">
                        <CaptureButton onCapture={setCapture} />
                        <MediaDisplay capture={capture} />
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
