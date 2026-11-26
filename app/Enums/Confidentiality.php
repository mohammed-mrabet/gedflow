<?php

namespace App\Enums;

enum Confidentiality: string
{
    case Internal = 'internal';       // tous les collaborateurs
    case Department = 'department';   // le service émetteur (et les valideurs du circuit)
    case Restricted = 'restricted';   // l'auteur, les valideurs du circuit et les personnes autorisées

    public function label(): string
    {
        return match ($this) {
            self::Internal => 'Interne',
            self::Department => 'Service',
            self::Restricted => 'Restreint',
        };
    }
}
