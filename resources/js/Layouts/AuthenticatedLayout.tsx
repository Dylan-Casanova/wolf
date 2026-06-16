import { DeviceClaimModal } from '@/Components/Theme/DeviceClaimModal';
import { GlassPanel } from '@/Components/Theme/GlassPanel';
import { Navbar1 } from '@/Components/Theme/Navbar1';
import { Navbar2 } from '@/Components/Theme/Navbar2';
import { StageBackground } from '@/Components/Theme/StageBackground';
import { UserBadge } from '@/Components/Theme/UserBadge';
import { WolfLogo } from '@/Components/Theme/WolfLogo';
import type { ReactNode } from 'react';
import { useState } from 'react';

interface Props {
    children: ReactNode;
    trigger?: ReactNode;
}

export default function AuthenticatedLayout({ children, trigger }: Props) {
    const [claimOpen, setClaimOpen] = useState(false);

    return (
        <StageBackground>
            <div className="mx-auto flex max-w-7xl gap-7 px-10 py-10">
                <Navbar1 onClaimClick={() => setClaimOpen(true)} />
                <GlassPanel className="flex-1 px-8 py-7">
                    <div className="mb-6 flex items-end justify-between">
                        <WolfLogo />
                        <UserBadge />
                    </div>
                    <div className="flex gap-5">
                        <Navbar2 />
                        <div className="flex flex-1 flex-col gap-3.5">
                            {children}
                        </div>
                    </div>
                    {trigger}
                </GlassPanel>
            </div>
            <DeviceClaimModal
                open={claimOpen}
                onClose={() => setClaimOpen(false)}
            />
        </StageBackground>
    );
}
