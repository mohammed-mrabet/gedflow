<?php

namespace App\Models;

use App\Enums\TaskStatus;
use App\Services\ValidationRights;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

#[Fillable(['document_id', 'document_version_id', 'round', 'position', 'step_name', 'department_id', 'status', 'due_at'])]
class ReviewTask extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'due_at' => 'datetime',
            'decided_at' => 'datetime',
            'reminded_at' => 'datetime',
            'escalated_at' => 'datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'document_version_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isOverdue(): bool
    {
        return $this->status === TaskStatus::Pending && $this->due_at->isPast();
    }

    /**
     * Tâches en attente que cet utilisateur peut traiter : étapes de son service (s'il est valideur)
     * ou du service d'un valideur absent qui lui a délégué ses visas ; jamais sur ses propres documents.
     */
    public function scopeActionableBy(Builder $query, User $user): Builder
    {
        $departments = ValidationRights::departmentsFor($user);
        if ($departments->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('status', TaskStatus::Pending)
            ->whereIn('department_id', $departments)
            ->whereHas('document', fn (Builder $q) => $q->where('owner_id', '!=', $user->id))
            // Pas d'étape d'un tour où l'utilisateur a déjà visé une autre étape (règle des quatre yeux).
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('review_tasks as done')
                ->whereColumn('done.document_id', 'review_tasks.document_id')
                ->whereColumn('done.round', 'review_tasks.round')
                ->where('done.decided_by', $user->id));
    }

    public function onBehalfOf(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_on_behalf_of');
    }
}
