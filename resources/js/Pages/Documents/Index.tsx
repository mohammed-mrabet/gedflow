import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import Layout from '@/Components/Layout';
import Pagination from '@/Components/Pagination';
import { ConfidentialityBadge, StatusBadge, Tags } from '@/Components/Badges';
import type { DocumentRow, IdName, Option, Paginated } from '@/types';
import { dateTime } from '@/lib/format';

interface Props {
    documents: Paginated<DocumentRow>;
    filters: { q?: string; type?: string; department?: string; status?: string; mine?: string; retention?: string };
    options: { types: IdName[]; departments: IdName[]; statuses: Option[] };
}

export default function Index({ documents, filters, options }: Props) {
    const [form, setForm] = useState({
        q: filters.q ?? '', type: filters.type ?? '', department: filters.department ?? '', status: filters.status ?? '',
        mine: !!filters.mine, retention: !!filters.retention,
    });
    const apply = (next = form) => router.get('/documents', {
        ...Object.fromEntries(Object.entries({ q: next.q, type: next.type, department: next.department, status: next.status }).filter(([, v]) => v)),
        ...(next.mine ? { mine: 1 } : {}), ...(next.retention ? { retention: 1 } : {}),
    }, { preserveState: true, replace: true });
    const set = (patch: Partial<typeof form>) => { const next = { ...form, ...patch }; setForm(next); if (!('q' in patch)) apply(next); };

    return (
        <Layout title="Documents" actions={<Link href="/documents/nouveau" className="btn btn-primary">Déposer un document</Link>}>
            <section className="card">
                <form className="flex flex-wrap items-end gap-3 border-b border-ink-100 p-4" onSubmit={(e) => { e.preventDefault(); apply(); }}>
                    <label className="field min-w-64 flex-1">Recherche plein texte
                        <input className="input" value={form.q} onChange={(e) => set({ q: e.target.value })} placeholder="Mots du titre, de la référence ou du contenu" />
                    </label>
                    <Select label="Type" value={form.type} onChange={(v) => set({ type: v })} options={options.types.map((t) => ({ value: String(t.id), label: t.name }))} />
                    <Select label="Service" value={form.department} onChange={(v) => set({ department: v })} options={options.departments.map((d) => ({ value: String(d.id), label: d.name }))} />
                    <Select label="Statut" value={form.status} onChange={(v) => set({ status: v })} options={options.statuses} />
                    <button className="btn">Rechercher</button>
                    <div className="flex w-full gap-5 text-sm text-ink-700">
                        <label className="flex items-center gap-2"><input type="checkbox" checked={form.mine} onChange={(e) => set({ mine: e.target.checked })} /> Mes documents</label>
                        <label className="flex items-center gap-2"><input type="checkbox" checked={form.retention} onChange={(e) => set({ retention: e.target.checked })} /> Durée de conservation échue</label>
                    </div>
                </form>

                {documents.data.length === 0 ? (
                    <p className="px-5 py-12 text-center text-sm text-ink-500">Aucun document ne correspond à ces critères.</p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full">
                            <thead className="border-b border-ink-100 bg-ink-50/60">
                                <tr><th className="th">Référence</th><th className="th">Document</th><th className="th">Service</th><th className="th">Statut</th><th className="th">Accès</th><th className="th">Mis à jour</th></tr>
                            </thead>
                            <tbody className="divide-y divide-ink-100">
                                {documents.data.map((d) => (
                                    <tr key={d.id} className="hover:bg-ink-50/50">
                                        <td className="td mono whitespace-nowrap text-ink-600">{d.reference}</td>
                                        <td className="td">
                                            <Link href={`/documents/${d.id}`} className="font-medium text-ink-900 hover:text-amber-brand-dark">{d.title}</Link>
                                            <p className="mt-0.5 flex flex-wrap items-center gap-2 text-xs text-ink-500">{d.type} · v{d.version} · {d.owner} <Tags tags={d.tags} /></p>
                                            {d.excerpt && <p className="mt-1.5 max-w-2xl rounded bg-amber-50 px-2 py-1 text-xs text-ink-600 italic">{d.excerpt}</p>}
                                        </td>
                                        <td className="td text-ink-600">{d.department}</td>
                                        <td className="td">
                                            <StatusBadge status={d.status} label={d.statusLabel} />
                                            {d.retentionExpired && <p className="mt-1 text-xs font-medium text-red-700">Conservation échue</p>}
                                        </td>
                                        <td className="td"><ConfidentialityBadge value={d.confidentiality} label={d.confidentialityLabel} /></td>
                                        <td className="td whitespace-nowrap text-ink-500">{dateTime(d.updatedAt)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                <Pagination page={documents} />
            </section>
        </Layout>
    );
}

function Select({ label, value, onChange, options }: { label: string; value: string; onChange: (v: string) => void; options: Option[] }) {
    return (
        <label className="field">{label}
            <select className="input" value={value} onChange={(e) => onChange(e.target.value)}>
                <option value="">Tous</option>
                {options.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
            </select>
        </label>
    );
}
