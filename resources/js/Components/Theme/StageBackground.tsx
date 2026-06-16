import type { ReactNode } from 'react';

interface Props {
    children: ReactNode;
}

export function StageBackground({ children }: Props) {
    return (
        <div className="relative min-h-screen overflow-hidden bg-wolf-void">
            <div
                aria-hidden
                className="pointer-events-none absolute inset-0"
                style={{
                    background:
                        'radial-gradient(ellipse 60% 50% at 100% 100%, rgba(239,68,68,0.18) 0%, transparent 60%),' +
                        'radial-gradient(ellipse 50% 40% at 0% 30%, rgba(56,189,248,0.10) 0%, transparent 60%),' +
                        'radial-gradient(ellipse 80% 60% at 50% 0%, rgba(244,63,94,0.08) 0%, transparent 50%)',
                }}
            />
            <div
                aria-hidden
                className="pointer-events-none absolute inset-x-0 top-0 h-0.5"
                style={{
                    background:
                        'linear-gradient(90deg, transparent 0%, #ef4444 30%, #ef4444 70%, transparent 100%)',
                    boxShadow: '0 0 12px #ef4444',
                }}
            />
            <div className="relative">{children}</div>
        </div>
    );
}
