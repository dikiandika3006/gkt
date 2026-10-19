<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Bendahara = 'bendahara';
    case Sekretariat = 'sekretariat';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrator',
            self::Bendahara => 'Bendahara',
            self::Sekretariat => 'Sekretariat',
            self::Viewer => 'Viewer',
        };
    }
}
