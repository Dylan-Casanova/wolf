import type { PageProps } from '@/types';
import { usePage } from '@inertiajs/react';

export function UserBadge() {
    const { auth } = usePage<PageProps>().props;
    return (
        <div className="text-sm text-slate-300">
            Hi, <span className="text-white">{auth.user.name}</span>
        </div>
    );
}
