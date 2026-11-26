import { useForm } from '@inertiajs/react';
import { Upload } from 'lucide-react';
import { useState } from 'react';
import Layout from '@/Components/Layout';
import type { IdName, Option } from '@/types';
import { fileSize } from '@/lib/format';

interface Props {
    options: { departments: IdName[]; confidentialities: Option[] };
    defaultDepartment: number | null;
    types: { id: number; name: string; defaultConfidentiality: string; steps: string[] }[];
}

export default function Create({ options, defaultDepartment, types }: Props) {
    const form = useForm<{ title: string; description: string; document_type_id: string; department_id: string; confidentiality: string; tags: string[]; file: File | null; submit: boolean }>({
        title: '', description: '', document_type_id: '', department_id: defaultDepartment ? String(defaultDepartment) : '',
        confidentiality: 'department', tags: [], file: null, submit: false,
    });
    const [tagInput, setTagInput] = useState('');
    const type = types.find((t) => String(t.id) === form.data.document_type_id);

    const addTag = () => {
        const tag = tagInput.trim().toLowerCase();
        if (tag && !form.data.tags.includes(tag)) form.setData('tags', [...form.data.tags, tag]);
        setTagInput('');
    };
    const send = (submit: boolean) => { form.transform((d) => ({ ...d, submit })); form.post('/documents', { forceFormData: true }); };

    return (
        <Layout title="Déposer un document">
            <form className="grid gap-6 lg:grid-cols-[1.4fr_1fr]" onSubmit={(e) => { e.preventDefault(); send(false); }}>
                <section className="card card-pad space-y-4">
                    <label className="field">Titre
                        <input className="input" value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} placeholder="ex. Facture Atlas Bureautique – fournitures T1" />
                        {form.errors.title && <span className="error">{form.errors.title}</span>}
                    </label>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <label className="field">Type de document
                            <select className="input" value={form.data.document_type_id} onChange={(e) => {
                                const t = types.find((x) => String(x.id) === e.target.value);
                                form.setData({ ...form.data, document_type_id: e.target.value, confidentiality: t?.defaultConfidentiality ?? form.data.confidentiality });
                            }}>
                                <option value="">Choisir…</option>
                                {types.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
                            </select>
                            {form.errors.document_type_id && <span className="error">{form.errors.document_type_id}</span>}
                        </label>
                        <label className="field">Service émetteur
                            <select className="input" value={form.data.department_id} onChange={(e) => form.setData('department_id', e.target.value)}>
                                <option value="">Choisir…</option>
                                {options.departments.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
                            </select>
                            {form.errors.department_id && <span className="error">{form.errors.department_id}</span>}
                        </label>
                    </div>
                    <label className="field">Description <span className="font-normal text-ink-400">(facultatif)</span>
                        <textarea className="input min-h-24" value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />
                    </label>
                    <div className="field">Mots-clés
                        <div className="flex flex-wrap items-center gap-2 rounded-lg border border-ink-200 bg-white p-2">
                            {form.data.tags.map((t) => (
                                <button type="button" key={t} className="rounded bg-paper px-2 py-0.5 text-xs text-amber-brand-dark ring-1 ring-amber-brand/20" onClick={() => form.setData('tags', form.data.tags.filter((x) => x !== t))}>#{t} ✕</button>
                            ))}
                            <input className="min-w-32 flex-1 text-sm outline-none" value={tagInput} onChange={(e) => setTagInput(e.target.value)}
                                onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ',') { e.preventDefault(); addTag(); } }} onBlur={addTag} placeholder="Entrée pour ajouter" />
                        </div>
                    </div>
                    <fieldset className="field">Accès
                        <div className="grid gap-2 sm:grid-cols-3">
                            {options.confidentialities.map((c) => (
                                <label key={c.value} className={`cursor-pointer rounded-lg border p-3 text-sm ${form.data.confidentiality === c.value ? 'border-amber-brand bg-amber-50/50' : 'border-ink-200'}`}>
                                    <input type="radio" className="sr-only" checked={form.data.confidentiality === c.value} onChange={() => form.setData('confidentiality', c.value)} />
                                    <strong className="block">{c.label}</strong>
                                    <span className="text-xs font-normal text-ink-500">
                                        {c.value === 'internal' ? 'Tous les collaborateurs' : c.value === 'department' ? 'Votre service et les valideurs' : 'Vous, les valideurs et les personnes autorisées'}
                                    </span>
                                </label>
                            ))}
                        </div>
                    </fieldset>
                </section>

                <aside className="space-y-6">
                    <section className="card card-pad">
                        <label className="flex cursor-pointer flex-col items-center gap-2 rounded-xl border-2 border-dashed border-ink-200 px-4 py-8 text-center hover:border-amber-brand">
                            <Upload className="size-7 text-amber-brand" />
                            <span className="text-sm font-medium">{form.data.file ? form.data.file.name : 'Choisir un fichier'}</span>
                            <span className="text-xs text-ink-500">{form.data.file ? fileSize(form.data.file.size) : 'PDF, Word, Excel, texte ou image · 20 Mo maximum'}</span>
                            <input type="file" className="sr-only" accept=".pdf,.docx,.xlsx,.txt,.png,.jpg,.jpeg" onChange={(e) => form.setData('file', e.target.files?.[0] ?? null)} />
                        </label>
                        {form.errors.file && <p className="error mt-2">{form.errors.file}</p>}
                        {form.progress && <progress className="mt-3 w-full" value={form.progress.percentage} max={100} />}
                    </section>

                    <section className="card card-pad">
                        <h2 className="text-sm font-semibold">Circuit de validation</h2>
                        {type ? (
                            type.steps.length ? (
                                <ol className="mt-3 space-y-2">
                                    {type.steps.map((s, i) => <li key={i} className="flex gap-3 text-sm"><span className="grid size-6 shrink-0 place-items-center rounded-full bg-ink-100 text-xs font-semibold">{i + 1}</span>{s}</li>)}
                                </ol>
                            ) : <p className="mt-2 text-sm text-ink-500">Aucune étape : le document sera approuvé dès sa soumission.</p>
                        ) : <p className="mt-2 text-sm text-ink-500">Choisissez un type pour voir son circuit.</p>}
                    </section>

                    <div className="flex flex-col gap-2">
                        <button type="button" className="btn btn-primary" disabled={form.processing} onClick={() => send(true)}>Déposer et soumettre à validation</button>
                        <button className="btn" disabled={form.processing}>Enregistrer comme brouillon</button>
                    </div>
                </aside>
            </form>
        </Layout>
    );
}
