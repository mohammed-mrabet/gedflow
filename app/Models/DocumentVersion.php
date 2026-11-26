<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

#[Fillable(['document_id', 'number', 'original_name', 'path', 'mime_type', 'size', 'sha256', 'comment', 'uploaded_by'])]
#[Hidden(['path', 'extracted_text'])]
class DocumentVersion extends Model
{
    use HasFactory;

    public const DISK = 'local';

    protected function casts(): array
    {
        return ['text_extracted_at' => 'datetime'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** Recalcule l'empreinte du fichier stocké et la compare à celle enregistrée au dépôt. */
    public function verifyIntegrity(): bool
    {
        $disk = Storage::disk(self::DISK);
        if (! $disk->exists($this->path)) {
            return false;
        }

        return hash_equals($this->sha256, hash_file('sha256', $disk->path($this->path)));
    }

    public function isPreviewable(): bool
    {
        return $this->mime_type === 'application/pdf' || str_starts_with($this->mime_type, 'image/');
    }
}
