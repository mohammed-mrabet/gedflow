<?php

namespace App\Policies;

use App\Enums\Confidentiality;
use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\User;
use App\Services\ValidationRights;

class DocumentPolicy
{
    /** Même règle que le scope Document::visibleTo (testée des deux côtés). */
    public function view(User $user, Document $document): bool
    {
        return $user->isAdmin()
            || $document->owner_id === $user->id
            || $document->confidentiality === Confidentiality::Internal
            || ($document->confidentiality === Confidentiality::Department && $document->department_id === $user->department_id)
            || $document->sharedWith()->whereKey($user->id)->exists()
            || $document->tasks()->whereIn('department_id', ValidationRights::departmentsFor($user))->exists();
    }

    public function create(User $user): bool
    {
        return $user->canContribute();
    }

    /** Métadonnées et nouvelles versions : l'auteur (ou l'administrateur) tant que le document est modifiable. */
    public function update(User $user, Document $document): bool
    {
        return $document->status->isEditable() && ($document->owner_id === $user->id || $user->isAdmin());
    }

    public function submit(User $user, Document $document): bool
    {
        return $document->owner_id === $user->id && $document->status->isEditable();
    }

    public function withdraw(User $user, Document $document): bool
    {
        return $document->owner_id === $user->id && $document->status === DocumentStatus::InReview;
    }

    public function share(User $user, Document $document): bool
    {
        return $document->owner_id === $user->id || $user->isAdmin();
    }

    public function archive(User $user, Document $document): bool
    {
        return $user->isAdmin() && $document->status === DocumentStatus::Approved;
    }
}
