<?php

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\ReviewTask;
use App\Models\User;

/** Mise en forme des modèles pour les pages React (uniquement les champs nécessaires). */
final class Present
{
    public static function documentRow(Document $d): array
    {
        return [
            'id' => $d->id,
            'reference' => $d->reference,
            'title' => $d->title,
            'type' => $d->type?->name,
            'department' => $d->department?->name,
            'owner' => $d->owner?->name,
            'status' => $d->status->value,
            'statusLabel' => $d->status->label(),
            'confidentiality' => $d->confidentiality->value,
            'confidentialityLabel' => $d->confidentiality->label(),
            'tags' => $d->tags ?? [],
            'version' => $d->currentVersion?->number,
            'updatedAt' => $d->updated_at?->toIso8601String(),
            'retentionExpired' => $d->isRetentionExpired(),
        ];
    }

    public static function version(DocumentVersion $v): array
    {
        return [
            'id' => $v->id,
            'number' => $v->number,
            'name' => $v->original_name,
            'mimeType' => $v->mime_type,
            'size' => $v->size,
            'sha256' => $v->sha256,
            'comment' => $v->comment,
            'uploadedBy' => $v->uploader?->name,
            'createdAt' => $v->created_at->toIso8601String(),
            'previewable' => $v->isPreviewable(),
            'textIndexed' => $v->text_extracted_at !== null && $v->extracted_text !== null,
        ];
    }

    public static function task(ReviewTask $t): array
    {
        return [
            'id' => $t->id,
            'round' => $t->round,
            'position' => $t->position,
            'stepName' => $t->step_name,
            'department' => $t->department?->name,
            'status' => $t->status->value,
            'statusLabel' => $t->status->label(),
            'dueAt' => $t->due_at->toIso8601String(),
            'overdue' => $t->isOverdue(),
            'decidedBy' => $t->decider?->name,
            'onBehalfOf' => $t->onBehalfOf?->name,
            'escalated' => $t->escalated_at !== null,
            'decidedAt' => $t->decided_at?->toIso8601String(),
            'comment' => $t->comment,
            'version' => $t->version?->number,
        ];
    }

    public static function activity(ActivityLog $a): array
    {
        return [
            'id' => $a->id,
            'action' => $a->action,
            'label' => self::actionLabel($a->action),
            'user' => $a->user?->name,
            'document' => $a->document ? ['id' => $a->document->id, 'reference' => $a->document->reference] : null,
            'details' => $a->details,
            'ip' => $a->ip_address,
            'createdAt' => $a->created_at->toIso8601String(),
        ];
    }

    public static function user(User $u): array
    {
        return ['id' => $u->id, 'name' => $u->name, 'department' => $u->department?->name];
    }

    public static function actionLabel(string $action): string
    {
        return [
            'created' => 'Document créé',
            'version_uploaded' => 'Fichier déposé',
            'new_version' => 'Nouvelle version',
            'metadata_updated' => 'Informations modifiées',
            'submitted' => 'Soumis à validation',
            'withdrawn' => 'Retiré du circuit',
            'step_approved' => 'Étape visée',
            'rejected' => 'Rejeté',
            'approved' => 'Approuvé',
            'archived' => 'Archivé',
            'downloaded' => 'Téléchargé',
            'shared' => 'Partagé',
            'unshared' => 'Partage retiré',
            'integrity_checked' => 'Intégrité vérifiée',
            'login' => 'Connexion',
            'escalated' => 'Étape escaladée',
            'external_verification' => 'Vérification publique',
            'delegation_created' => 'Délégation créée',
            'delegation_cancelled' => 'Délégation annulée',
            'api_token_created' => 'Jeton d\'API créé',
            'api_token_revoked' => 'Jeton d\'API révoqué',
            'workflow_updated' => 'Circuit modifié',
        ][$action] ?? $action;
    }
}
