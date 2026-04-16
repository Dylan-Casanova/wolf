import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import DangerButton from '@/Components/DangerButton';
import DeviceStatusBadge from '@/Components/DeviceStatusBadge';
import DeviceTokenBanner from '@/Components/DeviceTokenBanner';
import Modal from '@/Components/Modal';
import SecondaryButton from '@/Components/SecondaryButton';
import { Device, PageProps } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

export default function Index({ devices }: PageProps<{ devices: Device[] }>) {
    const { flash } = usePage<PageProps>().props;
    const [confirmingDeviceDeletion, setConfirmingDeviceDeletion] = useState<number | null>(null);

    const { delete: destroy, processing } = useForm({});

    const deleteDevice = () => {
        if (confirmingDeviceDeletion === null) return;

        destroy(route('devices.destroy', confirmingDeviceDeletion), {
            preserveScroll: true,
            onSuccess: () => setConfirmingDeviceDeletion(null),
        });
    };

    const closeModal = () => setConfirmingDeviceDeletion(null);

    return (
        <AuthenticatedLayout
            header={
                <div className="flex items-center justify-between">
                    <h2 className="text-xl font-semibold leading-tight text-gray-800">
                        Devices
                    </h2>
                    <Link
                        href={route('devices.create')}
                        className="inline-flex items-center rounded-md border border-transparent bg-gray-800 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white transition duration-150 ease-in-out hover:bg-gray-700 focus:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 active:bg-gray-900"
                    >
                        Add Device
                    </Link>
                </div>
            }
        >
            <Head title="Devices" />

            <div className="py-12">
                <div className="mx-auto max-w-7xl sm:px-6 lg:px-8">
                    {flash.device_token && (
                        <DeviceTokenBanner token={flash.device_token} />
                    )}

                    <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-gray-200">
                                <thead className="bg-gray-50">
                                    <tr>
                                        <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Name</th>
                                        <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Device ID</th>
                                        <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Assigned User</th>
                                        <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Type</th>
                                        <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Status</th>
                                        <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Last Seen</th>
                                        <th className="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">Actions</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-200 bg-white">
                                    {devices.length === 0 && (
                                        <tr>
                                            <td colSpan={7} className="px-6 py-8 text-center text-sm text-gray-500">
                                                No devices registered yet.
                                            </td>
                                        </tr>
                                    )}
                                    {devices.map((device) => (
                                        <tr key={device.id}>
                                            <td className="whitespace-nowrap px-6 py-4 text-sm font-medium text-gray-900">
                                                {device.name}
                                            </td>
                                            <td className="whitespace-nowrap px-6 py-4 font-mono text-sm text-gray-500">
                                                {device.device_id}
                                            </td>
                                            <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                                                {device.user ? (
                                                    <div>
                                                        <div>{device.user.name}</div>
                                                        <div className="text-xs text-gray-400">{device.user.email}</div>
                                                    </div>
                                                ) : (
                                                    <span className="text-gray-400">—</span>
                                                )}
                                            </td>
                                            <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                                                {device.type}
                                            </td>
                                            <td className="whitespace-nowrap px-6 py-4">
                                                <DeviceStatusBadge isOnline={device.is_online} />
                                            </td>
                                            <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                                                {device.last_seen_at ?? 'Never'}
                                            </td>
                                            <td className="whitespace-nowrap px-6 py-4 text-right text-sm">
                                                <Link
                                                    href={route('devices.edit', device.id)}
                                                    className="text-indigo-600 hover:text-indigo-900"
                                                >
                                                    Edit
                                                </Link>
                                                <button
                                                    type="button"
                                                    onClick={() => setConfirmingDeviceDeletion(device.id)}
                                                    className="ml-4 text-red-600 hover:text-red-900"
                                                >
                                                    Delete
                                                </button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <Modal show={confirmingDeviceDeletion !== null} onClose={closeModal}>
                <div className="p-6">
                    <h2 className="text-lg font-medium text-gray-900">
                        Are you sure you want to delete this device?
                    </h2>
                    <p className="mt-1 text-sm text-gray-600">
                        This will permanently remove the device and invalidate its token. Any captures linked to this device will remain but the device will no longer be able to upload new media.
                    </p>
                    <div className="mt-6 flex justify-end">
                        <SecondaryButton onClick={closeModal}>Cancel</SecondaryButton>
                        <DangerButton className="ms-3" disabled={processing} onClick={deleteDevice}>
                            Delete Device
                        </DangerButton>
                    </div>
                </div>
            </Modal>
        </AuthenticatedLayout>
    );
}
