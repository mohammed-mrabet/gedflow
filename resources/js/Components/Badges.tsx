import type { DocumentStatus, TaskStatus } from '@/types';
import { cx } from '@/lib/format';

const statusStyle: Record<DocumentStatus, string> = {
    draft: 'bg-ink-100 text-ink-700',
    in_review: 'bg-sky-50 text-sky-800 ring-1 ring-sky-200',
    approved: 'bg-emerald-50 text-emerald-800 ring-1 ring-emerald-200',
    rejected: 'bg-red-50 text-red-800 ring-1 ring-red-200',
    archived: 'bg-stone-100 text-stone-600',
};

export function StatusBadge({ status, label }: { status: DocumentStatus; label: string }) {
    return <span className={cx('inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold whitespace-nowrap', statusStyle[status])}>{label}</span>;
}

const taskStyle: Record<TaskStatus, string> = {
    pending: 'bg-amber-50 text-amber-800 ring-1 ring-amber-200',
    approved: 'bg-emerald-50 text-emerald-800',
    rejected: 'bg-red-50 text-red-800',
    cancelled: 'bg-ink-100 text-ink-500',
};

export function TaskBadge({ status, label, overdue }: { status: TaskStatus; label: string; overdue?: boolean }) {
    if (overdue) return <span className="inline-flex rounded-full bg-red-600 px-2.5 py-0.5 text-xs font-semibold text-white">En retard</span>;
    return <span className={cx('inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold', taskStyle[status])}>{label}</span>;
}

export function ConfidentialityBadge({ value, label }: { value: string; label: string }) {
    const style = value === 'restricted' ? 'text-red-700 border-red-200' : value === 'department' ? 'text-ink-700 border-ink-200' : 'text-ink-500 border-ink-200';
    return <span className={cx('inline-flex items-center gap-1 rounded-md border px-2 py-0.5 text-xs font-medium whitespace-nowrap', style)}>{value === 'restricted' && '🔒 '}{label}</span>;
}

export function Tags({ tags }: { tags: string[] }) {
    return (
        <span className="flex flex-wrap gap-1">
            {tags.map((t) => (
                <span key={t} className="rounded bg-paper px-1.5 py-0.5 text-[11px] font-medium text-amber-brand-dark ring-1 ring-amber-brand/20">#{t}</span>
            ))}
        </span>
    );
}
