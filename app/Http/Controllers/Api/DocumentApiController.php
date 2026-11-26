<?php

namespace App\Http\Controllers\Api;

use App\Enums\Confidentiality;
use App\Http\Controllers\Controller;
use App\Http\Controllers\DocumentController;
use App\Http\Resources\DocumentResource;
use App\Http\Resources\VersionResource;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Services\AuditLogger;
use App\Services\DocumentService;
use App\Services\WorkflowService;
use App\Support\DocumentFilters;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** API REST v1 : mêmes services, mêmes droits (policies) et même journal d'audit que l'interface web. */
class DocumentApiController extends Controller
{
    public function __construct(
        private readonly DocumentService $documents,
        private readonly WorkflowService $workflow,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([...DocumentFilters::rules(), 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);

        return DocumentResource::collection(
            DocumentFilters::query($filters, $request->user())->paginate($filters['per_page'] ?? 20)->withQueryString()
        );
    }

    public function show(Request $request, Document $document): DocumentResource
    {
        Gate::authorize('view', $document);

        return new DocumentResource($document->load(['type', 'department', 'owner', 'currentVersion', 'versions', 'tasks.department', 'tasks.decider']));
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', Document::class);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'document_type_id' => ['required', 'exists:document_types,id'],
            'department_id' => ['required', 'exists:departments,id'],
            'confidentiality' => ['nullable', Rule::enum(Confidentiality::class)],
            'tags' => ['nullable', 'array', 'max:10'],
            'tags.*' => ['string', 'max:30'],
            'file' => DocumentController::FILE_RULES,
            'submit' => ['nullable', 'boolean'],
        ]);
        $document = $this->documents->create($data, $request->file('file'), $request->user());
        if ($request->boolean('submit')) {
            $this->workflow->submit($document, $request->user());
        }

        return (new DocumentResource($document->fresh(['type', 'department', 'owner', 'currentVersion'])))
            ->response()->setStatusCode(201)->header('Location', url("/api/v1/documents/{$document->id}"));
    }

    public function storeVersion(Request $request, Document $document): JsonResponse
    {
        Gate::authorize('update', $document);
        $data = $request->validate(['file' => DocumentController::FILE_RULES, 'comment' => ['nullable', 'string', 'max:500']]);
        $version = $this->documents->addVersion($document, $request->file('file'), $request->user(), $data['comment'] ?? null);

        return (new VersionResource($version))->response()->setStatusCode(201);
    }

    public function submit(Request $request, Document $document): DocumentResource
    {
        Gate::authorize('submit', $document);
        $this->workflow->submit($document, $request->user());

        return new DocumentResource($document->fresh(['type', 'department', 'owner', 'currentVersion', 'tasks.department']));
    }

    public function download(Request $request, Document $document, DocumentVersion $version): StreamedResponse
    {
        Gate::authorize('view', $document);
        abort_unless($version->document_id === $document->id, 404);
        $this->audit->record('downloaded', $document, $request->user(), ['version' => $version->number, 'via' => 'api']);

        return Storage::disk(DocumentVersion::DISK)->download($version->path, $version->original_name, ['Content-Type' => $version->mime_type]);
    }
}
