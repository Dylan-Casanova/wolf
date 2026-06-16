import type { ReactNode } from 'react';

type Variant = 'panel' | 'rail' | 'card' | 'pill';

interface Props {
    children: ReactNode;
    variant?: Variant;
    className?: string;
}

const radius: Record<Variant, string> = {
    panel: 'rounded-wolf-panel',
    rail: 'rounded-wolf-rail',
    card: 'rounded-wolf-card',
    pill: 'rounded-wolf-pill',
};

const blur: Record<Variant, string> = {
    panel: 'backdrop-blur-wolf-panel',
    rail: 'backdrop-blur-wolf-rail',
    card: 'backdrop-blur-wolf-rail',
    pill: 'backdrop-blur-wolf-rail',
};

export function GlassPanel({
    children,
    variant = 'panel',
    className = '',
}: Props) {
    return (
        <div
            className={`border border-wolf-glass-border bg-wolf-glass shadow-[0_30px_80px_rgba(0,0,0,0.55),inset_0_1px_0_rgba(255,255,255,0.06)] ${radius[variant]} ${blur[variant]} ${className}`}
        >
            {children}
        </div>
    );
}
