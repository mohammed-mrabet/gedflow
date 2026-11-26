import { Link } from '@inertiajs/react';
import type { Paginated } from '@/types';

export default function Pagination({ page }: { page: Paginated<unknown> }) {
    if (page.total === 0) return null;
    return (
        <div className="flex items-center justify-between gap-3 border-t border-ink-100 px-4 py-3 text-sm text-ink-500">
            <span>{page.from}–{page.to} sur {page.total}</span>
            <div className="flex items-center gap-2">
                {page.prev_page_url ? <Link className="btn btn-sm" href={page.prev_page_url} preserveScroll>← Précédent</Link> : <span className="btn btn-sm opacity-40">← Précédent</span>}
                <span>Page {page.current_page} / {page.last_page}</span>
                {page.next_page_url ? <Link className="btn btn-sm" href={page.next_page_url} preserveScroll>Suivant →</Link> : <span className="btn btn-sm opacity-40">Suivant →</span>}
            </div>
        </div>
    );
}
