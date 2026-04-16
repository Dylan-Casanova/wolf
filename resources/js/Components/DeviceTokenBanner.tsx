import { useState } from 'react';

export default function DeviceTokenBanner({ token }: { token: string }) {
    const [copied, setCopied] = useState(false);

    const copyToClipboard = () => {
        navigator.clipboard.writeText(token);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    };

    return (
        <div className="mb-6 rounded-md border border-amber-300 bg-amber-50 p-4">
            <h3 className="text-sm font-semibold text-amber-800">
                Device Token Generated
            </h3>
            <p className="mt-1 text-sm text-amber-700">
                Copy this token now. It will not be shown again.
            </p>
            <div className="mt-3 flex items-center gap-3">
                <code className="flex-1 break-all rounded bg-amber-100 px-3 py-2 font-mono text-sm text-amber-900">
                    {token}
                </code>
                <button
                    type="button"
                    onClick={copyToClipboard}
                    className="shrink-0 rounded-md bg-amber-600 px-3 py-2 text-sm font-medium text-white hover:bg-amber-500 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-2"
                >
                    {copied ? 'Copied!' : 'Copy'}
                </button>
            </div>
        </div>
    );
}
