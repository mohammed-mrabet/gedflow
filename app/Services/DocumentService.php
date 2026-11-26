<?php

namespace App\Services;

use App\Enums\Confidentiality;
use App\Enums\DocumentStatus;
use App\Jobs\ExtractVersionText;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\DocumentVersion;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DocumentService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Crée un document (brouillon) et sa première version.
     *
     * @param  array{title: string, description?: ?string, document_type_id: int, department_id: int, confidentiality?: ?string, tags?: ?array}  $data
     */
    public function create(array $data, UploadedFile $file, User $owner): Document
    {
        $type = DocumentType::findOrFail($data['document_type_id']);

        // La référence est unique : en cas de dépôts simultanés, on recalcule le numéro et on réessaie.
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(function () use ($data, $file, $owner, $type) {
                    $document = Document::create([
                        'reference' => $this->nextReference($type),
                        'title' => $data['title'],
                        'description' => $data['description'] ?? null,
                        'document_type_id' => $type->id,
                        'department_id' => $data['department_id'],
                        'owner_id' => $owner->id,
                        'status' => DocumentStatus::Draft,
                        'confidentiality' => $data['confidentiality'] ?? $type->default_confidentiality->value,
                        'tags' => $this->normalizeTags($data['tags'] ?? []),
                    ]);
                    $this->audit->record('created', $document, $owner, ['reference' => $document->reference]);
                    $this->storeVersion($document, $file, $owner, null);

                    return $document;
                });
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= 3) {
                    throw $e;
                }
            }
        }
    }

    /** Nouvelle version : seulement pour un brouillon ou un document rejeté (jamais pendant la validation). */
    public function addVersion(Document $document, UploadedFile $file, User $user, ?string $comment): DocumentVersion
    {
        if (! $document->status->isEditable()) {
            throw ValidationException::withMessages([
                'file' => 'Impossible d\'ajouter une version : le document est '.mb_strtolower($document->status->label()).'.',
            ]);
        }

        return DB::transaction(fn () => $this->storeVersion($document, $file, $user, $comment));
    }

    public function updateMetadata(Document $document, array $data, User $user): void
    {
        $document->fill([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'confidentiality' => $data['confidentiality'],
            'tags' => $this->normalizeTags($data['tags'] ?? []),
        ]);
        $changes = array_keys($document->getDirty());
        $document->save();
        $document->refreshSearchText();
        if ($changes) {
            $this->audit->record('metadata_updated', $document, $user, ['fields' => $changes]);
        }
    }

    private function storeVersion(Document $document, UploadedFile $file, User $user, ?string $comment): DocumentVersion
    {
        $number = (int) $document->versions()->max('number') + 1;
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
        // Nom de fichier aléatoire : le nom d'origine n'est jamais utilisé comme chemin sur le disque.
        $path = $file->storeAs("documents/{$document->id}", "v{$number}-".Str::uuid().".{$extension}", DocumentVersion::DISK);

        $version = $document->versions()->create([
            'number' => $number,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'path' => $path,
            'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
            'size' => $file->getSize(),
            'sha256' => hash_file('sha256', Storage::disk(DocumentVersion::DISK)->path($path)),
            'comment' => $comment,
            'uploaded_by' => $user->id,
        ]);

        $document->current_version_id = $version->id;
        $document->save();
        $document->setRelation('currentVersion', $version);
        $document->refreshSearchText();

        $this->audit->record($number === 1 ? 'version_uploaded' : 'new_version', $document, $user, [
            'version' => $number, 'file' => $version->original_name, 'sha256' => $version->sha256,
        ]);

        // L'extraction du texte peut être lente (gros PDF) : elle est faite en tâche de fond après la transaction.
        ExtractVersionText::dispatch($version->id)->afterCommit();

        return $version;
    }

    private function nextReference(DocumentType $type): string
    {
        $prefix = $type->code.'-'.now()->year.'-';
        $last = Document::where('reference', 'like', $prefix.'%')->orderByDesc('reference')->value('reference');
        $next = $last ? ((int) Str::afterLast($last, '-')) + 1 : 1;

        return $prefix.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }

    /** @param  array<int, string>|string|null  $tags */
    private function normalizeTags(array|string|null $tags): array
    {
        $tags = is_string($tags) ? explode(',', $tags) : ($tags ?? []);

        return array_values(array_unique(array_filter(array_map(
            fn ($t) => mb_substr(mb_strtolower(trim((string) $t)), 0, 30), $tags))));
    }

    public static function confidentialityOptions(): array
    {
        return array_map(fn (Confidentiality $c) => ['value' => $c->value, 'label' => $c->label()], Confidentiality::cases());
    }
}
