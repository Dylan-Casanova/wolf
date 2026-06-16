import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import DeleteUserForm from '@/Pages/Profile/Partials/DeleteUserForm';
import UpdatePasswordForm from '@/Pages/Profile/Partials/UpdatePasswordForm';
import UpdateProfileInformationForm from '@/Pages/Profile/Partials/UpdateProfileInformationForm';
import { Head } from '@inertiajs/react';

interface Props {
    mustVerifyEmail: boolean;
    status?: string;
}

export default function Edit({ mustVerifyEmail, status }: Props) {
    return (
        <AuthenticatedLayout>
            <Head title="Profile" />
            <div className="flex flex-col gap-4">
                <div className="rounded-wolf-card border border-wolf-card-border bg-black/40 p-6 text-slate-200">
                    <UpdateProfileInformationForm
                        mustVerifyEmail={mustVerifyEmail}
                        status={status}
                        className="max-w-xl"
                    />
                </div>
                <div className="rounded-wolf-card border border-wolf-card-border bg-black/40 p-6 text-slate-200">
                    <UpdatePasswordForm className="max-w-xl" />
                </div>
                <div className="rounded-wolf-card border border-wolf-card-border bg-black/40 p-6 text-slate-200">
                    <DeleteUserForm className="max-w-xl" />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
