<?php

namespace App\Enums;

/** Sifat kategori. Hanya `operational` yang dihitung dalam surplus/defisit operasional. */
enum CategoryNature: string
{
    case Operational = 'operational';
    case Titipan = 'titipan';
    case Pinjaman = 'pinjaman';
    case Transfer = 'transfer';

    public function label(): string
    {
        return match ($this) {
            self::Operational => 'Operasional',
            self::Titipan => 'Titipan',
            self::Pinjaman => 'Pinjaman',
            self::Transfer => 'Mutasi Antar Kas',
        };
    }
}
