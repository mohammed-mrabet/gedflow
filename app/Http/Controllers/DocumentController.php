<?php

namespace App\Http\Controllers;

use App\Enums\Confidentiality;
use App\Enums\DocumentStatus;
use App\Enums\TaskStatus;
use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\DocumentVersion;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CertificateService;
use App\Services\DocumentService;
use App\Services\WorkflowService;
use App\Support\DocumentFilters;
use App\Support\Present;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    /** Formats acceptés et taille maximale (20 Mo). */
    public const FILE_RULES = ['required', 'file', 'max:20480', 'mimes:pdf,docx,xlsx,txt,png,jpg,jpeg'];

    public function __construct(
        private readonly DocumentService $documents,
        private readonly WorkflowService $workflow,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate(DocumentFilters::rules());
        $terms = $filters['q'] ?? null;
        $documents = DocumentFilters::query($filters, $request->user())->paginate(12)->withQueryString();

        return Inertia::render('Documents/Index', [
            'documents' => $documents->through(fn (Document $d) => [
                ...Present::documentRow($d),
                'excerpt' => $terms ? DocumentFilters::excerpt($d, $terms) : null,
            ]),
            'filters' => $filters,
            'options' => $this->options(),
        ]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('create', Document::class);

        return Inertia::render('Documents/Create', [
            'options' => $this->options(),
            'defaultDepartment' => $request->user()->department_id,
            'types' => DocumentType::with('steps.department')->orderBy('name')->get()->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'defaultConfidentiality' => $t->default_confidentiality->value,
                'steps' => $t->steps->map(fn ($s) => $s->name.' ('.($s->department?->name ?? 'service émetteur').')'),
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Document::class);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'document_type_id' => ['required', 'exists:document_types,id'],
            'department_id' => ['required', 'exists:departments,id'],
            'confidentiality' => ['required', Rule::enum(Confidentiality::class)],
            'tags' => ['nullable', 'array', 'max:10'],
            'tags.*' => ['string', 'max:30'],
            'file' => self::FILE_RULES,
            'submit' => ['nullable', 'boolean'],
        ]);

        $document = $this->documents->create($data, $request->file('file'), $request->user());

        if ($request->boolean('submit')) {
            $this->workflow->submit($document, $request->user());

            return redirect()->route('documents.show', $document)->with('success', "Document {$document->reference} déposé et soumis à validation.");
        }

        return redirect()->route('documents.show', $document)->with('success', "Brouillon {$document->reference} enregistré.");
    }

    public function show(Request $request, Document $document): Response
    {
        Gate::authorize('view', $document);
        $user = $request->user();
        $document->load(['type', 'department', 'owner', 'currentVersion', 'versions.uploader', 'tasks.department', 'tasks.decider', 'tasks.onBehalfOf', 'tasks.version', 'sharedWith']);
        $pending = $document->tasks->firstWhere('status', TaskStatus::Pending);

        return Inertia::render('Documents/Show', [
            'document' => [
                ...Present::documentRow($document),
                'description' => $document->description,
                'typeId' => $document->document_type_id,
                'reviewRound' => $document->review_round,
                'submittedAt' => $document->submitted_at?->toIso8601String(),
                'approvedAt' => $document->approved_at?->toIso8601String(),
                'archivedAt' => $document->archived_at?->toIso8601String(),
                'retentionUntil' => $document->retention_until?->toDateString(),
                'retentionYears' => $document->type->retention_years,
                'createdAt' => $document->created_at->toIso8601String(),
                'currentVersion' => $document->currentVersion ? Present::version($document->currentVersion) : null,
                'versions' => $document->versions->map(fn ($v) => Present::version($v)),
                'tasks' => $document->tasks->map(fn ($t) => Present::task($t)),
                'plannedSteps' => $document->type->steps()->with('department')->get()->map(fn ($s) => [
                    'position' => $s->position, 'name' => $s->name,
                    'department' => $s->department?->name ?? $document->department->name, 'dueHours' => $s->due_hours,
                ]),
                'sharedWith' => $document->sharedWith->map(fn ($u) => Present::user($u)),
                'hasCertificate' => $document->verification_token !== null
                    && in_array($document->status, [DocumentStatus::Approved, DocumentStatus::Archived], true),
                'verificationUrl' => $document->verification_token ? url("/verifier/{$document->verification_token}") : null,
            ],
            'pendingTaskId' => $pending?->id,
            'can' => [
                'update' => $user->can('update', $document),
                'submit' => $user->can('submit', $document),
                'withdraw' => $user->can('withdraw', $document),
                'share' => $user->can('share', $document),
                'archive' => $user->can('archive', $document),
                'decide' => $pending !== null && $user->can('decide', $pending),
            ],
            'activity' => $document->activities()->with('user')->limit(30)->get()->map(fn ($a) => Present::activity($a)),
            'confidentialityOptions' => DocumentService::confidentialityOptions(),
            'shareCandidates' => $user->can('share', $document)
                ? User::whereKeyNot($document->owner_id)->whereNotIn('id', $document->sharedWith->pluck('id'))
                    ->with('department')->orderBy('name')->get()->map(fn ($u) => Present::user($u))
                : [],
        ]);
    }

    public function update(Request $request, Document $document): RedirectResponse
    {
        Gate::authorize('update', $document);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'confidentiality' => ['required', Rule::enum(Confidentiality::class)],
            'tags' => ['nullable', 'array', 'max:10'],
            'tags.*' => ['string', 'max:30'],
        ]);
        $this->documents->updateMetadata($document, $data, $request->user());

        return back()->with('success', 'Informations enregistrées.');
    }

    public function storeVersion(Request $request, Document $document): RedirectResponse
    {
        Gate::authorize('update', $document);
        $data = $request->validate(['file' => self::FILE_RULES, 'comment' => ['nullable', 'string', 'max:500']]);
        $version = $this->documents->addVersion($document, $request->file('file'), $request->user(), $data['comment'] ?? null);

        return back()->with('success', "Version {$version->number} déposée.");
    }

    public function submit(Request $request, Document $document): RedirectResponse
    {
        Gate::authorize('submit', $document);
        $this->workflow->submit($document, $request->user());

        return back()->with('success', 'Document soumis : les valideurs de la première étape ont été prévenus.');
    }

    public function withdraw(Request $request, Document $document): RedirectResponse
    {
        Gate::authorize('withdraw', $document);
        $this->workflow->withdraw($document, $request->user());

        return back()->with('success', 'Document retiré du circuit : il est de nouveau modifiable.');
    }

    public function archive(Request $request, Document $document): RedirectResponse
    {
        Gate::authorize('archive', $document);
        $this->workflow->archive($document, $request->user());

        return back()->with('success', 'Document archivé.');
    }

    public function share(Request $request, Document $document): RedirectResponse
    {
        Gate::authorize('share', $document);
        $data = $request->validate(['user_id' => ['required', 'exists:users,id', Rule::notIn([$document->owner_id])]]);
        $document->sharedWith()->syncWithoutDetaching([$data['user_id'] => ['shared_by' => $request->user()->id]]);
        $this->audit->record('shared', $document, $request->user(), ['with' => User::find($data['user_id'])->name]);

        return back()->with('success', 'Accès accordé.');
    }

    public function unshare(Request $request, Document $document, User $user): RedirectResponse
    {
        Gate::authorize('share', $document);
        $document->sharedWith()->detach($user->id);
        $this->audit->record('unshared', $document, $request->user(), ['with' => $user->name]);

        return back()->with('success', 'Accès retiré.');
    }

    /** Téléchargement (ou aperçu en ligne) d'une version, après contrôle des droits ; chaque téléchargement est tracé. */
    public function download(Request $request, Document $document, DocumentVersion $version): StreamedResponse
    {
        Gate::authorize('view', $document);
        abort_unless($version->document_id === $document->id, 404);

        $inline = $request->boolean('inline') && $version->isPreviewable();
        if (! $inline) {
            $this->audit->record('downloaded', $document, $request->user(), ['version' => $version->number]);
        }

        $disk = Storage::disk(DocumentVersion::DISK);
        $headers = ['Content-Type' => $version->mime_type, 'X-Content-Type-Options' => 'nosniff'];

        return $inline
            ? $disk->response($version->path, $version->original_name, $headers, 'inline')
            : $disk->download($version->path, $version->original_name, $headers);
    }

    /** Certificat de validation (PDF) ; généré à la volée s'il n'est pas encore prêt (file d'attente). */
    public function certificate(Request $request, Document $document, CertificateService $certificates): StreamedResponse
    {
        Gate::authorize('view', $document);
        abort_unless(in_array($document->status, [DocumentStatus::Approved, DocumentStatus::Archived], true) && $document->verification_token, 404);
        $disk = Storage::disk(DocumentVersion::DISK);
        if (! $document->certificate_path || ! $disk->exists($document->certificate_path)) {
            $certificates->generate($document);
        }

        return $disk->response($document->certificate_path, "certificat-{$document->reference}.pdf", ['Content-Type' => 'application/pdf'], 'inline');
    }

    public function verify(Request $request, Document $document, DocumentVersion $version): RedirectResponse
    {
        Gate::authorize('view', $document);
        abort_unless($version->document_id === $document->id, 404);
        $intact = $version->verifyIntegrity();
        $this->audit->record('integrity_checked', $document, $request->user(), ['version' => $version->number, 'intact' => $intact]);

        return back()->with($intact ? 'success' : 'error', $intact
            ? "Version {$version->number} intègre : l'empreinte SHA-256 correspond au fichier déposé."
            : "Alerte : le fichier de la version {$version->number} a été modifié ou est introuvable.");
    }

    private function options(): array
    {
        return [
            'types' => DocumentType::orderBy('name')->get(['id', 'name']),
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            'statuses' => array_map(fn (DocumentStatus $s) => ['value' => $s->value, 'label' => $s->label()], DocumentStatus::cases()),
            'confidentialities' => DocumentService::confidentialityOptions(),
        ];
    }
}
