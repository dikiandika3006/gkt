<?php

namespace App\Enums;

/** Tingkat hak akses per modul. Urutan: none < view < manage. */
enum PermissionLevel: string
{
    case None = 'none';
    case View = 'view';
    case Manage = 'manage';

    public function label(): string
    {
        return match ($this) {
            self::None => 'Tidak ada',
            self::View => 'Lihat',
            self::Manage => 'Kelola',
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::None => 0,
            self::View => 1,
            self::Manage => 2,
        };
    }

    public function covers(self $required): bool
    {
        return $this->rank() >= $required->rank();
    }
}
