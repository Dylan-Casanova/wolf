import { router } from '@inertiajs/react';
import axios from 'axios';
import { useState } from 'react';
import { createPortal } from 'react-dom';

interface Props {
    open: boolean;
    onClose: () => void;
}

export function DeviceClaimModal({ open, onClose }: Props) {
    const [deviceId, setDeviceId] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);

    if (!open) return null;
    if (typeof document === 'undefined') return null;

    const onSubmit = async (e: React.FormEvent) => {
        e.preventDefault();
        setError(null);
        if (!deviceId.trim()) {
            setError('Please enter a setup token.');
            return;
        }
        setSubmitting(true);
        try {
            await axios.post('/devices/claim', { device_id: deviceId.trim() });
            setDeviceId('');
            onClose();
            router.reload();
        } catch (err) {
            const msg =
                axios.isAxiosError(err) && err.response?.data?.message
                    ? String(err.response.data.message)
                    : 'Failed to claim device. Please try again.';
            setError(msg);
        } finally {
            setSubmitting(false);
        }
    };

    return createPortal(
        <div className="fixed inset-0 z-[1000] flex items-center justify-center bg-black/70 p-4">
            <div className="rounded-wolf-card border-wolf-glass-border bg-wolf-glass backdrop-blur-wolf-panel w-full max-w-md border p-6 shadow-[0_30px_80px_rgba(0,0,0,0.7)]">
                <h3 className="text-lg font-semibold text-white">
                    Claim a device
                </h3>
                <p className="mt-2 text-sm text-slate-400">
                    Enter the setup token printed on the device.
                </p>
                <form onSubmit={onSubmit} className="mt-4 flex flex-col gap-3">
                    <input
                        type="text"
                        autoFocus
                        value={deviceId}
                        onChange={(e) => setDeviceId(e.target.value)}
                        placeholder="e.g. wolf-abc-12345"
                        autoCapitalize="off"
                        autoCorrect="off"
                        spellCheck={false}
                        className="rounded-wolf-pill border-wolf-glass-border focus:border-wolf-active-border focus:ring-wolf-active-border border bg-white/5 px-3 py-2 text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-1"
                    />
                    {error && <p className="text-xs text-red-400">{error}</p>}
                    <div className="mt-2 flex justify-end gap-2">
                        <button
                            type="button"
                            onClick={onClose}
                            className="rounded-wolf-pill px-4 py-2 text-sm font-medium text-slate-300 hover:bg-white/5"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            disabled={submitting}
                            className="rounded-wolf-pill bg-gradient-to-br from-indigo-500 to-indigo-700 px-4 py-2 text-sm font-semibold text-white shadow-[0_8px_24px_rgba(99,102,241,0.4)] disabled:opacity-50"
                        >
                            {submitting ? 'Claiming…' : 'Claim'}
                        </button>
                    </div>
                </form>
            </div>
        </div>,
        document.body,
    );
}
