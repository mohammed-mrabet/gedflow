import { Link } from '@inertiajs/react';
import type { Activity } from '@/types';
import { dateTime } from '@/lib/format';

const tone: Record<string, string> = {
    approved: 'bg-emerald-500', step_approved: 'bg-emerald-400', rejected: 'bg-red-500', submitted: 'bg-sky-500',
    withdrawn: 'bg-ink-400', downloaded: 'bg-ink-300', archived: 'bg-stone-400', new_version: 'bg-amber-brand', integrity_checked: 'bg-violet-500',
};

function detail(a: Activity): string | null {
    const d = a.details ?? {};
    if (a.action === 'rejected' || a.action === 'step_approved') return [d.step, d.comment && `« ${d.comment} »`].filter(Boolean).join(' — ') || null;
    if (a.action === 'new_version' || a.action === 'version_uploaded') return `v${d.version} · ${d.file}`;
    if (a.action === 'downloaded') return `version ${d.version}`;
    if (a.action === 'integrity_checked') return `version ${d.version} : ${d.intact ? 'intègre' : 'ALTÉRÉE'}`;
    if (a.action === 'shared' || a.action === 'unshared') return String(d.with ?? '');
    if (a.action === 'submitted') return `tour ${d.round}`;
    return null;
}

export default function ActivityList({ items, showDocument = false }: { items: Activity[]; showDocument?: boolean }) {
    if (items.length === 0) return <p className="px-5 py-6 text-sm text-ink-500">Aucune activité.</p>;
    return (
        <ul className="divide-y divide-ink-50">
            {items.map((a) => (
                <li key={a.id} className="flex items-start gap-3 px-5 py-2.5 text-sm">
                    <span className={`mt-1.5 size-2 shrink-0 rounded-full ${tone[a.action] ?? 'bg-ink-300'}`} />
                    <div className="min-w-0 flex-1">
                        <span className="font-medium">{a.label}</span>
                        {showDocument && a.document && <> · <Link className="mono text-amber-brand-dark hover:underline" href={`/documents/${a.document.id}`}>{a.document.reference}</Link></>}
                        {detail(a) && <span className="text-ink-500"> — {detail(a)}</span>}
                        <p className="text-xs text-ink-400">{a.user ?? 'Système'} · {dateTime(a.createdAt)}</p>
                    </div>
                </li>
            ))}
        </ul>
    );
}
