import { router } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { GlassPanel } from './GlassPanel';

interface Props {
    onClaimClick: () => void;
}

interface IconButtonProps {
    onClick: () => void;
    label: string;
    children: ReactNode;
}

function IconButton({ onClick, label, children }: IconButtonProps) {
    return (
        <div className="group relative">
            <button
                onClick={onClick}
                aria-label={label}
                className="flex h-11 w-11 items-center justify-center rounded-xl border border-white/10 bg-white/5 text-slate-300 transition-colors hover:bg-white/10"
            >
                {children}
            </button>
            <span
                aria-hidden
                className="border-wolf-glass-border bg-wolf-glass backdrop-blur-wolf-rail pointer-events-none absolute left-1/2 top-full z-50 mt-2 -translate-x-1/2 translate-y-1 whitespace-nowrap rounded-md border px-2.5 py-1 text-xs font-medium text-slate-200 opacity-0 transition-all duration-150 group-hover:translate-y-0 group-hover:opacity-100 lg:left-full lg:top-1/2 lg:ml-3 lg:mt-0 lg:-translate-x-1 lg:-translate-y-1/2"
            >
                {label}
            </span>
        </div>
    );
}

export function Navbar1({ onClaimClick }: Props) {
    return (
        <GlassPanel
            variant="rail"
            className="relative z-20 flex flex-row items-center gap-2.5 self-stretch p-2 lg:flex-col lg:gap-3.5 lg:self-center lg:p-2.5"
        >
            <IconButton
                onClick={() => router.visit('/profile')}
                label="Profile"
            >
                <svg
                    width="18"
                    height="18"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    strokeWidth="1.6"
                >
                    <circle cx="12" cy="8" r="4" />
                    <path d="M4 21c0-4 4-7 8-7s8 3 8 7" />
                </svg>
            </IconButton>
            <IconButton onClick={onClaimClick} label="Claim device">
                <svg
                    width="18"
                    height="18"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    strokeWidth="1.6"
                >
                    <path d="M12 5v14M5 12h14" />
                </svg>
            </IconButton>
            <IconButton onClick={() => router.post('/logout')} label="Logout">
                <svg
                    width="18"
                    height="18"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    strokeWidth="1.6"
                >
                    <path d="M16 17l5-5-5-5M21 12H9M13 21H5a2 2 0 01-2-2V5a2 2 0 012-2h8" />
                </svg>
            </IconButton>
        </GlassPanel>
    );
}
