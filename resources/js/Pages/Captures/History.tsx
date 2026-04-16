import CaptureStatusBadge from '@/Components/CaptureStatusBadge';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageProps, PaginatedCaptures } from '@/types';
import { Head, Link } from '@inertiajs/react';

interface Props extends PageProps {
    captures: PaginatedCaptures;
    isAdmin: boolean;
}

export default function History({ captures, isAdmin }: Props) {
    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Capture History
                </h2>
            }
        >
            <Head title="Capture History" />

            <div className="py-12">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-gray-200">
                                <thead className="bg-gray-50">
                                    <tr>
                                        {isAdmin && (
                                            <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                                User
                                            </th>
                                        )}
                                        <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                            Device
                                        </th>
                                        <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                            Trigger
                                        </th>
                                        <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                            Type
                                        </th>
                                        <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                            Status
                                        </th>
                                        <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                            Captured At
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-200 bg-white">
                                    {captures.data.length === 0 ? (
                                        <tr>
                                            <td
                                                colSpan={isAdmin ? 6 : 5}
                                                className="px-6 py-10 text-center text-sm text-gray-500"
                                            >
                                                No captures yet.
                                            </td>
                                        </tr>
                                    ) : (
                                        captures.data.map((capture) => (
                                            <tr key={capture.id}>
                                                {isAdmin && (
                                                    <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-900">
                                                        {capture.user?.name ?? '—'}
                                                    </td>
                                                )}
                                                <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-900">
                                                    {capture.device?.name ?? '—'}
                                                </td>
                                                <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                                                    {capture.trigger_source}
                                                </td>
                                                <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                                                    {capture.media_type}
                                                </td>
                                                <td className="whitespace-nowrap px-6 py-4">
                                                    <CaptureStatusBadge status={capture.status} />
                                                </td>
                                                <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                                                    {new Date(capture.captured_at).toLocaleString()}
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </div>

                        {/* Pagination */}
                        {(captures.meta.last_page > 1) && (
                            <div className="flex items-center justify-between border-t border-gray-200 px-6 py-4">
                                <p className="text-sm text-gray-700">
                                    Page {captures.meta.current_page} of {captures.meta.last_page} &mdash; {captures.meta.total} total
                                </p>
                                <div className="flex gap-2">
                                    {captures.links.prev && (
                                        <Link
                                            href={captures.links.prev}
                                            className="rounded border border-gray-300 px-3 py-1 text-sm hover:bg-gray-50"
                                        >
                                            Previous
                                        </Link>
                                    )}
                                    {captures.links.next && (
                                        <Link
                                            href={captures.links.next}
                                            className="rounded border border-gray-300 px-3 py-1 text-sm hover:bg-gray-50"
                                        >
                                            Next
                                        </Link>
                                    )}
                                </div>
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
