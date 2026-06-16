import type { ReactNode } from 'react';

interface Props {
    children: ReactNode;
    label?: string;
}

export function TriggerPanel({ children, label }: Props) {
    return (
        <div className="mt-4">
            <div className="flex items-center justify-center gap-3.5 rounded-wolf-card border border-wolf-glass-border bg-white/[0.04] p-5">
                {children}
            </div>
            {label && (
                <div className="mt-2 text-center text-[10px] uppercase tracking-[2px] text-slate-500">
                    {label}
                </div>
            )}
        </div>
    );
}
