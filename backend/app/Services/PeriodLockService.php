<?php

namespace App\Services;

use App\Models\PeriodLock;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Tutup buku per bulan. Dipanggil dari event model Transaction, sehingga semua jalur
 * (form, mutasi antar kas, import, LPJ, rekap persembahan) ikut terkunci.
 * Singleton: daftar bulan terkunci di-cache per request dan dikosongkan saat PeriodLock berubah.
 */
class PeriodLockService
{
    private const MONTHS = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    /** @var array<string, true>|null */
    private ?array $locked = null;

    /** @return array<string, true> */
    public function lockedMonths(): array
    {
        return $this->locked ??= PeriodLock::pluck('month')->mapWithKeys(fn ($m) => [$m => true])->all();
    }

    public function isLocked(Carbon|string|null $date): bool
    {
        if ($date === null || $date === '') {
            return false;
        }

        return isset($this->lockedMonths()[Carbon::parse($date)->format('Y-m')]);
    }

    /** Tolak bila salah satu tanggal berada di bulan yang sudah ditutup buku. */
    public function ensureOpen(Carbon|string|null ...$dates): void
    {
        foreach ($dates as $date) {
            if ($this->isLocked($date)) {
                throw ValidationException::withMessages([
                    'date' => 'Bulan '.$this->label(Carbon::parse($date)).' sudah ditutup buku. Minta admin membuka bulan ini bila perlu koreksi.',
                ]);
            }
        }
    }

    public function label(Carbon $date): string
    {
        return self::MONTHS[$date->month - 1].' '.$date->year;
    }

    public function flush(): void
    {
        $this->locked = null;
    }
}
