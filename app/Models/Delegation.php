<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pendant son absence, un valideur confie ses visas à un remplaçant, sur une période donnée. */
#[Fillable(['delegator_id', 'delegate_id', 'starts_on', 'ends_on', 'reason'])]
class Delegation extends Model
{
    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'cancelled_at' => 'datetime'];
    }

    public function delegator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegator_id');
    }

    public function delegate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegate_id');
    }

    /** Délégations en vigueur aujourd'hui (dates incluses), non annulées. */
    public function scopeActive(Builder $query): Builder
    {
        $today = now()->toDateString();

        return $query->whereNull('cancelled_at')->whereDate('starts_on', '<=', $today)->whereDate('ends_on', '>=', $today);
    }

    public function isActive(): bool
    {
        return $this->cancelled_at === null && now()->startOfDay()->betweenIncluded($this->starts_on, $this->ends_on);
    }
}
