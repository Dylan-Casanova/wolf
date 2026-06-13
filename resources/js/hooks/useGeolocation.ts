import axios from 'axios';
import { useCallback, useEffect, useRef, useState } from 'react';

const MILES_TO_METERS = 1609.34;
const CLOSE_THRESHOLD = 2 * MILES_TO_METERS;
const FAR_INTERVAL = 30000;
const CLOSE_INTERVAL = 10000;

interface UseGeolocationOptions {
    geofenceId: number;
    isActive: boolean;
    onTriggered: () => void;
}

export default function useGeolocation({
    geofenceId,
    isActive,
    onTriggered,
}: UseGeolocationOptions) {
    const [position, setPosition] = useState<[number, number] | null>(null);
    const [error, setError] = useState<string | null>(null);
    const intervalRef = useRef<ReturnType<typeof setTimeout> | null>(null);
    const currentInterval = useRef(FAR_INTERVAL);
    const onTriggeredRef = useRef(onTriggered);

    useEffect(() => {
        onTriggeredRef.current = onTriggered;
    }, [onTriggered]);

    const clearPolling = useCallback(() => {
        if (intervalRef.current) {
            clearInterval(intervalRef.current);
            intervalRef.current = null;
        }
    }, []);

    const checkPosition = useCallback(
        async (pos: GeolocationPosition) => {
            setPosition([pos.coords.latitude, pos.coords.longitude]);
            try {
                const response = await axios.post(
                    `/geo-fences/${geofenceId}/check`,
                    {
                        lat: pos.coords.latitude,
                        lng: pos.coords.longitude,
                    },
                );

                if (response.data.triggered) {
                    clearPolling();
                    onTriggeredRef.current();
                    return;
                }

                const distance: number = response.data.distance_meters;
                const newInterval =
                    distance <= CLOSE_THRESHOLD ? CLOSE_INTERVAL : FAR_INTERVAL;

                if (newInterval !== currentInterval.current) {
                    currentInterval.current = newInterval;
                }
            } catch {
                setError('Failed to check position');
            }
        },
        [geofenceId, clearPolling],
    );

    const poll = useCallback(() => {
        navigator.geolocation.getCurrentPosition(
            (position) => {
                checkPosition(position);
            },
            (err) => {
                setError(err.message);
            },
            { enableHighAccuracy: true, timeout: 10000 },
        );
    }, [checkPosition]);

    useEffect(() => {
        if (!isActive) {
            clearPolling();
            return;
        }

        if (!navigator.geolocation) {
            setError('Geolocation not supported');
            return;
        }

        poll();

        intervalRef.current = setInterval(() => {
            poll();
        }, currentInterval.current);

        return clearPolling;
    }, [isActive, poll, clearPolling]);

    return {
        tracking: isActive,
        position,
        error,
    };
}
