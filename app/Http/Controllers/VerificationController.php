<?php

namespace App\Http\Controllers;

use App\Enums\Confidentiality;
use App\Enums\DocumentStatus;
use App\Enums\TaskStatus;
use App\Models\Document;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Page publique ouverte par le QR code du certificat : elle confirme qu'un document a été approuvé
 * et permet de vérifier qu'un fichier reçu est exactement celui qui a été validé (comparaison SHA-256).
 * Aucune connexion requise : seules des informations minimales sont affichées.
 */
class VerificationController extends Controller
{
    public function show(string $token): Response
    {
        $document = $this->find($token);

        return Inertia::render('Verify', [
            'certificate' => $document ? $this->present($document) : null,
            'result' => session('verification'),
        ]);
    }

    public function check(Request $request, string $token, AuditLogger $audit): RedirectResponse
    {
        $document = $this->find($token);
        abort_if($document === null, 404);
        $request->validate(['file' => ['required', 'file', 'max:20480']]);

        // L'empreinte est calculée en mémoire : le fichier envoyé n'est jamais enregistré.
        $hash = hash_file('sha256', $request->file('file')->getRealPath());
        $match = hash_equals($document->currentVersion->sha256, $hash);
        $audit->record('external_verification', $document, null, ['match' => $match]);

        return back()->with('verification', ['match' => $match, 'sha256' => $hash, 'name' => $request->file('file')->getClientOriginalName()]);
    }

    private function find(string $token): ?Document
    {
        if (strlen($token) !== 40) {
            return null;
        }

        return Document::with(['type', 'department', 'currentVersion'])->where('verification_token', $token)
            ->whereIn('status', [DocumentStatus::Approved, DocumentStatus::Archived])->first();
    }

    private function present(Document $document): array
    {
        $tasks = $document->tasks()->with(['decider', 'department', 'onBehalfOf'])
            ->where('round', $document->review_round)->where('status', TaskStatus::Approved)->get();

        return [
            'reference' => $document->reference,
            // Un document restreint ne révèle pas son titre à une personne non connectée.
            'title' => $document->confidentiality === Confidentiality::Restricted ? null : $document->title,
            'type' => $document->type->name,
            'department' => $document->department->name,
            'status' => $document->status->label(),
            'approvedAt' => $document->approved_at?->toIso8601String(),
            'version' => $document->currentVersion->number,
            'sha256' => $document->currentVersion->sha256,
            'visas' => $tasks->map(fn ($t) => [
                'step' => $t->step_name,
                'department' => $t->department->name,
                'by' => $t->decider?->name,
                'onBehalfOf' => $t->onBehalfOf?->name,
                'at' => $t->decided_at->toIso8601String(),
            ]),
        ];
    }
}
