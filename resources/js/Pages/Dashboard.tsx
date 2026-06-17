import { DashboardCamFrame } from '@/Components/Theme/DashboardCamFrame';
import { GarageOpenButton } from '@/Components/Theme/GarageOpenButton';
import { GeofenceMiniControl } from '@/Components/Theme/GeofenceMiniControl';
import { StatusStrip } from '@/Components/Theme/StatusStrip';
import { TriggerPanel } from '@/Components/Theme/TriggerPanel';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import type { Geofence } from '@/types';
import { Head } from '@inertiajs/react';

interface DeviceInfo {
    id: number;
    name: string;
    device_id: string;
    type: 'esp32_cam' | 'esp8266';
    is_online: boolean;
}

interface Props {
    devices: DeviceInfo[];
    geofence: Geofence | null;
}

export default function Dashboard({ devices, geofence }: Props) {
    const primaryServo = devices.find((d) => d.type === 'esp8266');

    const trigger = primaryServo ? (
        <TriggerPanel label="Trigger Button">
            <GarageOpenButton deviceId={primaryServo.id} />
        </TriggerPanel>
    ) : null;

    return (
        <AuthenticatedLayout trigger={trigger}>
            <Head title="Dashboard" />
            <div className="overflow-hidden rounded-wolf-card border border-wolf-card-border bg-black/60">
                <div className="flex aspect-video w-full overflow-hidden sm:aspect-[16/8] lg:aspect-auto lg:h-[260px]">
                    <DashboardCamFrame devices={devices} />
                </div>
                <GeofenceMiniControl geofence={geofence} />
            </div>
            {primaryServo && (
                <StatusStrip
                    name={primaryServo.name}
                    online={primaryServo.is_online}
                    meta="ESP8266"
                />
            )}
        </AuthenticatedLayout>
    );
}
