<?php

namespace App\Jobs;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Services\CertificateService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateCertificate implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $documentId) {}

    public function handle(CertificateService $certificates): void
    {
        $document = Document::find($this->documentId);
        if ($document && in_array($document->status, [DocumentStatus::Approved, DocumentStatus::Archived], true)) {
            $certificates->generate($document);
        }
    }
}
