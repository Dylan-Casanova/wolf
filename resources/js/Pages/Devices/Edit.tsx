import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import DangerButton from '@/Components/DangerButton';
import DeviceTokenBanner from '@/Components/DeviceTokenBanner';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import { Device, PageProps, User } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

export default function Edit({
    device,
    users,
}: PageProps<{ device: Device; users: Pick<User, 'id' | 'name' | 'email'>[] }>) {
    const { flash } = usePage<PageProps>().props;
    const [confirmingTokenRegeneration, setConfirmingTokenRegeneration] = useState(false);

    const { data, setData, put, processing, errors } = useForm({
        name: device.name,
        device_id: device.device_id,
        user_id: String(device.user_id),
        type: device.type,
    });

    const { post: regenerate, processing: regenerating } = useForm({});

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('devices.update', device.id));
    };

    const regenerateToken = () => {
        regenerate(route('devices.regenerate-token', device.id), {
            preserveScroll: true,
            onSuccess: () => setConfirmingTokenRegeneration(false),
        });
    };

    const closeModal = () => setConfirmingTokenRegeneration(false);

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Edit Device
                </h2>
            }
        >
            <Head title="Edit Device" />

            <div className="py-12">
                <div className="mx-auto max-w-2xl sm:px-6 lg:px-8">
                    {flash.device_token && (
                        <DeviceTokenBanner token={flash.device_token} />
                    )}

                    <div className="bg-white p-6 shadow-sm sm:rounded-lg">
                        <form onSubmit={submit} className="space-y-6">
                            <div>
                                <InputLabel htmlFor="name" value="Device Name" />
                                <TextInput
                                    id="name"
                                    value={data.name}
                                    onChange={(e) => setData('name', e.target.value)}
                                    className="mt-1 block w-full"
                                    required
                                    isFocused
                                />
                                <InputError message={errors.name} className="mt-2" />
                            </div>

                            <div>
                                <InputLabel htmlFor="device_id" value="Device ID" />
                                <TextInput
                                    id="device_id"
                                    value={data.device_id}
                                    onChange={(e) => setData('device_id', e.target.value)}
                                    className="mt-1 block w-full"
                                    required
                                />
                                <InputError message={errors.device_id} className="mt-2" />
                            </div>

                            <div>
                                <InputLabel htmlFor="user_id" value="Assign to User" />
                                <select
                                    id="user_id"
                                    value={data.user_id}
                                    onChange={(e) => setData('user_id', e.target.value)}
                                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    required
                                >
                                    <option value="">Select a user...</option>
                                    {users.map((user) => (
                                        <option key={user.id} value={user.id}>
                                            {user.name} ({user.email})
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.user_id} className="mt-2" />
                            </div>

                            <div>
                                <InputLabel htmlFor="type" value="Device Type" />
                                <TextInput
                                    id="type"
                                    value={data.type}
                                    onChange={(e) => setData('type', e.target.value)}
                                    className="mt-1 block w-full"
                                />
                                <InputError message={errors.type} className="mt-2" />
                            </div>

                            <div className="flex items-center justify-end gap-4">
                                <Link
                                    href={route('devices.index')}
                                    className="text-sm text-gray-600 underline hover:text-gray-900"
                                >
                                    Cancel
                                </Link>
                                <PrimaryButton disabled={processing}>
                                    Update Device
                                </PrimaryButton>
                            </div>
                        </form>
                    </div>

                    <div className="mt-6 bg-white p-6 shadow-sm sm:rounded-lg">
                        <h3 className="text-lg font-medium text-gray-900">Device Token</h3>
                        <p className="mt-1 text-sm text-gray-600">
                            Regenerate the device token if the original was lost or compromised. This will invalidate the previous token.
                        </p>
                        <div className="mt-4">
                            <DangerButton onClick={() => setConfirmingTokenRegeneration(true)}>
                                Regenerate Token
                            </DangerButton>
                        </div>
                    </div>
                </div>
            </div>

            <Modal show={confirmingTokenRegeneration} onClose={closeModal}>
                <div className="p-6">
                    <h2 className="text-lg font-medium text-gray-900">
                        Regenerate device token?
                    </h2>
                    <p className="mt-1 text-sm text-gray-600">
                        The current token will stop working immediately. You will need to update the token on the physical device.
                    </p>
                    <div className="mt-6 flex justify-end">
                        <SecondaryButton onClick={closeModal}>Cancel</SecondaryButton>
                        <DangerButton className="ms-3" disabled={regenerating} onClick={regenerateToken}>
                            Regenerate Token
                        </DangerButton>
                    </div>
                </div>
            </Modal>
        </AuthenticatedLayout>
    );
}
