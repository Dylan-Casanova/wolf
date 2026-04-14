import { CaptureData } from '@/types';
import { useForm } from '@inertiajs/react';

interface Props {
    onCapture?: (capture: CaptureData) => void;
}

export default function CaptureButton({ onCapture }: Props) {
    const { post, processing } = useForm({});

    const handleCapture = () => {
        post(route('device.capture'), {
            onSuccess: (page) => {
                const capture = (page.props as { flash?: { capture?: CaptureData } }).flash?.capture;
                if (capture && onCapture) {
                    onCapture(capture);
                }
            },
        });
    };

    return (
        <button
            onClick={handleCapture}
            disabled={processing}
            className={`
                relative flex h-40 w-40 items-center justify-center rounded-full
                bg-indigo-600 text-white shadow-lg transition-all duration-150
                hover:bg-indigo-700 hover:shadow-xl
                active:scale-95
                disabled:cursor-not-allowed disabled:opacity-60
                focus:outline-none focus:ring-4 focus:ring-indigo-400 focus:ring-offset-2
            `}
        >
            {processing ? (
                <span className="flex flex-col items-center gap-1">
                    <svg className="h-8 w-8 animate-spin" viewBox="0 0 24 24" fill="none">
                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z" />
                    </svg>
                    <span className="text-sm font-medium">Capturing…</span>
                </span>
            ) : (
                <span className="flex flex-col items-center gap-1">
                    <svg className="h-10 w-10" fill="none" stroke="currentColor" strokeWidth={1.5} viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" d="M6.827 6.175A2.31 2.31 0 0 1 5.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 0 0-1.134-.175 2.31 2.31 0 0 1-1.64-1.055l-.822-1.316a2.192 2.192 0 0 0-1.736-1.039 48.774 48.774 0 0 0-5.232 0 2.192 2.192 0 0 0-1.736 1.039l-.821 1.316Z" />
                        <path strokeLinecap="round" strokeLinejoin="round" d="M16.5 12.75a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0ZM18.75 10.5h.008v.008h-.008V10.5Z" />
                    </svg>
                    <span className="text-sm font-semibold tracking-wide">CAPTURE</span>
                </span>
            )}
        </button>
    );
}
