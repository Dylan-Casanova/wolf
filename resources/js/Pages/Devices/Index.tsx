import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import type { Device } from '@/types';
import { Head, Link } from '@inertiajs/react';

interface Props {
    devices: Device[];
}

/**
 * Render an ISO timestamp in US Central Time. Automatically shows "CST" in
 * winter and "CDT" during daylight saving time — `America/Chicago` handles
 * the switch correctly for any input date.
 */
function formatCentralTime(iso: string | null): string {
    if (!iso) return 'Never';
    const date = new Date(iso);
    if (isNaN(date.getTime())) return '—';
    return new Intl.DateTimeFormat('en-US', {
        timeZone: 'America/Chicago',
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        hour12: true,
        timeZoneName: 'short',
    }).format(date);
}

const GRID_COLS = 'grid-cols-[2fr_1.4fr_0.9fr_0.8fr_1.4fr_auto]';

export default function Index({ devices }: Props) {
    return (
        <AuthenticatedLayout>
            <Head title="Devices" />
            <div className="rounded-wolf-card border border-wolf-card-border bg-black/40 p-4">
                <div
                    className={`mb-3 grid ${GRID_COLS} gap-3 border-b border-wolf-card-border pb-3 text-[10px] uppercase tracking-[2px] text-slate-400`}
                >
                    <div>Name</div>
                    <div>Device ID</div>
                    <div>Type</div>
                    <div>Status</div>
                    <div>Last Seen</div>
                    <div></div>
                </div>
                {devices.length === 0 ? (
                    <div className="py-12 text-center text-sm text-slate-400">
                        No devices in the system yet.
                    </div>
                ) : (
                    <div className="flex flex-col gap-2">
                        {devices.map((device) => (
                            <div
                                key={device.id}
                                className={`grid ${GRID_COLS} items-center gap-3 rounded-wolf-pill border border-wolf-card-border bg-white/[0.03] px-3.5 py-2.5 text-[12.5px] text-slate-300`}
                            >
                                <div className="font-medium text-white">
                                    {device.name}
                                </div>
                                <div className="font-mono text-slate-400">
                                    {device.device_id}
                                </div>
                                <div>{device.type}</div>
                                <div className="flex items-center gap-1.5">
                                    <span
                                        className="inline-block h-1.5 w-1.5 rounded-full"
                                        style={{
                                            backgroundColor: device.is_online
                                                ? '#22c55e'
                                                : '#475569',
                                            boxShadow: device.is_online
                                                ? '0 0 8px #22c55e'
                                                : 'none',
                                        }}
                                    />
                                    <span
                                        className={
                                            device.is_online
                                                ? 'text-green-400'
                                                : 'text-slate-500'
                                        }
                                    >
                                        {device.is_online
                                            ? 'Online'
                                            : 'Offline'}
                                    </span>
                                </div>
                                <div
                                    className={
                                        device.last_seen_at
                                            ? 'text-slate-400'
                                            : 'text-slate-600'
                                    }
                                >
                                    {formatCentralTime(device.last_seen_at)}
                                </div>
                                <Link
                                    href={`/devices/${device.id}/edit`}
                                    className="rounded-wolf-pill border border-wolf-glass-border bg-white/[0.05] px-3 py-1.5 text-[11px] font-medium text-slate-300 hover:bg-white/[0.08]"
                                >
                                    Edit
                                </Link>
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
