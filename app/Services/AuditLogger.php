<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Document;
use App\Models\User;
use Illuminate\Http\Request;

/** Écrit le journal d'audit : qui a fait quoi, sur quel document, quand et depuis quelle adresse. */
class AuditLogger
{
    public function __construct(private readonly ?Request $request = null) {}

    public function record(string $action, ?Document $document = null, ?User $user = null, array $details = []): ActivityLog
    {
        return ActivityLog::create([
            'document_id' => $document?->id,
            'user_id' => $user?->id ?? $this->request?->user()?->id,
            'action' => $action,
            'details' => $details ?: null,
            'ip_address' => $this->request?->ip(),
            'created_at' => now(),
        ]);
    }
}
