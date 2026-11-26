<?php

namespace App\Enums;

enum Role: string
{
    case Admin = 'admin';             // administre la GED : circuits, archivage, tous les documents
    case Validator = 'validator';     // valide les documents des étapes confiées à son service
    case Contributor = 'contributor'; // dépose et soumet des documents
    case Reader = 'reader';           // consulte uniquement

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrateur GED',
            self::Validator => 'Valideur',
            self::Contributor => 'Contributeur',
            self::Reader => 'Lecteur',
        };
    }
}
