import axios from 'axios';
import { useState } from 'react';

interface Props {
    deviceId: number;
}

type State = 'idle' | 'sending' | 'sent';

export function GarageOpenButton({ deviceId }: Props) {
    const [state, setState] = useState<State>('idle');

    const onClick = async () => {
        if (state !== 'idle') return;
        setState('sending');
        try {
            await axios.post('/garage/trigger', { device_id: deviceId });
            setState('sent');
            setTimeout(() => setState('idle'), 1500);
        } catch {
            setState('idle');
        }
    };

    const label =
        state === 'sent'
            ? 'SENT ✓'
            : state === 'sending'
              ? 'SENDING…'
              : 'OPEN GARAGE';

    const background =
        state === 'sent'
            ? 'linear-gradient(135deg, #22c55e 0%, #15803d 100%)'
            : 'linear-gradient(135deg, #6366f1 0%, #4338ca 100%)';

    return (
        <button
            onClick={onClick}
            disabled={state !== 'idle'}
            className="rounded-wolf-card px-11 py-4 text-base font-bold tracking-wide text-white shadow-[0_8px_28px_rgba(99,102,241,0.45),inset_0_1px_0_rgba(255,255,255,0.18)] transition-all hover:brightness-110 active:brightness-95 disabled:opacity-90"
            style={{ background }}
        >
            {label}
        </button>
    );
}
