import { Link } from '@inertiajs/react';
import Layout from '@/Components/Layout';
import { TaskBadge } from '@/Components/Badges';
import type { Task } from '@/types';
import { dateTime, relative } from '@/lib/format';

export default function Index({ tasks, done }: { tasks: Task[]; done: Task[] }) {
    return (
        <Layout title="À valider">
            <section className="card">
                <div className="border-b border-ink-100 px-5 py-3.5">
                    <h2 className="font-semibold">Documents en attente de votre service</h2>
                    <p className="text-sm text-ink-500">Triés par échéance. Vous ne voyez jamais vos propres documents : un autre valideur doit les viser.</p>
                </div>
                {tasks.length === 0 ? (
                    <p className="px-5 py-12 text-center text-sm text-ink-500">Rien à valider pour le moment. 🎉</p>
                ) : (
                    <table className="w-full">
                        <thead className="bg-ink-50/60"><tr><th className="th">Document</th><th className="th">Étape</th><th className="th">Déposé par</th><th className="th">Échéance</th><th className="th" /></tr></thead>
                        <tbody className="divide-y divide-ink-100">
                            {tasks.map((t) => (
                                <tr key={t.id} className={t.overdue ? 'bg-red-50/40' : ''}>
                                    <td className="td">
                                        <Link href={`/documents/${t.document!.id}`} className="font-medium hover:text-amber-brand-dark">{t.document!.title}</Link>
                                        <p className="text-xs text-ink-500"><span className="mono">{t.document!.reference}</span> · {t.document!.type} · version {t.version}</p>
                                    </td>
                                    <td className="td">
                                        {t.stepName}{t.round > 1 && <span className="ml-1 text-xs text-ink-500">(tour {t.round})</span>}
                                        {t.delegatedFrom && <p className="mt-0.5 text-xs font-medium text-violet-700">Pour le compte de {t.delegatedFrom}</p>}
                                        {t.escalated && <p className="mt-0.5 text-xs font-medium text-red-700">Escaladé au responsable</p>}
                                    </td>
                                    <td className="td text-ink-600">{t.document!.owner}</td>
                                    <td className="td whitespace-nowrap"><TaskBadge status={t.status} label={t.statusLabel} overdue={t.overdue} /><p className="mt-1 text-xs text-ink-500">{dateTime(t.dueAt)} · {relative(t.dueAt)}</p></td>
                                    <td className="td text-right"><Link href={`/documents/${t.document!.id}`} className="btn btn-primary btn-sm">Examiner</Link></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </section>

            {done.length > 0 && (
                <section className="card mt-6">
                    <h2 className="border-b border-ink-100 px-5 py-3.5 font-semibold">Mes dernières décisions</h2>
                    <ul className="divide-y divide-ink-100">
                        {done.map((t) => (
                            <li key={t.id} className="flex items-center gap-4 px-5 py-3 text-sm">
                                <TaskBadge status={t.status} label={t.statusLabel} />
                                <Link href={`/documents/${t.document!.id}`} className="flex-1 hover:text-amber-brand-dark"><span className="mono text-ink-500">{t.document!.reference}</span> {t.document!.title} · {t.stepName}{t.onBehalfOf && <span className="text-violet-700"> · pour {t.onBehalfOf}</span>}</Link>
                                <span className="text-xs text-ink-500">{dateTime(t.decidedAt)}</span>
                            </li>
                        ))}
                    </ul>
                </section>
            )}
        </Layout>
    );
}
