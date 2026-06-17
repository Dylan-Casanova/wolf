import { router } from '@inertiajs/react';
import { useEffect, useState, type ReactNode } from 'react';

interface Props {
    onClaimClick: () => void;
}

interface MenuItemProps {
    onClick: () => void;
    children: ReactNode;
    label: string;
}

function MenuItem({ onClick, children, label }: MenuItemProps) {
    return (
        <button
            onClick={onClick}
            className="flex w-full items-center gap-3 rounded-wolf-pill border border-wolf-card-border bg-white/[0.04] px-4 py-3 text-left text-sm font-medium text-slate-200 transition-colors hover:bg-white/[0.08]"
        >
            <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg border border-white/10 bg-white/5 text-slate-300">
                {children}
            </span>
            {label}
        </button>
    );
}

/**
 * Mobile hamburger menu in the top header. Replaces the horizontal Navbar1
 * icon row at < lg. Same three actions: Profile, Claim device, Logout.
 */
export function MobileMenu({ onClaimClick }: Props) {
    const [open, setOpen] = useState(false);

    useEffect(() => {
        if (!open) return;
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') setOpen(false);
        };
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [open]);

    const close = () => setOpen(false);

    const handleProfile = () => {
        close();
        router.visit('/profile');
    };

    const handleClaim = () => {
        close();
        onClaimClick();
    };

    const handleLogout = () => {
        close();
        router.post('/logout');
    };

    return (
        <div className="relative lg:hidden">
            <button
                onClick={() => setOpen((v) => !v)}
                aria-label="Menu"
                aria-expanded={open}
                aria-haspopup="menu"
                className="flex h-11 w-11 items-center justify-center rounded-xl border border-white/10 bg-white/5 text-slate-300 transition-colors hover:bg-white/10"
            >
                <svg
                    width="20"
                    height="20"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    strokeWidth="1.8"
                    strokeLinecap="round"
                >
                    {open ? (
                        <>
                            <path d="M6 6l12 12" />
                            <path d="M18 6L6 18" />
                        </>
                    ) : (
                        <>
                            <path d="M4 7h16" />
                            <path d="M4 12h16" />
                            <path d="M4 17h16" />
                        </>
                    )}
                </svg>
            </button>

            {open && (
                <>
                    <div
                        aria-hidden
                        onClick={close}
                        className="fixed inset-0 z-20 bg-black/40 backdrop-blur-sm"
                    />
                    <div
                        role="menu"
                        className="absolute right-0 top-full z-30 mt-2 flex w-56 flex-col gap-2 rounded-wolf-card border border-wolf-glass-border bg-wolf-glass p-2 shadow-[0_30px_80px_rgba(0,0,0,0.55)] backdrop-blur-wolf-panel"
                    >
                        <MenuItem onClick={handleProfile} label="Profile">
                            <svg
                                width="14"
                                height="14"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                strokeWidth="1.8"
                            >
                                <circle cx="12" cy="8" r="4" />
                                <path d="M4 21c0-4 4-7 8-7s8 3 8 7" />
                            </svg>
                        </MenuItem>
                        <MenuItem onClick={handleClaim} label="Claim device">
                            <svg
                                width="14"
                                height="14"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                strokeWidth="1.8"
                            >
                                <path d="M12 5v14M5 12h14" />
                            </svg>
                        </MenuItem>
                        <MenuItem onClick={handleLogout} label="Logout">
                            <svg
                                width="14"
                                height="14"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                strokeWidth="1.8"
                            >
                                <path d="M16 17l5-5-5-5M21 12H9M13 21H5a2 2 0 01-2-2V5a2 2 0 012-2h8" />
                            </svg>
                        </MenuItem>
                    </div>
                </>
            )}
        </div>
    );
}
