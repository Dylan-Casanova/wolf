interface Props {
    name: string;
    online?: boolean;
    meta?: string;
}

export function StatusStrip({ name, online, meta }: Props) {
    return (
        <div className="flex items-center justify-between px-1.5 text-[11px] text-slate-400">
            <div>
                <div className="text-[13px] font-semibold text-white">
                    {name}
                </div>
                <div className="mt-0.5">
                    {online !== undefined && (
                        <span
                            className="mr-1.5 inline-block h-1.5 w-1.5 rounded-full"
                            style={{
                                backgroundColor: online ? '#22c55e' : '#475569',
                                boxShadow: online ? '0 0 8px #22c55e' : 'none',
                            }}
                        />
                    )}
                    {online === undefined
                        ? meta
                        : `${online ? 'Online' : 'Offline'}${meta ? ` · ${meta}` : ''}`}
                </div>
            </div>
        </div>
    );
}
