<?php

namespace App\Http\Resources;

use App\Models\ReviewTask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ReviewTask */
class TaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'document_id' => $this->document_id,
            'document' => $this->whenLoaded('document', fn () => ['reference' => $this->document->reference, 'title' => $this->document->title]),
            'round' => $this->round,
            'position' => $this->position,
            'step' => $this->step_name,
            'department' => $this->department?->name,
            'status' => $this->status->value,
            'due_at' => $this->due_at->toIso8601String(),
            'overdue' => $this->isOverdue(),
            'decided_by' => $this->decider?->name,
            'decided_at' => $this->decided_at?->toIso8601String(),
            'comment' => $this->comment,
        ];
    }
}
