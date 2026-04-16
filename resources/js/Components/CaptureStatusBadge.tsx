export default function CaptureStatusBadge({ status }: { status: 'pending' | 'success' | 'failed' }) {
    const styles = {
        pending: 'bg-yellow-100 text-yellow-800',
        success: 'bg-green-100 text-green-800',
        failed:  'bg-red-100 text-red-800',
    };

    return (
        <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${styles[status]}`}>
            {status.charAt(0).toUpperCase() + status.slice(1)}
        </span>
    );
}
