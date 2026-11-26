import { Head, Link, router, usePage } from '@inertiajs/react';
import { BarChart3, Bell, CalendarOff, ClipboardCheck, FileText, FolderOpen, KeyRound, LayoutDashboard, LogOut, Plus, ScrollText, Search, Workflow } from 'lucide-react';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import type { SharedProps } from '@/types';
import { cx, relative } from '@/lib/format';

export default function Layout({ title, children, actions }: { title: string; children: ReactNode; actions?: ReactNode }) {
    const { auth, counters, notifications, flash, nav: navFlags } = usePage<SharedProps>().props;
    const user = auth!.user;
    const url = usePage().url;
    const [q, setQ] = useState('');
    const [bellOpen, setBellOpen] = useState(false);
    const bellRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const close = (e: MouseEvent) => bellRef.current && !bellRef.current.contains(e.target as Node) && setBellOpen(false);
        document.addEventListener('click', close);
        return () => document.removeEventListener('click', close);
    }, []);

    const nav = [
        { href: '/', label: 'Tableau de bord', icon: LayoutDashboard, active: url === '/' },
        { href: '/documents', label: 'Documents', icon: FolderOpen, active: url.startsWith('/documents') && !url.startsWith('/documents/nouveau') },
        { href: '/validations', label: 'À valider', icon: ClipboardCheck, active: url.startsWith('/validations'), count: counters?.tasks },
        ...(navFlags?.statistics ? [{ href: '/statistiques', label: 'Statistiques', icon: BarChart3, active: url.startsWith('/statistiques') }] : []),
        ...(navFlags?.delegations ? [{ href: '/absences', label: 'Absences et délégations', icon: CalendarOff, active: url.startsWith('/absences') }] : []),
        { href: '/profil/api', label: 'Accès API', icon: KeyRound, active: url.startsWith('/profil/api') },
    ];
    const admin = [
        { href: '/admin/circuits', label: 'Circuits de validation', icon: Workflow, active: url.startsWith('/admin/circuits') },
        { href: '/admin/journal', label: "Journal d'audit", icon: ScrollText, active: url.startsWith('/admin/journal') },
    ];

    return (
        <div className="min-h-screen bg-paper lg:grid lg:grid-cols-[248px_1fr] lg:bg-[linear-gradient(to_right,var(--color-ink-900)_248px,var(--color-paper)_248px)]">
            <Head title={title} />
            <aside className="flex flex-col gap-6 bg-ink-900 px-4 py-5 text-ink-200 lg:sticky lg:top-0 lg:h-screen">
                <Link href="/" className="flex items-center gap-3 px-2 text-white">
                    <span className="grid size-9 place-items-center rounded-lg bg-white/10"><FileText className="size-5 text-amber-300" /></span>
                    <span className="leading-tight"><strong className="block text-[17px] font-semibold">GedFlow</strong><small className="text-xs text-ink-400">Documents & validations</small></span>
                </Link>
                {user.role !== 'reader' && (
                    <Link href="/documents/nouveau" className="btn btn-primary w-full"><Plus className="size-4" /> Déposer un document</Link>
                )}
                <nav className="flex flex-col gap-0.5">
                    {nav.map((item) => <NavItem key={item.href} {...item} />)}
                    {user.role === 'admin' && (
                        <>
                            <p className="mt-5 mb-1 px-3 text-[11px] font-semibold tracking-widest text-ink-500 uppercase">Administration</p>
                            {admin.map((item) => <NavItem key={item.href} {...item} />)}
                        </>
                    )}
                </nav>
                <div className="mt-auto border-t border-white/10 pt-4">
                    <p className="px-2 text-sm font-semibold text-white">{user.name}</p>
                    <p className="px-2 text-xs text-ink-400">{user.roleLabel}{user.department ? ` · ${user.department}` : ''}</p>
                    <button onClick={() => router.post('/logout')} className="mt-3 flex items-center gap-2 px-2 text-xs text-ink-400 hover:text-white">
                        <LogOut className="size-3.5" /> Se déconnecter
                    </button>
                </div>
            </aside>

            <div className="min-w-0 bg-paper">
                <header className="sticky top-0 z-20 flex items-center gap-3 border-b border-ink-100 bg-white/90 px-6 py-3 backdrop-blur">
                    <form className="relative max-w-lg flex-1" onSubmit={(e) => { e.preventDefault(); router.get('/documents', { q }); }}>
                        <Search className="pointer-events-none absolute top-2.5 left-3 size-4 text-ink-400" />
                        <input className="input pl-9" placeholder="Rechercher dans les documents (titre, référence, contenu)…" value={q} onChange={(e) => setQ(e.target.value)} aria-label="Recherche" />
                    </form>
                    <div className="relative ml-auto" ref={bellRef}>
                        <button className="btn btn-ghost relative" onClick={() => setBellOpen((o) => !o)} aria-label="Notifications">
                            <Bell className="size-5" />
                            {!!counters?.notifications && <span className="absolute top-0.5 right-0.5 grid size-4 place-items-center rounded-full bg-red-600 text-[10px] font-bold text-white">{counters.notifications}</span>}
                        </button>
                        {bellOpen && (
                            <div className="card absolute right-0 mt-2 w-96 overflow-hidden">
                                <div className="flex items-center justify-between border-b border-ink-100 px-4 py-2.5">
                                    <strong className="text-sm">Notifications</strong>
                                    {!!counters?.notifications && <button className="text-xs text-amber-brand-dark hover:underline" onClick={() => router.post('/notifications/lues', {}, { preserveScroll: true })}>Tout marquer comme lu</button>}
                                </div>
                                {notifications.length === 0 ? <p className="px-4 py-6 text-center text-sm text-ink-500">Aucune notification.</p> : (
                                    <ul className="max-h-96 overflow-y-auto">
                                        {notifications.map((n) => (
                                            <li key={n.id}>
                                                <Link href={n.documentId ? `/documents/${n.documentId}` : '/'} className={cx('block border-b border-ink-50 px-4 py-2.5 text-sm hover:bg-ink-50', !n.read && 'bg-amber-50/60')}>
                                                    <span className="block">{n.message}</span>
                                                    <small className="text-xs text-ink-400">{relative(n.createdAt)}</small>
                                                </Link>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </div>
                        )}
                    </div>
                </header>

                <main className="mx-auto max-w-7xl px-6 py-7">
                    <div className="mb-6 flex flex-wrap items-end justify-between gap-4">
                        <h1 className="text-2xl font-semibold tracking-tight text-ink-900">{title}</h1>
                        {actions && <div className="flex flex-wrap gap-2">{actions}</div>}
                    </div>
                    {navFlags?.activeDelegation && (
                        <p className="mb-5 rounded-lg border border-violet-200 bg-violet-50 px-4 py-3 text-sm text-violet-900">
                            Vous remplacez <strong>{navFlags.activeDelegation}</strong> : ses étapes de validation apparaissent dans « À valider ».
                        </p>
                    )}
                    {flash.success && <p className="mb-5 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">{flash.success}</p>}
                    {flash.error && <p className="mb-5 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800">{flash.error}</p>}
                    {children}
                </main>
            </div>
        </div>
    );
}

function NavItem({ href, label, icon: Icon, active, count }: { href: string; label: string; icon: typeof Bell; active: boolean; count?: number }) {
    return (
        <Link href={href} className={cx('flex items-center gap-3 rounded-lg px-3 py-2 text-sm', active ? 'bg-white text-ink-900 font-semibold' : 'text-ink-200 hover:bg-white/10')}>
            <Icon className="size-4" />
            <span className="flex-1">{label}</span>
            {!!count && <span className={cx('rounded-full px-2 py-0.5 text-[11px] font-bold', active ? 'bg-amber-brand text-white' : 'bg-amber-400 text-ink-900')}>{count}</span>}
        </Link>
    );
}
