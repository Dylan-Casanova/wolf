import axios from 'axios';
import { useState } from 'react';

interface GeofenceToggleProps {
    geofenceId: number;
    initialActive: boolean;
    onToggle?: (isActive: boolean) => void;
}

export default function GeofenceToggle({
    geofenceId,
    initialActive,
    onToggle,
}: GeofenceToggleProps) {
    const [isActive, setIsActive] = useState(initialActive);
    const [loading, setLoading] = useState(false);
    const [locationError, setLocationError] = useState<string | null>(null);

    const checkLocationPermission = async (): Promise<boolean> => {
        if (!navigator.geolocation) {
            setLocationError(
                'Your browser does not support location services.',
            );
            return false;
        }

        if (navigator.permissions) {
            const status = await navigator.permissions.query({
                name: 'geolocation',
            });
            if (status.state === 'denied') {
                setLocationError(
                    'Location access is blocked. Enable it in your browser settings (click the lock icon in the address bar).',
                );
                return false;
            }
        }

        return new Promise((resolve) => {
            navigator.geolocation.getCurrentPosition(
                () => {
                    setLocationError(null);
                    resolve(true);
                },
                () => {
                    setLocationError(
                        'Location access denied. Please allow location access and try again.',
                    );
                    resolve(false);
                },
                { timeout: 10000 },
            );
        });
    };

    const toggle = async () => {
        setLocationError(null);

        if (!isActive) {
            setLoading(true);
            const allowed = await checkLocationPermission();
            if (!allowed) {
                setLoading(false);
                return;
            }
        }

        setLoading(true);
        try {
            const response = await axios.post(
                `/geo-fences/${geofenceId}/toggle`,
            );
            const newState = response.data.is_active;
            setIsActive(newState);
            onToggle?.(newState);
        } catch {
            // revert on failure
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className="flex flex-col gap-2">
            <button
                onClick={toggle}
                disabled={loading}
                className={`inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold transition-colors ${
                    isActive
                        ? 'bg-green-600 text-white hover:bg-green-700'
                        : 'bg-gray-200 text-gray-700 hover:bg-gray-300'
                } disabled:opacity-50`}
            >
                <span
                    className={`h-2.5 w-2.5 rounded-full ${
                        isActive ? 'animate-pulse bg-white' : 'bg-gray-400'
                    }`}
                />
                {loading
                    ? 'Checking location...'
                    : isActive
                      ? 'Geofencing Active'
                      : 'Geofencing Off'}
            </button>
            {locationError && (
                <p className="text-xs text-red-500">{locationError}</p>
            )}
        </div>
    );
}
