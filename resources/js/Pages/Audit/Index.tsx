import { Link, router } from '@inertiajs/react';
import { Download } from 'lucide-react';
import { useState } from 'react';
import Layout from '@/Components/Layout';
import Pagination from '@/Components/Pagination';
import type { Activity, Option, Paginated } from '@/types';
import { dateTime } from '@/lib/format';

export default function Index({ entries, filters, actions }: { entries: Paginated<Activity>; filters: { action?: string; q?: string }; actions: Option[] }) {
    const [q, setQ] = useState(filters.q ?? '');
    const [action, setAction] = useState(filters.action ?? '');
    const params = new URLSearchParams(Object.entries({ q, action }).filter(([, v]) => v) as [string, string][]).toString();

    return (
        <Layout title="Journal d'audit" actions={<a className="btn" href={`/admin/journal/export${params ? `?${params}` : ''}`}><Download className="size-4" /> Exporter en CSV</a>}>
            <section className="card">
                <form className="flex flex-wrap items-end gap-3 border-b border-ink-100 p-4" onSubmit={(e) => { e.preventDefault(); router.get('/admin/journal', Object.fromEntries(new URLSearchParams(params)), { preserveState: true }); }}>
                    <label className="field min-w-56 flex-1">Utilisateur ou référence<input className="input" value={q} onChange={(e) => setQ(e.target.value)} /></label>
                    <label className="field">Action
                        <select className="input" value={action} onChange={(e) => setAction(e.target.value)}>
                            <option value="">Toutes</option>
                            {actions.map((a) => <option key={a.value} value={a.value}>{a.label}</option>)}
                        </select>
                    </label>
                    <button className="btn">Filtrer</button>
                </form>
                <table className="w-full">
                    <thead className="bg-ink-50/60"><tr><th className="th">Date</th><th className="th">Utilisateur</th><th className="th">Action</th><th className="th">Document</th><th className="th">Détails</th><th className="th">Adresse IP</th></tr></thead>
                    <tbody className="divide-y divide-ink-100">
                        {entries.data.map((e) => (
                            <tr key={e.id}>
                                <td className="td whitespace-nowrap text-ink-600">{dateTime(e.createdAt)}</td>
                                <td className="td">{e.user ?? 'Système'}</td>
                                <td className="td font-medium">{e.label}</td>
                                <td className="td">{e.document && <Link className="mono text-amber-brand-dark hover:underline" href={`/documents/${e.document.id}`}>{e.document.reference}</Link>}</td>
                                <td className="td max-w-md truncate text-xs text-ink-500" title={e.details ? JSON.stringify(e.details) : ''}>{e.details ? Object.entries(e.details).map(([k, v]) => `${k} : ${String(v)}`).join(' · ') : ''}</td>
                                <td className="td mono text-ink-500">{e.ip}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
                <Pagination page={entries} />
            </section>
        </Layout>
    );
}
