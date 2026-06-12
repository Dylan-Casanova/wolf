import { useState, useEffect, useCallback, useRef } from 'react';
import axios from 'axios';

type GarageState = 'idle' | 'triggering' | 'triggered' | 'error';

interface GarageButtonProps {
    deviceId: number;
    onTriggerStart?: () => void;
    onTriggerComplete?: () => void;
}

export default function GarageButton({ deviceId, onTriggerStart, onTriggerComplete }: GarageButtonProps) {
    const [state, setState] = useState<GarageState>('idle');
    const timeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    useEffect(() => {
        if (!deviceId) return;

        const channel = window.Echo?.private(`device.${deviceId}`);

        channel?.listen('.ServoTriggered', () => {
            // Clear the 10-second ack timeout so it doesn't fire after success
            if (timeoutRef.current) {
                clearTimeout(timeoutRef.current);
                timeoutRef.current = null;
            }

            setState('triggered');
            onTriggerComplete?.();

            setTimeout(() => setState('idle'), 3000);
        });

        return () => {
            window.Echo?.leave(`device.${deviceId}`);
        };
    }, [deviceId, onTriggerComplete]);

    const trigger = useCallback(async () => {
        if (state === 'triggering') return;

        setState('triggering');
        onTriggerStart?.();

        try {
            await axios.post('/garage/trigger', { device_id: deviceId });
        } catch (error) {
            setState('error');

            setTimeout(() => {
                setState('idle');
            }, 3000);
        }

        // 10-second timeout for ack — cleared by the useEffect Echo listener above
        timeoutRef.current = setTimeout(() => {
            timeoutRef.current = null;
            setState('error');
            setTimeout(() => setState('idle'), 3000);
        }, 10000);
    }, [deviceId, state, onTriggerStart]);

    const label: Record<GarageState, string> = {
        idle: 'Open / Close Garage',
        triggering: 'Triggering garage...',
        triggered: 'Garage triggered ✓',
        error: 'Failed to trigger — try again',
    };

    const colors: Record<GarageState, string> = {
        idle: 'bg-blue-600 hover:bg-blue-700 text-white',
        triggering: 'bg-yellow-500 text-white cursor-wait',
        triggered: 'bg-green-600 text-white',
        error: 'bg-red-600 text-white',
    };

    return (
        <div className="w-full flex flex-col gap-3">
            <button
                onClick={trigger}
                disabled={state === 'triggering'}
                className={`w-full px-6 py-3 rounded-lg font-semibold transition-colors ${colors[state]} disabled:opacity-75`}
            >
                {label[state]}
            </button>
        </div>
    );
}
