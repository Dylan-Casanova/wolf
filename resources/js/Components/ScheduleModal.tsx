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

const clamp = (n: number) =>
    Math.max(MIN_MINUTES, Math.min(MAX_MINUTES, n));

export default function ScheduleModal({
    distanceMiles,
    estimatedMinutes,
    onConfirm,
    onClose,
}: ScheduleModalProps) {
    const [minutes, setMinutes] = useState(estimatedMinutes);

    if (typeof document === 'undefined') return null;

    // Portal to document.body so the modal escapes any parent stacking context
    // (Leaflet's map panes go up to z-index 700; this needs to render above).
    return createPortal(
        <div className="fixed inset-0 z-[1000] flex items-center justify-center bg-black/50 p-4">
            <div className="w-full max-w-md rounded-lg bg-white p-6 shadow-xl">
                <h3 className="text-lg font-semibold text-gray-900">
                    Web mode: time-based trigger
                </h3>
                <p className="mt-2 text-sm text-gray-600">
                    The app uses your live location. The web uses a timer.
                </p>

                <div className="mt-4 rounded-md bg-gray-50 p-3 text-sm">
                    <div className="flex items-center justify-between">
                        <span className="text-gray-600">Estimated arrival</span>
                        <span className="font-semibold text-gray-900">
                            {estimatedMinutes} min
                        </span>
                    </div>
                    <div className="mt-1 flex items-center justify-between">
                        <span className="text-gray-600">Distance</span>
                        <span className="text-gray-900">
                            {distanceMiles.toFixed(1)} mi
                        </span>
                    </div>
                </div>

                <div className="mt-4">
                    <span className="block text-sm font-medium text-gray-700">
                        Open garage in (minutes)
                    </span>

                    <div className="mt-2 flex flex-wrap gap-2">
                        {PRESETS.map((p) => (
                            <button
                                key={p}
                                type="button"
                                onClick={() => setMinutes(p)}
                                className={`rounded-md px-3 py-1.5 text-sm font-medium transition-colors ${
                                    minutes === p
                                        ? 'bg-indigo-600 text-white'
                                        : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
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
                            className="flex h-12 w-12 items-center justify-center rounded-md bg-gray-100 text-2xl font-medium text-gray-700 hover:bg-gray-200 disabled:opacity-40"
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
                                // Snap empty/invalid input back to a valid value
                                const v = parseInt(e.target.value, 10);
                                setMinutes(isNaN(v) ? MIN_MINUTES : clamp(v));
                            }}
                            className="block h-12 flex-1 rounded-md border-gray-300 text-center text-xl font-semibold tabular-nums shadow-sm focus:border-indigo-500 focus:ring-indigo-500 [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none"
                        />
                        <button
                            type="button"
                            onClick={() => setMinutes(clamp(minutes + STEP))}
                            disabled={minutes >= MAX_MINUTES}
                            aria-label="Increase by 1"
                            className="flex h-12 w-12 items-center justify-center rounded-md bg-gray-100 text-2xl font-medium text-gray-700 hover:bg-gray-200 disabled:opacity-40"
                        >
                            +
                        </button>
                    </div>

                    <span className="mt-2 block text-xs text-gray-500">
                        Max {MAX_MINUTES} minutes (3 hours). Tap a preset or
                        type a custom value.
                    </span>
                </div>

                <p className="mt-4 text-xs text-gray-500">
                    Garage opens when the timer ends. Cancel anytime.
                </p>

                <div className="mt-6 flex justify-end gap-3">
                    <button
                        onClick={onClose}
                        className="rounded-md px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-100"
                    >
                        Cancel
                    </button>
                    <button
                        onClick={() => onConfirm(minutes)}
                        className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700"
                    >
                        Start Timer
                    </button>
                </div>
            </div>
        </div>,
        document.body,
    );
}
