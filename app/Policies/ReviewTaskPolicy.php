<?php

namespace App\Policies;

use App\Enums\TaskStatus;
use App\Models\ReviewTask;
use App\Models\User;
use App\Services\ValidationRights;

class ReviewTaskPolicy
{
    /**
     * Un valideur du service concerné (ou son remplaçant pendant une absence),
     * qui n'est pas l'auteur du document (séparation des responsabilités).
     */
    public function decide(User $user, ReviewTask $task): bool
    {
        return $task->status === TaskStatus::Pending
            && ValidationRights::departmentsFor($user)->contains($task->department_id)
            && $task->document->owner_id !== $user->id
            // Quatre yeux : une même personne ne vise pas deux étapes du même tour (par exemple via une délégation).
            && ! ReviewTask::where('document_id', $task->document_id)->where('round', $task->round)
                ->where('decided_by', $user->id)->exists();
    }
}
