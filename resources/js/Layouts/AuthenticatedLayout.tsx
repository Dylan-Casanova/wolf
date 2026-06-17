import { BottomTabBar } from '@/Components/Theme/BottomTabBar';
import { BusyOverlay } from '@/Components/Theme/BusyOverlay';
import { DeviceClaimModal } from '@/Components/Theme/DeviceClaimModal';
import { GlassPanel } from '@/Components/Theme/GlassPanel';
import { MobileMenu } from '@/Components/Theme/MobileMenu';
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
            <div className="mx-auto flex max-w-7xl flex-col gap-4 px-4 pb-24 pt-4 lg:flex-row lg:gap-7 lg:px-10 lg:pb-10 lg:pt-10">
                <div className="flex items-center justify-between gap-3 lg:hidden">
                    <WolfLogo />
                    <div className="flex items-center gap-3">
                        <UserBadge />
                        <MobileMenu onClaimClick={() => setClaimOpen(true)} />
                    </div>
                </div>

                <div className="hidden lg:block">
                    <Navbar1 onClaimClick={() => setClaimOpen(true)} />
                </div>

                <GlassPanel className="flex-1 px-4 py-5 lg:px-8 lg:py-7">
                    <div className="mb-6 hidden items-end justify-between lg:flex">
                        <WolfLogo />
                        <UserBadge />
                    </div>
                    <div className="flex gap-5">
                        <div className="hidden lg:block">
                            <Navbar2 />
                        </div>
                        <div className="flex flex-1 flex-col gap-3.5">
                            {children}
                        </div>
                    </div>
                    {trigger}
                </GlassPanel>
            </div>

            <BottomTabBar />

            <DeviceClaimModal
                open={claimOpen}
                onClose={() => setClaimOpen(false)}
            />
            <BusyOverlay />
        </StageBackground>
    );
}
