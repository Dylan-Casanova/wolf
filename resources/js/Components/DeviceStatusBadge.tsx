export default function DeviceStatusBadge({ isOnline }: { isOnline: boolean }) {
    return isOnline ? (
        <span className="inline-flex items-center rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-800">
            Online
        </span>
    ) : (
        <span className="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-800">
            Offline
        </span>
    );
}
