<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Support\Present;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Journal d'audit complet (administrateur) avec export CSV pour un contrôle externe. */
class AuditController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->isAdmin(), 403);
        $filters = $request->validate(['action' => ['nullable', 'string', 'max:40'], 'q' => ['nullable', 'string', 'max:60']]);

        return Inertia::render('Audit/Index', [
            'entries' => $this->query($filters)->paginate(20)->withQueryString()->through(fn ($a) => Present::activity($a)),
            'filters' => $filters,
            'actions' => collect(['created', 'new_version', 'submitted', 'withdrawn', 'step_approved', 'rejected', 'approved', 'archived', 'downloaded', 'shared', 'integrity_checked', 'login'])
                ->map(fn ($a) => ['value' => $a, 'label' => Present::actionLabel($a)]),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $filters = $request->validate(['action' => ['nullable', 'string', 'max:40'], 'q' => ['nullable', 'string', 'max:60']]);
        $query = $this->query($filters);

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM : accents corrects dans Excel
            fputcsv($out, ['Date (UTC)', 'Utilisateur', 'Action', 'Document', 'Détails', 'Adresse IP'], ';');
            $query->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $a) {
                    fputcsv($out, array_map([$this, 'safeCell'], [
                        $a->created_at->format('Y-m-d H:i:s'), $a->user?->name, Present::actionLabel($a->action),
                        $a->document?->reference, $a->details ? json_encode($a->details, JSON_UNESCAPED_UNICODE) : '', $a->ip_address,
                    ]), ';');
                }
            });
            fclose($out);
        }, 'journal-audit-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Neutralise les formules (=, +, -, @) : une cellule ne doit jamais être exécutée par le tableur. */
    public function safeCell(?string $value): string
    {
        $value = (string) $value;

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }

    private function query(array $filters)
    {
        return ActivityLog::with(['user', 'document'])
            ->when($filters['action'] ?? null, fn ($q, $v) => $q->where('action', $v))
            ->when($filters['q'] ?? null, fn ($q, $v) => $q->where(fn ($q) => $q
                ->whereHas('user', fn ($u) => $u->where('name', 'like', "%{$v}%"))
                ->orWhereHas('document', fn ($d) => $d->where('reference', 'like', "%{$v}%"))))
            ->latest('created_at')->latest('id');
    }
}
