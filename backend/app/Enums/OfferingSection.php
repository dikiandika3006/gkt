<?php

namespace App\Enums;

/** Bagian pada rekap persembahan mingguan (mengikuti format warta). */
enum OfferingSection: string
{
    case Ibadah = 'ibadah';
    case Syukur = 'syukur';
    case Perpuluhan = 'perpuluhan';
    case Lainnya = 'lainnya';
    case Pengeluaran = 'pengeluaran';

    public function label(): string
    {
        return match ($this) {
            self::Ibadah => 'Persembahan Ibadah',
            self::Syukur => 'Persembahan Syukur',
            self::Perpuluhan => 'Perpuluhan',
            self::Lainnya => 'Persembahan Lain',
            self::Pengeluaran => 'Pengeluaran',
        };
    }

    /** Bagian yang baris-barisnya dicatat per nama/kode jemaat. */
    public function requiresName(): bool
    {
        return $this === self::Syukur || $this === self::Perpuluhan;
    }

    /** Ikut dijumlahkan ke Total Persembahan (pengeluaran hanya catatan). */
    public function isOffering(): bool
    {
        return $this !== self::Pengeluaran;
    }
}
