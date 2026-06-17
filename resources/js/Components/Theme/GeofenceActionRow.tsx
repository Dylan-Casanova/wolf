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
        <div className="flex items-center justify-center gap-2 sm:gap-3">
            <button
                onClick={onEnable}
                disabled={enableLoading}
                className={`rounded-wolf-pill px-3.5 py-2 text-[11px] font-bold tracking-wide text-white disabled:opacity-60 sm:rounded-wolf-card sm:px-9 sm:py-3.5 sm:text-[15px] ${enableClasses}`}
            >
                {enableLoading ? 'WORKING…' : enableLabel}
            </button>
            <button
                onClick={onUpdate}
                disabled={updateDisabled || updating}
                className="rounded-wolf-pill border-wolf-glass-border border bg-white/[0.07] px-3.5 py-2 text-[11px] font-bold tracking-wide text-white disabled:opacity-40 sm:rounded-wolf-card sm:px-9 sm:py-3.5 sm:text-[15px]"
            >
                {updating ? 'UPDATING…' : 'UPDATE'}
            </button>
            <button
                onClick={onDelete}
                aria-label="Delete geofence"
                className="rounded-wolf-pill border border-red-500/30 bg-transparent px-3.5 py-2 text-[11px] font-bold tracking-wide text-red-300 transition-colors hover:bg-red-500/10 hover:text-red-200 sm:rounded-wolf-card sm:px-9 sm:py-3.5 sm:text-[15px]"
            >
                DELETE
            </button>
        </div>
    );
}
