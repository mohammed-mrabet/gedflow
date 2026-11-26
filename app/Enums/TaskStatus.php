<?php

namespace App\Enums;

enum TaskStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled'; // circuit interrompu (document retiré par son auteur)

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En attente',
            self::Approved => 'Visé',
            self::Rejected => 'Rejeté',
            self::Cancelled => 'Annulé',
        };
    }
}
