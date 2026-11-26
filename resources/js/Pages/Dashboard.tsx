import { Link } from '@inertiajs/react';
import Layout from '@/Components/Layout';
import { TaskBadge } from '@/Components/Badges';
import ActivityList from '@/Components/ActivityList';
import type { Activity, DocumentStatus, Task } from '@/types';
import { relative } from '@/lib/format';

interface Props {
    stats: { total: number; inReview: number; myTasks: number; overdue: number; approvedThisMonth: number; myDrafts: number };
    statusCounts: { status: DocumentStatus; label: string; total: number }[];
    departments: { name: string; total: number }[];
    tasks: Task[];
    activity: Activity[];
}

const barColor: Record<DocumentStatus, string> = {
    draft: 'bg-ink-400', in_review: 'bg-sky-500', approved: 'bg-emerald-500', rejected: 'bg-red-500', archived: 'bg-stone-400',
};

export default function Dashboard({ stats, statusCounts, departments, tasks, activity }: Props) {
    const maxDept = Math.max(1, ...departments.map((d) => d.total));

    return (
        <Layout title="Tableau de bord">
            <section className="grid grid-cols-2 gap-4 lg:grid-cols-5">
                <Kpi label="Documents accessibles" value={stats.total} />
                <Kpi label="En validation" value={stats.inReview} />
                <Kpi label="À valider par moi" value={stats.myTasks} accent href="/validations" />
                <Kpi label="Étapes en retard" value={stats.overdue} danger={stats.overdue > 0} />
                <Kpi label="Approuvés ce mois" value={stats.approvedThisMonth} />
            </section>

            <div className="mt-6 grid gap-6 lg:grid-cols-[1.35fr_1fr]">
                <section className="card">
                    <div className="flex items-center justify-between border-b border-ink-100 px-5 py-3.5">
                        <h2 className="font-semibold">Ma corbeille de validation</h2>
                        <Link href="/validations" className="text-sm text-amber-brand-dark hover:underline">Tout voir →</Link>
                    </div>
                    {tasks.length === 0 ? (
                        <p className="px-5 py-8 text-center text-sm text-ink-500">Aucun document n'attend votre visa.</p>
                    ) : (
                        <ul className="divide-y divide-ink-100">
                            {tasks.map((t) => (
                                <li key={t.id}>
                                    <Link href={`/documents/${t.document!.id}`} className="flex items-center gap-4 px-5 py-3 hover:bg-ink-50">
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate text-sm font-medium">{t.document!.title}</p>
                                            <p className="text-xs text-ink-500"><span className="mono">{t.document!.reference}</span> · {t.stepName}</p>
                                        </div>
                                        <div className="text-right">
                                            <TaskBadge status={t.status} label={t.statusLabel} overdue={t.overdue} />
                                            <p className="mt-1 text-xs text-ink-500">échéance {relative(t.dueAt)}</p>
                                        </div>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                    {stats.myDrafts > 0 && (
                        <p className="border-t border-ink-100 px-5 py-3 text-sm text-ink-600">
                            Vous avez <strong>{stats.myDrafts}</strong> brouillon(s) ou document(s) rejeté(s) à finaliser.{' '}
                            <Link href="/documents?mine=1" className="text-amber-brand-dark hover:underline">Voir mes documents</Link>
                        </p>
                    )}
                </section>

                <section className="card card-pad">
                    <h2 className="mb-4 font-semibold">Répartition par statut</h2>
                    <div className="flex h-3 overflow-hidden rounded-full bg-ink-100">
                        {statusCounts.filter((s) => s.total > 0).map((s) => (
                            <span key={s.status} className={barColor[s.status]} style={{ width: `${(s.total / Math.max(1, stats.total)) * 100}%` }} title={`${s.label} : ${s.total}`} />
                        ))}
                    </div>
                    <ul className="mt-4 grid grid-cols-2 gap-2 text-sm">
                        {statusCounts.map((s) => (
                            <li key={s.status}>
                                <Link href={`/documents?status=${s.status}`} className="flex items-center gap-2 rounded px-1 py-0.5 hover:bg-ink-50">
                                    <span className={`size-2.5 rounded-full ${barColor[s.status]}`} />
                                    <span className="flex-1 text-ink-600">{s.label}</span>
                                    <strong>{s.total}</strong>
                                </Link>
                            </li>
                        ))}
                    </ul>
                    <h2 className="mt-7 mb-3 font-semibold">Documents par service</h2>
                    <ul className="space-y-2.5">
                        {departments.map((d) => (
                            <li key={d.name} className="grid grid-cols-[140px_1fr_28px] items-center gap-3 text-sm">
                                <span className="truncate text-ink-600">{d.name}</span>
                                <span className="h-2 rounded-full bg-ink-100"><span className="block h-2 rounded-full bg-amber-brand" style={{ width: `${(d.total / maxDept) * 100}%` }} /></span>
                                <strong className="text-right">{d.total}</strong>
                            </li>
                        ))}
                    </ul>
                </section>
            </div>

            <section className="card mt-6">
                <h2 className="border-b border-ink-100 px-5 py-3.5 font-semibold">Activité récente</h2>
                <ActivityList items={activity} showDocument />
            </section>
        </Layout>
    );
}

function Kpi({ label, value, accent, danger, href }: { label: string; value: number; accent?: boolean; danger?: boolean; href?: string }) {
    const body = (
        <div className={`card card-pad h-full ${accent ? 'border-amber-brand/40 bg-amber-50/40' : ''}`}>
            <p className="text-xs font-medium text-ink-500">{label}</p>
            <p className={`mt-1 text-3xl font-semibold tracking-tight ${danger ? 'text-red-700' : accent ? 'text-amber-brand-dark' : 'text-ink-900'}`}>{value}</p>
        </div>
    );
    return href ? <Link href={href}>{body}</Link> : body;
}
