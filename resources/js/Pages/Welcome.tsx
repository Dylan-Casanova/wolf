import { GlassPanel } from '@/Components/Theme/GlassPanel';
import { StageBackground } from '@/Components/Theme/StageBackground';
import { WolfLogo } from '@/Components/Theme/WolfLogo';
import { PageProps } from '@/types';
import { Head, Link } from '@inertiajs/react';

export default function Welcome({ auth }: PageProps) {
    return (
        <StageBackground>
            <Head title="IopenIt — Smart Garage for Riders" />

            <div className="mx-auto max-w-6xl px-6">
                <header className="flex items-center justify-between py-6">
                    <WolfLogo />
                    <nav className="flex items-center gap-2">
                        {auth.user ? (
                            <Link
                                href={route('dashboard')}
                                className="rounded-wolf-pill border border-wolf-glass-border bg-wolf-glass px-4 py-2 text-sm font-medium text-white backdrop-blur-wolf-rail transition hover:bg-white/10"
                            >
                                Dashboard
                            </Link>
                        ) : (
                            <>
                                <Link
                                    href={route('login')}
                                    className="rounded-wolf-pill px-4 py-2 text-sm font-medium text-slate-300 transition hover:text-white"
                                >
                                    Log in
                                </Link>
                                <Link
                                    href={route('register')}
                                    className="rounded-wolf-pill bg-red-500 px-4 py-2 text-sm font-semibold text-white shadow-[0_8px_24px_rgba(239,68,68,0.35)] transition hover:bg-red-400"
                                >
                                    Get started
                                </Link>
                            </>
                        )}
                    </nav>
                </header>

                <section className="grid items-center gap-12 py-20 lg:grid-cols-12 lg:py-32">
                    <div className="lg:col-span-7">
                        <span className="inline-flex items-center gap-2 rounded-wolf-pill border border-red-500/30 bg-red-500/10 px-3 py-1 text-xs font-medium uppercase tracking-[3px] text-red-300">
                            <span className="h-1.5 w-1.5 rounded-full bg-red-400" />
                            Built for riders
                        </span>
                        <h1 className="mt-6 text-4xl font-extrabold leading-[1.05] tracking-tight text-white sm:text-5xl md:text-6xl lg:text-7xl">
                            Your garage opens{' '}
                            <span className="text-red-400">
                                before you break.
                            </span>
                        </h1>
                        <p className="mt-6 max-w-xl text-lg leading-relaxed text-slate-300">
                            IopenIt is a geo-fenced smart garage opener built
                            for motorcycle riders. Set a fence around home — the
                            door is already up when you roll into the driveway.
                            No fumbling. No pulling out your phone. Just ride
                            in.
                        </p>
                        <div className="mt-10 flex flex-wrap items-center gap-4">
                            {!auth.user && (
                                <Link
                                    href={route('register')}
                                    className="rounded-wolf-pill bg-red-500 px-6 py-3 text-sm font-semibold text-white shadow-[0_12px_32px_rgba(239,68,68,0.4)] transition hover:bg-red-400"
                                >
                                    Get started — it's free
                                </Link>
                            )}
                            <a
                                href="#how-it-works"
                                className="rounded-wolf-pill border border-wolf-glass-border bg-wolf-glass px-6 py-3 text-sm font-medium text-white backdrop-blur-wolf-rail transition hover:bg-white/10"
                            >
                                See how it works
                            </a>
                        </div>
                    </div>

                    <div className="lg:col-span-5">
                        <GlassPanel
                            variant="panel"
                            className="relative overflow-hidden p-8"
                        >
                            <div
                                aria-hidden
                                className="pointer-events-none absolute -right-20 -top-20 h-64 w-64 rounded-full bg-red-500/20 blur-3xl"
                            />
                            <div className="relative">
                                <div className="text-xs font-medium uppercase tracking-[3px] text-slate-400">
                                    Live status
                                </div>
                                <div className="mt-4 flex items-center gap-3">
                                    <div className="h-2.5 w-2.5 animate-pulse rounded-full bg-emerald-400 shadow-[0_0_12px_rgba(52,211,153,0.8)]" />
                                    <span className="text-sm font-medium text-emerald-300">
                                        Approaching home
                                    </span>
                                </div>
                                <div className="mt-6 text-3xl font-bold text-white">
                                    0.4 mi away
                                </div>
                                <div className="mt-1 text-sm text-slate-400">
                                    Door will open at 0.1 mi
                                </div>
                                <div className="mt-8 h-px w-full bg-gradient-to-r from-transparent via-red-500/40 to-transparent" />
                                <div className="mt-6 grid grid-cols-2 gap-4 text-sm">
                                    <div>
                                        <div className="text-slate-400">
                                            Geofence
                                        </div>
                                        <div className="mt-1 font-medium text-white">
                                            Active
                                        </div>
                                    </div>
                                    <div>
                                        <div className="text-slate-400">
                                            Device
                                        </div>
                                        <div className="mt-1 font-medium text-white">
                                            Online
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </GlassPanel>
                    </div>
                </section>

                <section id="how-it-works" className="py-20">
                    <div className="mx-auto max-w-2xl text-center">
                        <span className="text-xs font-medium uppercase tracking-[3px] text-slate-400">
                            How it works
                        </span>
                        <h2 className="mt-4 text-4xl font-bold tracking-tight text-white sm:text-5xl">
                            Three steps. Then never touch a remote again.
                        </h2>
                    </div>

                    <div className="mt-16 grid gap-6 lg:grid-cols-3">
                        {[
                            {
                                step: '01',
                                title: 'Drop a pin at home',
                                body: 'Set your geo-fence around your garage in under a minute. Pick the radius that matches your street.',
                            },
                            {
                                step: '02',
                                title: 'Ride',
                                body: 'IopenIt watches your location in the background. No app to open, no buttons to press while you ride.',
                            },
                            {
                                step: '03',
                                title: 'Door opens automatically',
                                body: 'Cross the fence and your garage opens itself. Roll straight in and park.',
                            },
                        ].map(({ step, title, body }) => (
                            <GlassPanel
                                key={step}
                                variant="card"
                                className="p-7"
                            >
                                <div className="text-xs font-medium tracking-[3px] text-red-400">
                                    {step}
                                </div>
                                <h3 className="mt-3 text-xl font-semibold text-white">
                                    {title}
                                </h3>
                                <p className="mt-3 text-sm leading-relaxed text-slate-300">
                                    {body}
                                </p>
                            </GlassPanel>
                        ))}
                    </div>
                </section>

                <section className="py-24">
                    <GlassPanel
                        variant="panel"
                        className="relative overflow-hidden px-8 py-16 text-center sm:px-16"
                    >
                        <div
                            aria-hidden
                            className="pointer-events-none absolute inset-0"
                            style={{
                                background:
                                    'radial-gradient(ellipse 60% 80% at 50% 100%, rgba(239,68,68,0.18) 0%, transparent 70%)',
                            }}
                        />
                        <div className="relative">
                            <h2 className="text-3xl font-bold tracking-tight text-white sm:text-4xl">
                                Ready to ride into a hands-free garage?
                            </h2>
                            <p className="mx-auto mt-4 max-w-xl text-slate-300">
                                Sign up free and pair your device in minutes.
                            </p>
                            <div className="mt-8">
                                {auth.user ? (
                                    <Link
                                        href={route('dashboard')}
                                        className="rounded-wolf-pill bg-red-500 px-7 py-3 text-sm font-semibold text-white shadow-[0_12px_32px_rgba(239,68,68,0.4)] transition hover:bg-red-400"
                                    >
                                        Go to your dashboard
                                    </Link>
                                ) : (
                                    <Link
                                        href={route('register')}
                                        className="rounded-wolf-pill bg-red-500 px-7 py-3 text-sm font-semibold text-white shadow-[0_12px_32px_rgba(239,68,68,0.4)] transition hover:bg-red-400"
                                    >
                                        Create your account
                                    </Link>
                                )}
                            </div>
                        </div>
                    </GlassPanel>
                </section>

                <footer className="border-t border-white/5 py-8 text-center text-xs text-slate-500">
                    © {new Date().getFullYear()} IopenIt · Smart Garage for
                    riders
                </footer>
            </div>
        </StageBackground>
    );
}
