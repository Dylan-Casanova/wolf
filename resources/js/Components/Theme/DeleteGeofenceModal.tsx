import { useEffect } from 'react';
import { createPortal } from 'react-dom';

interface Props {
    open: boolean;
    onClose: () => void;
    onConfirm: () => void;
}

/**
 * Confirmation modal for the destructive "delete geofence" action. Mirrors
 * the styling of ScheduleModal / DeviceClaimModal so it feels native.
 */
export function DeleteGeofenceModal({ open, onClose, onConfirm }: Props) {
    useEffect(() => {
        if (!open) return;
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') onClose();
        };
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [open, onClose]);

    if (!open) return null;
    if (typeof document === 'undefined') return null;

    return createPortal(
        <div
            className="fixed inset-0 z-[1000] flex items-center justify-center bg-black/70 p-4"
            onClick={onClose}
            role="dialog"
            aria-modal="true"
            aria-labelledby="delete-geofence-title"
        >
            <div
                onClick={(e) => e.stopPropagation()}
                className="w-full max-w-md rounded-wolf-card border border-wolf-glass-border bg-wolf-glass p-6 shadow-[0_30px_80px_rgba(0,0,0,0.7)] backdrop-blur-wolf-panel"
            >
                <div className="flex items-center gap-3">
                    <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-red-500/30 bg-red-500/10 text-red-300">
                        <svg
                            width="18"
                            height="18"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            strokeWidth="1.8"
                            strokeLinecap="round"
                            strokeLinejoin="round"
                        >
                            <path d="M12 9v4M12 17h.01" />
                            <path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
                        </svg>
                    </span>
                    <h3
                        id="delete-geofence-title"
                        className="text-lg font-semibold text-white"
                    >
                        Delete geofence?
                    </h3>
                </div>

                <p className="mt-4 text-sm leading-relaxed text-slate-300">
                    Your home perimeter and any pending scheduled triggers will
                    be removed. This can't be undone.
                </p>

                <div className="mt-6 flex flex-wrap justify-end gap-2 sm:gap-3">
                    <button
                        onClick={onClose}
                        className="rounded-wolf-pill border border-wolf-card-border bg-white/[0.05] px-4 py-2 text-sm font-medium text-slate-200 transition-colors hover:bg-white/10"
                    >
                        Cancel
                    </button>
                    <button
                        onClick={onConfirm}
                        className="rounded-wolf-pill bg-gradient-to-br from-red-500 to-red-700 px-4 py-2 text-sm font-semibold text-white shadow-[0_8px_24px_rgba(239,68,68,0.35)] transition-colors hover:brightness-110"
                    >
                        Delete fence
                    </button>
                </div>
            </div>
        </div>,
        document.body,
    );
}
