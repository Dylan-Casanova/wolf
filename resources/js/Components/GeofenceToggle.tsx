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

    const toggle = async () => {
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
                ? 'Updating...'
                : isActive
                  ? 'Geofencing Active'
                  : 'Geofencing Off'}
        </button>
    );
}
