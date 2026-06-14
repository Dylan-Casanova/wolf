import { useState } from 'react';
import { createPortal } from 'react-dom';

interface ScheduleModalProps {
    distanceMiles: number;
    estimatedMinutes: number;
    onConfirm: (minutes: number) => void;
    onClose: () => void;
}

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

                <label className="mt-4 block">
                    <span className="text-sm font-medium text-gray-700">
                        Open garage in (minutes)
                    </span>
                    <input
                        type="number"
                        min={1}
                        max={180}
                        value={minutes}
                        onChange={(e) => {
                            const v = parseInt(e.target.value, 10);
                            if (!isNaN(v)) {
                                setMinutes(Math.max(1, Math.min(180, v)));
                            }
                        }}
                        className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                    />
                    <span className="mt-1 block text-xs text-gray-500">
                        Max 180 minutes (3 hours).
                    </span>
                </label>

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
