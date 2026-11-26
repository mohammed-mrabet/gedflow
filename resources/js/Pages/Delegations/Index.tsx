import { router, useForm } from '@inertiajs/react';
import Layout from '@/Components/Layout';
import { cx, date } from '@/lib/format';

interface Row { id: number; delegator: string; delegate: string; startsOn: string; endsOn: string; reason: string | null; state: 'active' | 'upcoming' | 'past' | 'cancelled'; mine: boolean; canCancel: boolean }

const stateLabel = { active: 'En cours', upcoming: 'À venir', past: 'Terminée', cancelled: 'Annulée' };
const stateStyle = { active: 'bg-violet-100 text-violet-800', upcoming: 'bg-sky-50 text-sky-800', past: 'bg-ink-100 text-ink-600', cancelled: 'bg-ink-100 text-ink-400 line-through' };

export default function Index({ delegations, canDelegate, candidates }: { delegations: Row[]; canDelegate: boolean; candidates: { id: number; name: string; department: string | null }[] }) {
    const today = new Date().toISOString().slice(0, 10);
    const form = useForm({ delegate_id: '', starts_on: today, ends_on: today, reason: '' });

    return (
        <Layout title="Absences et délégations">
            {canDelegate && (
                <form className="card card-pad mb-6" onSubmit={(e) => { e.preventDefault(); form.post('/absences', { preserveScroll: true, onSuccess: () => form.reset() }); }}>
                    <h2 className="font-semibold">Déclarer une absence</h2>
                    <p className="text-sm text-ink-500">Votre remplaçant pourra viser les étapes de votre service pendant cette période ; ses visas porteront la mention « pour le compte de » vous.</p>
                    <div className="mt-4 grid gap-3 md:grid-cols-[1.3fr_160px_160px_1fr_auto] md:items-end">
                        <label className="field">Remplaçant
                            <select className="input" value={form.data.delegate_id} onChange={(e) => form.setData('delegate_id', e.target.value)}>
                                <option value="">Choisir…</option>
                                {candidates.map((c) => <option key={c.id} value={c.id}>{c.name}{c.department ? ` (${c.department})` : ''}</option>)}
                            </select>
                        </label>
                        <label className="field">Du<input type="date" className="input" min={today} value={form.data.starts_on} onChange={(e) => form.setData('starts_on', e.target.value)} /></label>
                        <label className="field">Au<input type="date" className="input" min={form.data.starts_on} value={form.data.ends_on} onChange={(e) => form.setData('ends_on', e.target.value)} /></label>
                        <label className="field">Motif<input className="input" value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} placeholder="Congés, formation…" /></label>
                        <button className="btn btn-primary" disabled={form.processing}>Enregistrer</button>
                    </div>
                    {Object.values(form.errors).map((e) => <p key={e} className="error mt-2">{e}</p>)}
                </form>
            )}

            <section className="card">
                <h2 className="border-b border-ink-100 px-5 py-3.5 font-semibold">Délégations</h2>
                {delegations.length === 0 ? <p className="px-5 py-10 text-center text-sm text-ink-500">Aucune délégation.</p> : (
                    <table className="w-full">
                        <thead className="bg-ink-50/60"><tr><th className="th">Valideur absent</th><th className="th">Remplaçant</th><th className="th">Période</th><th className="th">Motif</th><th className="th">État</th><th className="th" /></tr></thead>
                        <tbody className="divide-y divide-ink-100">
                            {delegations.map((d) => (
                                <tr key={d.id}>
                                    <td className="td">{d.delegator}</td>
                                    <td className="td">{d.delegate}</td>
                                    <td className="td whitespace-nowrap">{date(d.startsOn)} → {date(d.endsOn)}</td>
                                    <td className="td text-ink-600">{d.reason}</td>
                                    <td className="td"><span className={cx('rounded-full px-2.5 py-0.5 text-xs font-semibold', stateStyle[d.state])}>{stateLabel[d.state]}</span></td>
                                    <td className="td text-right">{d.canCancel && <button className="btn btn-sm" onClick={() => router.post(`/absences/${d.id}/annuler`, {}, { preserveScroll: true })}>Annuler</button>}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </section>
        </Layout>
    );
}
