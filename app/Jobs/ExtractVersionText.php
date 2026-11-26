<?php

namespace App\Jobs;

use App\Models\DocumentVersion;
use App\Services\TextExtractor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

/** Extraction du texte d'une version (en tâche de fond), puis mise à jour de l'index de recherche du document. */
class ExtractVersionText implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $versionId) {}

    public function handle(TextExtractor $extractor): void
    {
        $version = DocumentVersion::with('document')->find($this->versionId);
        if ($version === null) {
            return;
        }

        $version->forceFill([
            'extracted_text' => $extractor->extract(Storage::disk(DocumentVersion::DISK)->path($version->path), $version->mime_type),
            'text_extracted_at' => now(),
        ])->save();

        $document = $version->document;
        if ($document->current_version_id === $version->id) {
            $document->setRelation('currentVersion', $version);
            $document->refreshSearchText();
        }
    }
}
