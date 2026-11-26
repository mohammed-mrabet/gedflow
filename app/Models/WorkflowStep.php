<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['document_type_id', 'position', 'name', 'department_id', 'due_hours'])]
class WorkflowStep extends Model
{
    use HasFactory;

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    /** Null : l'étape est traitée par les valideurs du service émetteur du document. */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
