import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

export default function Claim() {
    const { data, setData, post, processing, errors } = useForm({
        device_id: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post('/devices/claim');
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Claim a Device
                </h2>
            }
        >
            <Head title="Claim Device" />

            <div className="py-12">
                <div className="mx-auto max-w-2xl sm:px-6 lg:px-8">
                    <div className="bg-white p-6 shadow-sm sm:rounded-lg">
                        <p className="mb-4 text-sm text-gray-600">
                            Enter the Device ID printed on your hardware to link
                            it to your account.
                        </p>
                        <form onSubmit={submit} className="space-y-6">
                            <div>
                                <InputLabel
                                    htmlFor="device_id"
                                    value="Device ID"
                                />
                                <TextInput
                                    id="device_id"
                                    value={data.device_id}
                                    onChange={(e) =>
                                        setData('device_id', e.target.value)
                                    }
                                    className="mt-1 block w-full"
                                    required
                                    isFocused
                                    placeholder="e.g. ESP8266-001"
                                />
                                <InputError
                                    message={errors.device_id}
                                    className="mt-2"
                                />
                            </div>

                            <div className="flex items-center justify-end">
                                <PrimaryButton disabled={processing}>
                                    Claim Device
                                </PrimaryButton>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
