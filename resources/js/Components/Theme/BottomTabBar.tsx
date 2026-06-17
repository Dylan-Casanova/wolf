import type { PageProps } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { navItems, type NavItem } from './NavItems';

/**
 * Fixed bottom navigation bar for mobile (< lg). Mirrors Navbar2's items
 * and active-state behavior, but renders as a horizontal strip with
 * stacked icon-over-label tabs.
 */
export function BottomTabBar() {
    const page = usePage<PageProps>();
    const isAdmin = page.props.auth.user.is_admin;
    const url = page.url;
    const visibleItems = navItems.filter((i) => !i.adminOnly || isAdmin);

    const [intentHref, setIntentHref] = useState<string | null>(null);

    const isActive = (item: NavItem) => {
        if (intentHref) return item.href === intentHref;
        return item.routeMatch.some((r) => url.startsWith(r));
    };

    return (
        <nav
            aria-label="Primary"
            className="fixed inset-x-0 bottom-0 z-30 border-t border-wolf-glass-border bg-wolf-glass backdrop-blur-wolf-panel lg:hidden"
        >
            <ul className="flex h-16 items-stretch justify-around px-2 pb-[env(safe-area-inset-bottom)]">
                {visibleItems.map((item) => {
                    const active = isActive(item);
                    return (
                        <li key={item.href} className="flex-1">
                            <Link
                                href={item.href}
                                onStart={() => setIntentHref(item.href)}
                                onFinish={() => setIntentHref(null)}
                                aria-current={active ? 'page' : undefined}
                                className={`flex h-full flex-col items-center justify-center gap-1 rounded-wolf-pill transition-colors ${
                                    active
                                        ? 'text-white'
                                        : 'text-slate-400 hover:text-slate-200'
                                }`}
                            >
                                <span
                                    className={`flex h-7 w-7 items-center justify-center rounded-lg text-sm ${
                                        active
                                            ? 'bg-gradient-to-br from-red-500 to-indigo-600 text-white'
                                            : 'border border-white/10 bg-white/5'
                                    }`}
                                >
                                    {item.badge}
                                </span>
                                <span className="text-[10px] font-medium uppercase tracking-wider">
                                    {item.label}
                                </span>
                            </Link>
                        </li>
                    );
                })}
            </ul>
        </nav>
    );
}
