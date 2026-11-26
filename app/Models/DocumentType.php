<?php

namespace App\Models;

use App\Enums\Confidentiality;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name', 'description', 'retention_years', 'default_confidentiality'])]
class DocumentType extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['default_confidentiality' => Confidentiality::class];
    }

    public function steps(): HasMany
    {
        return $this->hasMany(WorkflowStep::class)->orderBy('position');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }
}
