import type { PageProps } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { navItems, type NavItem } from './NavItems';

export function Navbar2() {
    const page = usePage<PageProps>();
    const isAdmin = page.props.auth.user.is_admin;
    const url = page.url;
    const visibleItems = navItems.filter((i) => !i.adminOnly || isAdmin);

    // Optimistic active state: when a Link is clicked, we mark its target as
    // active immediately so the user gets feedback before the page transition
    // completes. Inertia's onStart fires on click, onFinish clears the intent
    // once navigation resolves (success or fail).
    const [intentHref, setIntentHref] = useState<string | null>(null);

    const isActive = (item: NavItem) => {
        if (intentHref) return item.href === intentHref;
        return item.routeMatch.some((r) => url.startsWith(r));
    };

    return (
        <nav className="flex w-[220px] shrink-0 flex-col gap-2.5">
            {visibleItems.map((item) => {
                const active = isActive(item);
                return (
                    <Link
                        key={item.href}
                        href={item.href}
                        onStart={() => setIntentHref(item.href)}
                        onFinish={() => setIntentHref(null)}
                        className={`flex items-center gap-3 rounded-wolf-pill border px-3 py-2.5 transition-colors ${
                            active
                                ? 'border-wolf-active-border bg-wolf-active shadow-[0_6px_20px_rgba(99,102,241,0.18),inset_0_1px_0_rgba(255,255,255,0.08)]'
                                : 'border-wolf-card-border bg-wolf-card hover:bg-white/[0.05]'
                        }`}
                    >
                        <div
                            className={`flex h-7 w-7 items-center justify-center rounded-lg text-xs ${
                                active
                                    ? 'bg-gradient-to-br from-red-500 to-indigo-600 text-white'
                                    : 'border border-white/10 bg-white/5 text-slate-400'
                            }`}
                        >
                            {item.badge}
                        </div>
                        <span
                            className={`text-sm font-medium ${
                                active ? 'text-white' : 'text-slate-200'
                            }`}
                        >
                            {item.label}
                        </span>
                        {item.adminOnly && (
                            <span className="ml-auto text-[10px] text-slate-500">
                                admin
                            </span>
                        )}
                    </Link>
                );
            })}
        </nav>
    );
}
