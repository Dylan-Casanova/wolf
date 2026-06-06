import { useState, useEffect, useRef, useCallback } from 'react';
import axios from 'axios';

type StreamStatus = 'idle' | 'connecting' | 'streaming' | 'ended' | 'error';

export default function StreamView() {
    const [status, setStatus] = useState<StreamStatus>('idle');
    const [streamId, setStreamId] = useState<number | null>(null);
    const [frameSrc, setFrameSrc] = useState<string | null>(null);
    const [timeLeft, setTimeLeft] = useState(120);
    const timerRef = useRef<ReturnType<typeof setInterval> | null>(null);
    const streamIdRef = useRef<number | null>(null);

    // Keep ref in sync for cleanup functions
    useEffect(() => {
        streamIdRef.current = streamId;
    }, [streamId]);

    const clearTimer = () => {
        if (timerRef.current) {
            clearInterval(timerRef.current);
            timerRef.current = null;
        }
    };

    const stopStream = useCallback(async (id?: number) => {
        const activeId = id ?? streamIdRef.current;
        if (!activeId) return;

        // Unsubscribe from Echo channel
        window.Echo?.leave(`stream.${activeId}`);

        try {
            await axios.post(`/stream/${activeId}/stop`);
        } catch {
            // Stream may already be ended
        }

        setStatus('ended');
        setFrameSrc(null);
        clearTimer();

        // Show "Stream ended" for 3 seconds, then return to idle
        setTimeout(() => {
            setStatus('idle');
            setStreamId(null);
            setTimeLeft(120);
        }, 3000);
    }, []);

    const startStream = async () => {
        setStatus('connecting');
        setFrameSrc(null);

        try {
            const response = await axios.post('/stream/start');
            const id = response.data.stream_id;
            setStreamId(id);
            setTimeLeft(120);

            // Subscribe to stream channel via Echo
            window.Echo
                .private(`stream.${id}`)
                .listen('.StreamFrameReceived', (e: { frame: string }) => {
                    setFrameSrc(`data:image/jpeg;base64,${e.frame}`);
                    setStatus('streaming');
                })
                .listen('.StreamEnded', () => {
                    setStatus('ended');
                    setFrameSrc(null);
                    clearTimer();
                    window.Echo?.leave(`stream.${id}`);

                    setTimeout(() => {
                        setStatus('idle');
                        setStreamId(null);
                        setTimeLeft(120);
                    }, 3000);
                });

            // Set status to streaming (will show connecting until first frame)
            setStatus('connecting');

            // Countdown timer
            timerRef.current = setInterval(() => {
                setTimeLeft((prev) => {
                    if (prev <= 1) {
                        stopStream(id);
                        return 0;
                    }
                    return prev - 1;
                });
            }, 1000);
        } catch {
            setStatus('error');
        }
    };

    // Cleanup on unmount / navigate away
    useEffect(() => {
        const handleBeforeUnload = () => {
            if (streamIdRef.current) {
                navigator.sendBeacon(`/stream/${streamIdRef.current}/stop`);
            }
        };

        window.addEventListener('beforeunload', handleBeforeUnload);

        return () => {
            window.removeEventListener('beforeunload', handleBeforeUnload);
            if (streamIdRef.current) {
                window.Echo?.leave(`stream.${streamIdRef.current}`);
                stopStream();
            }
        };
    }, [stopStream]);

    const formatTime = (seconds: number) => {
        const m = Math.floor(seconds / 60);
        const s = seconds % 60;
        return `${m}:${s.toString().padStart(2, '0')}`;
    };

    if (status === 'idle') {
        return (
            <div className="flex flex-col items-center gap-6">
                <button
                    onClick={startStream}
                    className="flex h-40 w-40 items-center justify-center rounded-full bg-indigo-600 text-white shadow-lg transition-all duration-150 hover:bg-indigo-700 hover:shadow-xl active:scale-95 focus:outline-none focus:ring-4 focus:ring-indigo-400 focus:ring-offset-2"
                >
                    <span className="flex flex-col items-center gap-1">
                        <svg className="h-10 w-10" fill="none" stroke="currentColor" strokeWidth={1.5} viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" d="m15.75 10.5 4.72-4.72a.75.75 0 0 1 1.28.53v11.38a.75.75 0 0 1-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 0 0 2.25-2.25v-9a2.25 2.25 0 0 0-2.25-2.25h-9A2.25 2.25 0 0 0 2.25 7.5v9a2.25 2.25 0 0 0 2.25 2.25Z" />
                        </svg>
                        <span className="text-sm font-semibold tracking-wide">LIVE VIEW</span>
                    </span>
                </button>
                <div className="flex h-72 w-full max-w-lg items-center justify-center rounded-2xl border-2 border-dashed border-gray-200 bg-gray-50 text-gray-400">
                    <span className="text-sm">Live feed will appear here</span>
                </div>
            </div>
        );
    }

    if (status === 'connecting') {
        return (
            <div className="flex flex-col items-center gap-6">
                <div className="flex h-72 w-full max-w-lg items-center justify-center rounded-2xl border border-gray-200 bg-gray-50">
                    <div className="flex flex-col items-center gap-3 text-gray-500">
                        <svg className="h-8 w-8 animate-spin text-indigo-500" viewBox="0 0 24 24" fill="none">
                            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z" />
                        </svg>
                        <span className="text-sm">Connecting to device...</span>
                    </div>
                </div>
            </div>
        );
    }

    if (status === 'error') {
        return (
            <div className="flex flex-col items-center gap-6">
                <button
                    onClick={startStream}
                    className="flex h-40 w-40 items-center justify-center rounded-full bg-indigo-600 text-white shadow-lg transition-all duration-150 hover:bg-indigo-700 hover:shadow-xl active:scale-95 focus:outline-none focus:ring-4 focus:ring-indigo-400 focus:ring-offset-2"
                >
                    <span className="flex flex-col items-center gap-1">
                        <svg className="h-10 w-10" fill="none" stroke="currentColor" strokeWidth={1.5} viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" d="m15.75 10.5 4.72-4.72a.75.75 0 0 1 1.28.53v11.38a.75.75 0 0 1-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 0 0 2.25-2.25v-9a2.25 2.25 0 0 0-2.25-2.25h-9A2.25 2.25 0 0 0 2.25 7.5v9a2.25 2.25 0 0 0 2.25 2.25Z" />
                        </svg>
                        <span className="text-sm font-semibold tracking-wide">RETRY</span>
                    </span>
                </button>
                <div className="flex h-72 w-full max-w-lg items-center justify-center rounded-2xl border border-red-200 bg-red-50 text-red-500">
                    <span className="text-sm font-medium">Failed to start stream. Try again.</span>
                </div>
            </div>
        );
    }

    if (status === 'ended') {
        return (
            <div className="flex flex-col items-center gap-6">
                <div className="flex h-72 w-full max-w-lg items-center justify-center rounded-2xl border border-gray-200 bg-gray-50">
                    <div className="flex flex-col items-center gap-3 text-gray-500">
                        <svg className="h-8 w-8 text-gray-400" fill="none" stroke="currentColor" strokeWidth={1.5} viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M9.75 9.75l4.5 4.5m0-4.5l-4.5 4.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <span className="text-sm font-medium">Stream ended</span>
                    </div>
                </div>
            </div>
        );
    }

    // Streaming
    return (
        <div className="flex flex-col items-center gap-4">
            <div className="w-full max-w-lg overflow-hidden rounded-2xl shadow-lg">
                {frameSrc ? (
                    <img
                        src={frameSrc}
                        alt="Live feed"
                        className="w-full bg-black"
                    />
                ) : (
                    <div className="flex h-72 w-full items-center justify-center bg-black">
                        <span className="text-sm text-gray-500">Waiting for first frame...</span>
                    </div>
                )}
                <div className="flex items-center justify-between bg-gray-800 px-4 py-2">
                    <div className="flex items-center gap-2">
                        <span className="h-2 w-2 animate-pulse rounded-full bg-red-500" />
                        <span className="text-xs font-medium text-red-400">LIVE</span>
                    </div>
                    <span className="text-xs text-gray-400">{formatTime(timeLeft)}</span>
                </div>
            </div>
            <button
                onClick={() => stopStream()}
                className="rounded-lg bg-red-600 px-6 py-2 text-sm font-semibold text-white shadow transition hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-400 focus:ring-offset-2"
            >
                Stop Stream
            </button>
        </div>
    );
}
