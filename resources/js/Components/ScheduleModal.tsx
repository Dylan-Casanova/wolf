import { useState } from 'react';
import { createPortal } from 'react-dom';

interface ScheduleModalProps {
    distanceMiles: number;
    estimatedMinutes: number;
    onConfirm: (minutes: number) => void;
    onClose: () => void;
}

const PRESETS = [15, 30, 60, 90, 120, 180];
const MIN_MINUTES = 1;
const MAX_MINUTES = 180;
const STEP = 1;

const clamp = (n: number) => Math.max(MIN_MINUTES, Math.min(MAX_MINUTES, n));

export default function ScheduleModal({
    distanceMiles,
    estimatedMinutes,
    onConfirm,
    onClose,
}: ScheduleModalProps) {
    const [minutes, setMinutes] = useState(estimatedMinutes);

    if (typeof document === 'undefined') return null;

    return createPortal(
        <div className="fixed inset-0 z-[1000] flex items-center justify-center bg-black/70 p-4">
            <div className="rounded-wolf-card border-wolf-glass-border bg-wolf-glass backdrop-blur-wolf-panel w-full max-w-md border p-6 shadow-[0_30px_80px_rgba(0,0,0,0.7)]">
                <h3 className="text-lg font-semibold text-white">
                    Web mode: time-based trigger
                </h3>
                <p className="mt-2 text-sm text-slate-400">
                    The app uses your live location. The web uses a timer.
                </p>

                <div className="rounded-wolf-pill border-wolf-card-border mt-4 border bg-black/40 p-3 text-sm">
                    <div className="flex items-center justify-between">
                        <span className="text-slate-400">
                            Estimated arrival
                        </span>
                        <span className="font-semibold text-white">
                            {estimatedMinutes} min
                        </span>
                    </div>
                    <div className="mt-1 flex items-center justify-between">
                        <span className="text-slate-400">Distance</span>
                        <span className="text-white">
                            {distanceMiles.toFixed(1)} mi
                        </span>
                    </div>
                </div>

                <div className="mt-4">
                    <span className="block text-sm font-medium text-slate-300">
                        Open garage in (minutes)
                    </span>

                    <div className="mt-2 flex flex-wrap gap-2">
                        {PRESETS.map((p) => (
                            <button
                                key={p}
                                type="button"
                                onClick={() => setMinutes(p)}
                                className={`rounded-wolf-pill px-3 py-1.5 text-sm font-medium transition-colors ${
                                    minutes === p
                                        ? 'bg-gradient-to-br from-indigo-500 to-indigo-700 text-white shadow-[0_4px_12px_rgba(99,102,241,0.4)]'
                                        : 'border-wolf-card-border border bg-white/[0.05] text-slate-300 hover:bg-white/[0.08]'
                                }`}
                            >
                                {p}
                            </button>
                        ))}
                    </div>

                    <div className="mt-3 flex items-center gap-2">
                        <button
                            type="button"
                            onClick={() => setMinutes(clamp(minutes - STEP))}
                            disabled={minutes <= MIN_MINUTES}
                            aria-label="Decrease by 1"
                            className="rounded-wolf-pill border-wolf-card-border flex h-12 w-12 items-center justify-center border bg-white/[0.05] text-2xl font-medium text-white hover:bg-white/[0.08] disabled:opacity-40"
                        >
                            −
                        </button>
                        <input
                            type="text"
                            inputMode="numeric"
                            pattern="[0-9]*"
                            value={minutes}
                            onChange={(e) => {
                                const raw = e.target.value.replace(/\D/g, '');
                                if (raw === '') {
                                    setMinutes(MIN_MINUTES);
                                    return;
                                }
                                const v = parseInt(raw, 10);
                                if (!isNaN(v)) setMinutes(clamp(v));
                            }}
                            onBlur={(e) => {
                                const v = parseInt(e.target.value, 10);
                                setMinutes(isNaN(v) ? MIN_MINUTES : clamp(v));
                            }}
                            className="rounded-wolf-pill border-wolf-glass-border focus:border-wolf-active-border focus:ring-wolf-active-border block h-12 flex-1 border bg-white/5 text-center text-xl font-semibold tabular-nums text-white shadow-none [appearance:textfield] focus:outline-none focus:ring-1 [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none"
                        />
                        <button
                            type="button"
                            onClick={() => setMinutes(clamp(minutes + STEP))}
                            disabled={minutes >= MAX_MINUTES}
                            aria-label="Increase by 1"
                            className="rounded-wolf-pill border-wolf-card-border flex h-12 w-12 items-center justify-center border bg-white/[0.05] text-2xl font-medium text-white hover:bg-white/[0.08] disabled:opacity-40"
                        >
                            +
                        </button>
                    </div>

                    <span className="mt-2 block text-xs text-slate-500">
                        Max {MAX_MINUTES} minutes (3 hours). Tap a preset or
                        type a custom value.
                    </span>
                </div>

                <p className="mt-4 text-xs text-slate-500">
                    Garage opens when the timer ends. Cancel anytime.
                </p>

                <div className="mt-6 flex justify-end gap-3">
                    <button
                        onClick={onClose}
                        className="rounded-wolf-pill px-4 py-2 text-sm font-medium text-slate-300 hover:bg-white/5"
                    >
                        Cancel
                    </button>
                    <button
                        onClick={() => onConfirm(minutes)}
                        className="rounded-wolf-pill bg-gradient-to-br from-indigo-500 to-indigo-700 px-5 py-2 text-sm font-semibold text-white shadow-[0_8px_24px_rgba(99,102,241,0.4)]"
                    >
                        Start Timer
                    </button>
                </div>
            </div>
        </div>,
        document.body,
    );
}
