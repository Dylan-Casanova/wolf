interface Props {
    onEnable: () => void;
    onUpdate: () => void;
    onDelete: () => void;
    enableLabel?: string;
    enableLoading?: boolean;
    /** primary = indigo (Enable). cancel = amber (Cancel a scheduled timer). */
    enableVariant?: 'primary' | 'cancel';
    updateDisabled?: boolean;
    updating?: boolean;
}

export function GeofenceActionRow({
    onEnable,
    onUpdate,
    onDelete,
    enableLabel = 'ENABLE TRACKING',
    enableLoading,
    enableVariant = 'primary',
    updateDisabled,
    updating,
}: Props) {
    const enableClasses =
        enableVariant === 'cancel'
            ? 'bg-gradient-to-br from-amber-500 to-orange-600 shadow-[0_8px_24px_rgba(245,158,11,0.4),inset_0_1px_0_rgba(255,255,255,0.18)]'
            : 'bg-gradient-to-br from-indigo-500 to-indigo-700 shadow-[0_8px_24px_rgba(99,102,241,0.4),inset_0_1px_0_rgba(255,255,255,0.18)]';

    return (
        <div className="flex items-center justify-center gap-3">
            <button
                onClick={onEnable}
                disabled={enableLoading}
                className={`rounded-wolf-card px-9 py-3.5 text-[15px] font-bold tracking-wide text-white disabled:opacity-60 ${enableClasses}`}
            >
                {enableLoading ? 'WORKING…' : enableLabel}
            </button>
            <button
                onClick={onUpdate}
                disabled={updateDisabled || updating}
                className="rounded-wolf-card border-wolf-glass-border border bg-white/[0.07] px-9 py-3.5 text-[15px] font-bold tracking-wide text-white disabled:opacity-40"
            >
                {updating ? 'UPDATING…' : 'UPDATE'}
            </button>
            <button
                onClick={onDelete}
                className="rounded-wolf-card bg-gradient-to-br from-red-500 to-red-700 px-9 py-3.5 text-[15px] font-bold tracking-wide text-white shadow-[0_8px_24px_rgba(239,68,68,0.35)]"
            >
                DELETE
            </button>
        </div>
    );
}
