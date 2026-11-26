<?php

namespace App\Models;

use App\Enums\Confidentiality;
use App\Enums\DocumentStatus;
use App\Enums\TaskStatus;
use App\Services\ValidationRights;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

#[Fillable(['reference', 'title', 'description', 'document_type_id', 'department_id', 'owner_id', 'status', 'confidentiality', 'tags'])]
class Document extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'confidentiality' => Confidentiality::class,
            'tags' => 'array',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'archived_at' => 'datetime',
            'retention_until' => 'date',
            'certificate_generated_at' => 'datetime',
        ];
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class, 'document_type_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'current_version_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class)->orderByDesc('number');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(ReviewTask::class)->orderBy('round')->orderBy('position');
    }

    public function sharedWith(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'document_shares')->withPivot('shared_by')->withTimestamps();
    }

    public function activities(): HasMany
    {
        return $this->hasMany(ActivityLog::class)->latest('created_at')->latest('id');
    }

    public function pendingTask(): ?ReviewTask
    {
        return $this->tasks()->where('status', TaskStatus::Pending)->first();
    }

    public function isRetentionExpired(): bool
    {
        return $this->retention_until !== null && $this->retention_until->isPast();
    }

    /**
     * Documents que l'utilisateur a le droit de voir. Même règle que DocumentPolicy::view,
     * traduite en SQL pour filtrer les listes et la recherche sans charger les documents interdits.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($user) {
            $q->where('owner_id', $user->id)
                ->orWhere('confidentiality', Confidentiality::Internal)
                ->orWhere(fn (Builder $q) => $q
                    ->where('confidentiality', Confidentiality::Department)
                    ->where('department_id', $user->department_id))
                ->orWhereExists(fn ($sub) => $sub->select(DB::raw(1))->from('document_shares')
                    ->whereColumn('document_shares.document_id', 'documents.id')
                    ->where('document_shares.user_id', $user->id))
                // Les valideurs (et leurs remplaçants) voient les documents qui passent ou sont passés par leur service.
                ->when(ValidationRights::departmentsFor($user)->isNotEmpty(), fn (Builder $q) => $q
                    ->orWhereExists(fn ($sub) => $sub->select(DB::raw(1))->from('review_tasks')
                        ->whereColumn('review_tasks.document_id', 'documents.id')
                        ->whereIn('review_tasks.department_id', ValidationRights::departmentsFor($user))));
        });
    }

    /** Recherche plein texte : index GIN et dictionnaire français sur PostgreSQL, LIKE ailleurs (SQLite en développement). */
    public function scopeSearch(Builder $query, ?string $terms): Builder
    {
        $terms = trim((string) $terms);
        if ($terms === '') {
            return $query;
        }

        if ($query->getConnection()->getDriverName() === 'pgsql') {
            return $query->whereFullText('search_text', $terms, ['language' => 'french', 'mode' => 'websearch']);
        }

        foreach (preg_split('/\s+/u', mb_strtolower($terms)) as $word) {
            $query->where('search_text', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $word).'%');
        }

        return $query;
    }

    /** Recalcule le texte indexé à partir des métadonnées et du contenu de la version courante. */
    public function refreshSearchText(): void
    {
        $content = $this->currentVersion?->extracted_text ?? '';
        $this->forceFill(['search_text' => mb_strtolower(implode("\n", array_filter([
            $this->reference, $this->title, $this->description, implode(' ', $this->tags ?? []),
            $this->currentVersion?->original_name, $content,
        ])))])->saveQuietly();
    }
}
