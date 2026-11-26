<?php

namespace App\Support;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/** Filtres de la liste des documents, communs à l'interface et à l'API (mêmes règles, mêmes droits). */
final class DocumentFilters
{
    public static function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', 'integer'],
            'department' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::enum(DocumentStatus::class)],
            'mine' => ['nullable', 'boolean'],
            'retention' => ['nullable', 'boolean'],
        ];
    }

    public static function query(array $filters, User $user): Builder
    {
        return Document::visibleTo($user)
            ->with(['type', 'department', 'owner', 'currentVersion'])
            ->search($filters['q'] ?? null)
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('document_type_id', $v))
            ->when($filters['department'] ?? null, fn ($q, $v) => $q->where('department_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['mine'] ?? false, fn ($q) => $q->where('owner_id', $user->id))
            ->when($filters['retention'] ?? false, fn ($q) => $q->whereNotNull('retention_until')->where('retention_until', '<', now()->toDateString()))
            ->orderByDesc('updated_at')->orderByDesc('id');
    }

    /** Extrait du contenu autour du premier mot recherché, pour montrer pourquoi le document correspond. */
    public static function excerpt(Document $document, string $terms): ?string
    {
        $text = (string) $document->currentVersion?->extracted_text;
        foreach (preg_split('/\s+/u', trim($terms)) as $word) {
            $pos = $word === '' ? false : mb_stripos($text, $word);
            if ($pos === false) {
                continue;
            }
            $start = max(0, $pos - 70);
            if ($start > 0) { // commencer l'extrait au début d'un mot
                $space = mb_strpos($text, ' ', $start);
                $start = $space !== false && $space < $pos ? $space + 1 : $start;
            }
            $more = $start + 180 < mb_strlen($text);
            $excerpt = mb_substr($text, $start, 180);
            $end = mb_strrpos($excerpt, ' ');
            $excerpt = $more && $end !== false ? mb_substr($excerpt, 0, $end) : $excerpt;

            return ($start > 0 ? '… ' : '').trim($excerpt).($more ? ' …' : '');
        }

        return null;
    }
}
