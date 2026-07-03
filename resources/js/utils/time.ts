/**
 * Format a millisecond duration as `HH:MM:SS` (or `MM:SS` when < 1 hour).
 * Returns `00:00` for non-positive durations — safe to call during
 * countdown-hit-zero renders.
 */
export const formatRemaining = (ms: number): string => {
    if (ms <= 0) return '00:00';
    const total = Math.floor(ms / 1000);
    const hours = Math.floor(total / 3600);
    const minutes = Math.floor((total % 3600) / 60);
    const seconds = total % 60;
    const mm = String(minutes).padStart(2, '0');
    const ss = String(seconds).padStart(2, '0');
    return hours > 0 ? `${hours}:${mm}:${ss}` : `${mm}:${ss}`;
};
