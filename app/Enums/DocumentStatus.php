<?php

namespace App\Enums;

enum DocumentStatus: string
{
    case Draft = 'draft';
    case InReview = 'in_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Brouillon',
            self::InReview => 'En validation',
            self::Approved => 'Approuvé',
            self::Rejected => 'Rejeté',
            self::Archived => 'Archivé',
        };
    }

    /** Le contenu ne peut changer que tant que le document n'est ni en validation ni validé. */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Rejected], true);
    }
}
