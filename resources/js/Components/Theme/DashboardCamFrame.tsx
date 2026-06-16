interface Device {
    id: number;
    type: 'esp32_cam' | 'esp8266';
    is_online: boolean;
}

interface Props {
    devices: Device[];
}

export function DashboardCamFrame({ devices }: Props) {
    const cam = devices.find((d) => d.type === 'esp32_cam' && d.is_online);

    if (cam) {
        return (
            <div className="flex h-full w-full items-center justify-center bg-black">
                <div className="text-xs uppercase tracking-[2px] text-slate-500">
                    Live stream slot (StreamView)
                </div>
            </div>
        );
    }

    return (
        <div
            className="flex h-full w-full items-center justify-center"
            style={{
                background:
                    'radial-gradient(ellipse at 20% 30%, rgba(56,189,248,0.30) 0%, transparent 60%),' +
                    'radial-gradient(ellipse at 80% 70%, rgba(239,68,68,0.40) 0%, transparent 60%),' +
                    '#04060f',
            }}
        >
            <div className="text-[11px] uppercase tracking-[2px] text-slate-500">
                Area under construction.
            </div>
        </div>
    );
}
