import { router } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';
import Layout from '@/Components/Layout';
import { cx } from '@/lib/format';

interface StepStat { step: string; department: string; count: number; avgHours: number; medianHours: number; onTimeRate: number | null; rejectionRate: number | null }
interface DeptStat { department: string; decided: number; avgHours: number | null; pending: number; overdue: number; oldestPendingHours: number | null }
interface Props {
    period: number;
    summary: { decisions: number; avgStepHours: number | null; onTimeRate: number | null; rejectionRate: number | null; avgCycleHours: number | null; delegatedRate: number | null; pending: number; overdue: number };
    steps: StepStat[];
    departments: DeptStat[];
    bottleneck: DeptStat | null;
    types: { type: string; documents: number; rejectionRate: number | null }[];
    monthly: { month: string; approved: number; rejected: number }[];
}

const duration = (h: number | null) => (h === null ? '—' : h < 48 ? `${h.toLocaleString('fr-FR')} h` : `${(h / 24).toLocaleString('fr-FR', { maximumFractionDigits: 1 })} j`);
const pct = (v: number | null) => (v === null ? '—' : `${v.toLocaleString('fr-FR')} %`);

export default function Statistics({ period, summary, steps, departments, bottleneck, types, monthly }: Props) {
    const maxStep = Math.max(1, ...steps.map((s) => s.avgHours));
    const maxMonth = Math.max(1, ...monthly.map((m) => m.approved + m.rejected));

    return (
        <Layout
            title="Statistiques des circuits"
            actions={
                <select className="input w-48" value={period} onChange={(e) => router.get('/statistiques', { period: e.target.value }, { preserveScroll: true })} aria-label="Période">
                    {[30, 90, 180, 365].map((d) => <option key={d} value={d}>{d === 365 ? '12 derniers mois' : `${d} derniers jours`}</option>)}
                </select>
            }
        >
            <section className="grid grid-cols-2 gap-4 lg:grid-cols-6">
                <Kpi label="Visas et rejets" value={String(summary.decisions)} />
                <Kpi label="Délai moyen d'une étape" value={duration(summary.avgStepHours)} />
                <Kpi label="Dans les délais" value={pct(summary.onTimeRate)} tone={summary.onTimeRate !== null && summary.onTimeRate < 80 ? 'warn' : 'ok'} />
                <Kpi label="Taux de rejet" value={pct(summary.rejectionRate)} />
                <Kpi label="Cycle complet moyen" value={duration(summary.avgCycleHours)} hint="de la soumission à l'approbation" />
                <Kpi label="Visas par délégation" value={pct(summary.delegatedRate)} />
            </section>

            {bottleneck && (bottleneck.overdue > 0 || (bottleneck.avgHours ?? 0) > 24) && (
                <div className="mt-6 flex gap-3 rounded-xl border border-amber-300 bg-amber-50 px-5 py-4 text-sm text-amber-950">
                    <AlertTriangle className="size-5 shrink-0 text-amber-600" />
                    <p><strong>Goulot d'étranglement : {bottleneck.department}.</strong> Délai moyen de {duration(bottleneck.avgHours)} par étape, {bottleneck.pending} étape(s) en attente dont {bottleneck.overdue} en retard.
                        {' '}Pistes : renforcer l'équipe de valideurs, organiser des délégations pendant les absences ou revoir le délai de l'étape.</p>
                </div>
            )}

            <div className="mt-6 grid gap-6 xl:grid-cols-[1.4fr_1fr]">
                <section className="card">
                    <h2 className="border-b border-ink-100 px-5 py-3.5 font-semibold">Délai moyen par étape</h2>
                    <ul className="divide-y divide-ink-50">
                        {steps.map((s) => (
                            <li key={s.step + s.department} className="grid grid-cols-[1fr_auto] gap-x-4 gap-y-1.5 px-5 py-3 text-sm">
                                <span><strong>{s.step}</strong> <span className="text-ink-500">· {s.department} · {s.count} décision(s)</span></span>
                                <span className="text-right font-semibold">{duration(s.avgHours)} <span className="font-normal text-ink-500">(médiane {duration(s.medianHours)})</span></span>
                                <span className="h-2 rounded-full bg-ink-100"><span className={cx('block h-2 rounded-full', (s.onTimeRate ?? 100) < 70 ? 'bg-red-500' : 'bg-amber-brand')} style={{ width: `${(s.avgHours / maxStep) * 100}%` }} /></span>
                                <span className="text-right text-xs text-ink-500">{pct(s.onTimeRate)} dans les délais · {pct(s.rejectionRate)} de rejets</span>
                            </li>
                        ))}
                        {steps.length === 0 && <li className="px-5 py-8 text-center text-sm text-ink-500">Aucune décision sur la période.</li>}
                    </ul>
                </section>

                <div className="space-y-6">
                    <section className="card card-pad">
                        <h2 className="mb-4 font-semibold">Décisions par mois</h2>
                        <div className="flex h-44 items-end gap-3">
                            {monthly.map((m) => (
                                <div key={m.month} className="flex h-full flex-1 flex-col items-center justify-end gap-1">
                                    <span className="text-[11px] text-ink-500">{m.approved + m.rejected || ''}</span>
                                    <div className="flex w-full max-w-10 flex-col justify-end overflow-hidden rounded-t-md" style={{ height: `${((m.approved + m.rejected) / maxMonth) * 100}%` }}>
                                        <span className="bg-red-400" style={{ flex: m.rejected }} title={`${m.rejected} rejet(s)`} />
                                        <span className="bg-emerald-500" style={{ flex: m.approved }} title={`${m.approved} visa(s)`} />
                                    </div>
                                    <span className="text-[11px] text-ink-500 capitalize">{m.month}</span>
                                </div>
                            ))}
                        </div>
                        <p className="mt-3 flex gap-4 text-xs text-ink-500"><span className="flex items-center gap-1.5"><i className="size-2.5 rounded-sm bg-emerald-500" /> visas</span><span className="flex items-center gap-1.5"><i className="size-2.5 rounded-sm bg-red-400" /> rejets</span></p>
                    </section>

                    <section className="card card-pad">
                        <h2 className="mb-3 font-semibold">Taux de rejet par type</h2>
                        <ul className="space-y-2 text-sm">
                            {types.map((t) => (
                                <li key={t.type} className="flex justify-between"><span>{t.type} <span className="text-ink-500">({t.documents} doc.)</span></span><strong>{pct(t.rejectionRate)}</strong></li>
                            ))}
                        </ul>
                    </section>
                </div>
            </div>

            <section className="card mt-6">
                <h2 className="border-b border-ink-100 px-5 py-3.5 font-semibold">Charge par service (aujourd'hui)</h2>
                <table className="w-full">
                    <thead className="bg-ink-50/60"><tr><th className="th">Service</th><th className="th">Décisions sur la période</th><th className="th">Délai moyen</th><th className="th">En attente</th><th className="th">En retard</th><th className="th">Plus ancienne attente</th></tr></thead>
                    <tbody className="divide-y divide-ink-100">
                        {departments.map((d) => (
                            <tr key={d.department} className={d.department === bottleneck?.department ? 'bg-amber-50/50' : ''}>
                                <td className="td font-medium">{d.department}</td>
                                <td className="td">{d.decided}</td>
                                <td className="td">{duration(d.avgHours)}</td>
                                <td className="td">{d.pending}</td>
                                <td className={cx('td', d.overdue > 0 && 'font-semibold text-red-700')}>{d.overdue}</td>
                                <td className="td">{duration(d.oldestPendingHours)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </section>
        </Layout>
    );
}

function Kpi({ label, value, hint, tone }: { label: string; value: string; hint?: string; tone?: 'ok' | 'warn' }) {
    return (
        <div className="card card-pad">
            <p className="text-xs font-medium text-ink-500">{label}</p>
            <p className={cx('mt-1 text-2xl font-semibold tracking-tight', tone === 'warn' ? 'text-amber-brand-dark' : 'text-ink-900')}>{value}</p>
            {hint && <p className="text-[11px] text-ink-400">{hint}</p>}
        </div>
    );
}
