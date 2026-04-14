import { CaptureData } from '@/types';

interface Props {
    capture: CaptureData | null;
}

export default function MediaDisplay({ capture }: Props) {
    if (!capture) {
        return (
            <div className="flex h-72 w-full max-w-lg items-center justify-center rounded-2xl border-2 border-dashed border-gray-200 bg-gray-50 text-gray-400">
                <span className="text-sm">Capture result will appear here</span>
            </div>
        );
    }

    if (capture.status === 'pending') {
        return (
            <div className="flex h-72 w-full max-w-lg items-center justify-center rounded-2xl border border-gray-200 bg-gray-50">
                <div className="flex flex-col items-center gap-3 text-gray-500">
                    <svg className="h-8 w-8 animate-spin text-indigo-500" viewBox="0 0 24 24" fill="none">
                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z" />
                    </svg>
                    <span className="text-sm">Waiting for device…</span>
                </div>
            </div>
        );
    }

    if (capture.status === 'failed') {
        return (
            <div className="flex h-72 w-full max-w-lg items-center justify-center rounded-2xl border border-red-200 bg-red-50 text-red-500">
                <div className="flex flex-col items-center gap-2">
                    <svg className="h-8 w-8" fill="none" stroke="currentColor" strokeWidth={1.5} viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                    </svg>
                    <span className="text-sm font-medium">Capture failed</span>
                    {capture.error_message && (
                        <span className="text-xs text-red-400">{capture.error_message}</span>
                    )}
                </div>
            </div>
        );
    }

    if (!capture.media_url) return null;

    return (
        <div className="w-full max-w-lg overflow-hidden rounded-2xl shadow-lg">
            {capture.media_type === 'video' ? (
                <video
                    src={capture.media_url}
                    controls
                    autoPlay
                    className="w-full"
                />
            ) : (
                <img
                    src={capture.media_url}
                    alt="Device capture"
                    className="w-full object-cover"
                />
            )}
            <div className="bg-gray-800 px-4 py-2 text-xs text-gray-400">
                {new Date(capture.captured_at).toLocaleString()} · {capture.trigger_source}
            </div>
        </div>
    );
}
