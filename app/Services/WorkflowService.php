<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Enums\Role;
use App\Enums\TaskStatus;
use App\Jobs\GenerateCertificate;
use App\Models\Document;
use App\Models\ReviewTask;
use App\Models\User;
use App\Notifications\DocumentDecided;
use App\Notifications\ReviewEscalated;
use App\Notifications\ReviewOverdue;
use App\Notifications\ReviewRequested;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Circuit de validation séquentiel : chaque étape du type de document crée une tâche
 * pour les valideurs d'un service ; un rejet renvoie le document à son auteur,
 * qui dépose une nouvelle version et relance un nouveau tour de validation.
 */
class WorkflowService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function submit(Document $document, User $user): void
    {
        $notify = DB::transaction(function () use ($document, $user) {
            $document = Document::whereKey($document->id)->lockForUpdate()->firstOrFail();
            if (! $document->status->isEditable()) {
                throw ValidationException::withMessages(['document' => 'Ce document est déjà '.mb_strtolower($document->status->label()).'.']);
            }
            if ($document->current_version_id === null) {
                throw ValidationException::withMessages(['document' => 'Ajoutez un fichier avant de soumettre le document.']);
            }

            $document->forceFill([
                'status' => DocumentStatus::InReview,
                'review_round' => $document->review_round + 1,
                'submitted_at' => now(),
            ])->save();
            $this->audit->record('submitted', $document, $user, ['round' => $document->review_round]);

            $task = $this->openStep($document, 1);
            if ($task === null) {
                $this->approve($document, $user); // type sans circuit : approbation directe

                return null;
            }

            return $task;
        });

        if ($notify) {
            $this->notifyValidators($notify);
        }
    }

    /** L'auteur retire son document du circuit (pour le corriger) : la tâche en cours est annulée. */
    public function withdraw(Document $document, User $user): void
    {
        DB::transaction(function () use ($document, $user) {
            $document = Document::whereKey($document->id)->lockForUpdate()->firstOrFail();
            if ($document->status !== DocumentStatus::InReview) {
                throw ValidationException::withMessages(['document' => 'Seul un document en validation peut être retiré.']);
            }
            $document->tasks()->where('status', TaskStatus::Pending)->update(['status' => TaskStatus::Cancelled]);
            $document->forceFill(['status' => DocumentStatus::Draft])->save();
            $this->audit->record('withdrawn', $document, $user);
        });
    }

    public function decide(ReviewTask $task, User $user, bool $approve, ?string $comment): void
    {
        if (! $approve && blank($comment)) {
            throw ValidationException::withMessages(['comment' => 'Indiquez le motif du rejet : l\'auteur en a besoin pour corriger son document.']);
        }

        [$next, $finished] = DB::transaction(function () use ($task, $user, $approve, $comment) {
            // Verrou : deux valideurs du même service ne peuvent pas traiter la même étape en même temps.
            $task = ReviewTask::whereKey($task->id)->lockForUpdate()->firstOrFail();
            if ($task->status !== TaskStatus::Pending) {
                throw ValidationException::withMessages(['task' => 'Cette étape a déjà été traitée.']);
            }
            $document = $task->document()->lockForUpdate()->firstOrFail();

            $onBehalfOf = ValidationRights::onBehalfOf($user, $task);
            $task->forceFill([
                'status' => $approve ? TaskStatus::Approved : TaskStatus::Rejected,
                'decided_by' => $user->id,
                'decided_on_behalf_of' => $onBehalfOf?->id,
                'decided_at' => now(),
                'comment' => $comment,
            ])->save();
            $this->audit->record($approve ? 'step_approved' : 'rejected', $document, $user, array_filter([
                'step' => $task->step_name, 'round' => $task->round, 'comment' => $comment,
                'on_behalf_of' => $onBehalfOf?->name,
            ]));

            if (! $approve) {
                $document->forceFill(['status' => DocumentStatus::Rejected])->save();

                return [null, $document];
            }

            $next = $this->openStep($document, $task->position + 1);
            if ($next === null) {
                $this->approve($document, $user);

                return [null, $document];
            }

            return [$next, null];
        });

        if ($next) {
            $this->notifyValidators($next);
        }
        if ($finished) {
            $finished->owner->notify(new DocumentDecided($finished, $approve, $comment, $user));
        }
    }

    /**
     * Relance les valideurs (et leurs remplaçants) des étapes en retard, au plus une fois par jour,
     * puis escalade au responsable du service et aux administrateurs les étapes bloquées depuis trop longtemps.
     * Commande planifiée toutes les heures.
     *
     * @return array{reminded: int, escalated: int}
     */
    public function remindOverdue(): array
    {
        $overdue = ReviewTask::with(['document', 'department.manager'])
            ->where('status', TaskStatus::Pending)
            ->where('due_at', '<', now())
            ->get();

        $reminded = 0;
        foreach ($overdue->filter(fn ($t) => $t->reminded_at === null || $t->reminded_at->lt(now()->subDay())) as $task) {
            $validators = $this->validatorsFor($task);
            if ($validators->isNotEmpty()) {
                Notification::send($validators, new ReviewOverdue($task));
            }
            $task->forceFill(['reminded_at' => now()])->save();
            $reminded++;
        }

        $limit = now()->subHours(config('gedflow.escalation_after_hours'));
        $escalated = 0;
        foreach ($overdue->filter(fn ($t) => $t->escalated_at === null && $t->due_at->lt($limit)) as $task) {
            $recipients = User::where('role', Role::Admin)->get()
                ->push($task->department->manager)
                ->filter()->unique('id')
                ->reject(fn (User $u) => $u->id === $task->document->owner_id);
            Notification::send($recipients, new ReviewEscalated($task));
            $task->forceFill(['escalated_at' => now()])->save();
            $this->audit->record('escalated', $task->document, null, ['step' => $task->step_name, 'department' => $task->department->name]);
            $escalated++;
        }

        return ['reminded' => $reminded, 'escalated' => $escalated];
    }

    private function openStep(Document $document, int $position): ?ReviewTask
    {
        $step = $document->type->steps()->where('position', '>=', $position)->first();
        if ($step === null) {
            return null;
        }

        return $document->tasks()->create([
            'document_version_id' => $document->current_version_id,
            'round' => $document->review_round,
            'position' => $step->position,
            'step_name' => $step->name,
            'department_id' => $step->department_id ?? $document->department_id,
            'status' => TaskStatus::Pending,
            'due_at' => now()->addHours($step->due_hours),
        ]);
    }

    private function approve(Document $document, User $user): void
    {
        $document->forceFill([
            'status' => DocumentStatus::Approved,
            'approved_at' => now(),
            'retention_until' => now()->addYears($document->type->retention_years)->toDateString(),
            'verification_token' => $document->verification_token ?? Str::random(40),
        ])->save();
        $this->audit->record('approved', $document, $user, ['round' => $document->review_round]);
        GenerateCertificate::dispatch($document->id)->afterCommit();
    }

    private function notifyValidators(ReviewTask $task): void
    {
        $validators = $this->validatorsFor($task);
        if ($validators->isNotEmpty()) {
            Notification::send($validators, new ReviewRequested($task));
        }
    }

    /** Valideurs du service de l'étape et remplaçants des valideurs absents, sauf l'auteur du document. */
    private function validatorsFor(ReviewTask $task): Collection
    {
        return User::where('role', Role::Validator)
            ->where('department_id', $task->department_id)
            ->get()
            ->merge(ValidationRights::delegatesForDepartment($task->department_id))
            ->unique('id')
            ->reject(fn (User $u) => $u->id === $task->document->owner_id)
            ->values();
    }

    public function archive(Document $document, User $user): void
    {
        if ($document->status !== DocumentStatus::Approved) {
            throw ValidationException::withMessages(['document' => 'Seul un document approuvé peut être archivé.']);
        }
        $document->forceFill(['status' => DocumentStatus::Archived, 'archived_at' => now()])->save();
        $this->audit->record('archived', $document, $user);
    }
}
