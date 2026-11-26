<?php

namespace App\Http\Resources;

use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Document */
class DocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'title' => $this->title,
            'description' => $this->description,
            'type' => $this->type?->name,
            'department' => $this->department?->name,
            'owner' => $this->owner?->name,
            'status' => $this->status->value,
            'confidentiality' => $this->confidentiality->value,
            'tags' => $this->tags ?? [],
            'current_version' => $this->whenLoaded('currentVersion', fn () => $this->currentVersion ? new VersionResource($this->currentVersion) : null),
            'versions' => VersionResource::collection($this->whenLoaded('versions')),
            'review_round' => $this->review_round,
            'tasks' => TaskResource::collection($this->whenLoaded('tasks')),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'retention_until' => $this->retention_until?->toDateString(),
            'verification_url' => $this->verification_token ? url("/verifier/{$this->verification_token}") : null,
            'updated_at' => $this->updated_at->toIso8601String(),
            'links' => ['self' => url("/api/v1/documents/{$this->id}"), 'web' => url("/documents/{$this->id}")],
        ];
    }
}
