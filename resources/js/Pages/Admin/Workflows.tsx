import { useForm } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Plus, Trash2 } from 'lucide-react';
import Layout from '@/Components/Layout';
import type { IdName } from '@/types';

type Step = { name: string; departmentId: number | null; dueHours: number };
type Type = { id: number; code: string; name: string; description: string | null; retentionYears: number; documentsCount: number; steps: Step[] };

export default function Workflows({ types, departments }: { types: Type[]; departments: IdName[] }) {
    return (
        <Layout title="Circuits de validation">
            <p className="-mt-3 mb-6 max-w-3xl text-sm text-ink-600">
                Chaque type de document a son circuit : des étapes successives, confiées aux valideurs d'un service, avec un délai de traitement.
                Une modification s'applique aux prochaines étapes ; l'historique des documents déjà traités n'est jamais réécrit.
            </p>
            <div className="grid max-w-5xl gap-6">
                {types.map((t) => <TypeCard key={t.id} type={t} departments={departments} />)}
            </div>
        </Layout>
    );
}

function TypeCard({ type, departments }: { type: Type; departments: IdName[] }) {
    const form = useForm({ retention_years: type.retentionYears, steps: type.steps.map((s) => ({ name: s.name, department_id: s.departmentId ?? '', due_hours: s.dueHours })) });
    const steps = form.data.steps;
    const update = (i: number, patch: Partial<(typeof steps)[number]>) => form.setData('steps', steps.map((s, j) => (j === i ? { ...s, ...patch } : s)));
    const move = (i: number, d: -1 | 1) => { const next = [...steps]; [next[i], next[i + d]] = [next[i + d], next[i]]; form.setData('steps', next); };

    return (
        <form className="card card-pad" onSubmit={(e) => { e.preventDefault(); { form.transform((d) => ({ ...d, steps: d.steps.map((s) => ({ ...s, department_id: s.department_id || null })) })); form.put(`/admin/circuits/${type.id}`, { preserveScroll: true }); }; }}>
            <div className="flex items-start justify-between gap-3">
                <div>
                    <h2 className="font-semibold">{type.name} <span className="mono ml-1 rounded bg-ink-100 px-1.5 py-0.5 text-xs">{type.code}</span></h2>
                    <p className="text-xs text-ink-500">{type.documentsCount} document(s)</p>
                </div>
                <label className="field w-40">Conservation (ans)
                    <input className="input" type="number" min={1} max={99} value={form.data.retention_years} onChange={(e) => form.setData('retention_years', Number(e.target.value))} />
                </label>
            </div>
            <ol className="mt-4 space-y-2">
                {steps.map((s, i) => (
                    <li key={i} className="grid grid-cols-[28px_1fr_220px_96px_auto] items-center gap-2">
                        <span className="grid size-7 place-items-center rounded-full bg-ink-900 text-xs font-bold text-white">{i + 1}</span>
                        <input className="input" value={s.name} onChange={(e) => update(i, { name: e.target.value })} aria-label="Nom de l'étape" />
                        <select className="input" value={s.department_id} onChange={(e) => update(i, { department_id: e.target.value ? Number(e.target.value) : '' })} aria-label="Service valideur">
                            <option value="">Service émetteur</option>
                            {departments.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
                        </select>
                        <label className="relative"><input className="input pr-6" type="number" min={1} value={s.due_hours} onChange={(e) => update(i, { due_hours: Number(e.target.value) })} aria-label="Délai en heures" /><span className="absolute top-2 right-2 text-xs text-ink-400">h</span></label>
                        <span className="flex">
                            <button type="button" className="btn btn-ghost btn-sm" disabled={i === 0} onClick={() => move(i, -1)} aria-label="Monter"><ArrowUp className="size-3.5" /></button>
                            <button type="button" className="btn btn-ghost btn-sm" disabled={i === steps.length - 1} onClick={() => move(i, 1)} aria-label="Descendre"><ArrowDown className="size-3.5" /></button>
                            <button type="button" className="btn btn-ghost btn-sm text-red-700" onClick={() => form.setData('steps', steps.filter((_, j) => j !== i))} aria-label="Supprimer"><Trash2 className="size-3.5" /></button>
                        </span>
                    </li>
                ))}
                {steps.length === 0 && <li className="text-sm text-ink-500">Aucune étape : approbation directe à la soumission.</li>}
            </ol>
            {Object.keys(form.errors).length > 0 && <p className="error mt-2">{Object.values(form.errors)[0]}</p>}
            <div className="mt-4 flex justify-between">
                <button type="button" className="btn btn-sm" disabled={steps.length >= 6} onClick={() => form.setData('steps', [...steps, { name: 'Nouvelle étape', department_id: '', due_hours: 48 }])}><Plus className="size-3.5" /> Ajouter une étape</button>
                <button className="btn btn-primary btn-sm" disabled={form.processing || !form.isDirty}>Enregistrer le circuit</button>
            </div>
        </form>
    );
}
