<?php

namespace App\Http\Resources;

use App\Models\DocumentVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DocumentVersion */
class VersionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'file_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            'sha256' => $this->sha256,
            'comment' => $this->comment,
            'text_indexed' => $this->text_extracted_at !== null && $this->extracted_text !== null,
            'created_at' => $this->created_at->toIso8601String(),
            'download_url' => url("/api/v1/documents/{$this->document_id}/versions/{$this->id}/file"),
        ];
    }
}
