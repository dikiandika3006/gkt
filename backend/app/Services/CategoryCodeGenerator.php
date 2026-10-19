<?php

namespace App\Services;

use App\Enums\CategoryNature;
use App\Enums\CategoryType;
use App\Models\Category;
use Illuminate\Support\Collection;

/**
 * Membuat kode kategori otomatis mengikuti pola bagan akun "D-NNN":
 * 4 = pemasukan, 5 = pengeluaran, 6 = dua arah/komisi, 7 = titipan/pinjaman.
 * Kode di luar pola (mis. TRF-*, IMP-*) diabaikan.
 */
class CategoryCodeGenerator
{
    private const PATTERN = '/^(\d)-(\d{3})$/';

    public function next(string $group, CategoryType $type, CategoryNature $nature): string
    {
        $all = $this->parsed(Category::query()->get(['code', 'group']));
        $taken = $all->map(fn ($c) => $c['code'])->flip();

        // 1. Kelompok yang sudah ada: lanjutkan nomor terakhirnya.
        $inGroup = $all->filter(fn ($c) => strcasecmp($c['group'], trim($group)) === 0);
        if ($inGroup->isNotEmpty()) {
            $last = $inGroup->sortBy(fn ($c) => [$c['prefix'], $c['number']])->last();

            return $this->firstFree($last['prefix'], $last['number'] + 1, $taken);
        }

        // 2. Kelompok baru: blok ratusan berikutnya pada awalan sesuai jenis/sifat.
        $prefix = $this->prefixFor($type, $nature);
        $hundreds = $all->where('prefix', $prefix)->map(fn ($c) => intdiv($c['number'], 100) * 100);
        $next = ($hundreds->max() ?? 0) + 100;

        if ($next > 900) {
            // Ratusan habis: pakai blok pertama yang masih kosong.
            $next = collect(range(100, 900, 100))->first(fn ($h) => ! $hundreds->contains($h));
            if ($next === null) {
                return $this->firstFree($prefix, 1, $taken);
            }
        }

        return $this->firstFree($prefix, $next, $taken);
    }

    private function prefixFor(CategoryType $type, CategoryNature $nature): int
    {
        if (in_array($nature, [CategoryNature::Titipan, CategoryNature::Pinjaman], true)) {
            return 7;
        }

        return match ($type) {
            CategoryType::Income => 4,
            CategoryType::Expense => 5,
            CategoryType::Both => 6,
        };
    }

    /** @param  Collection<string, int>  $taken */
    private function firstFree(int $prefix, int $number, Collection $taken): string
    {
        while ($number <= 999) {
            $code = sprintf('%d-%03d', $prefix, $number);
            if (! $taken->has($code)) {
                return $code;
            }
            $number++;
        }

        // Semua nomor di awalan ini terpakai (praktis tidak terjadi): cari dari awal.
        for ($number = 1; $number <= 999; $number++) {
            $code = sprintf('%d-%03d', $prefix, $number);
            if (! $taken->has($code)) {
                return $code;
            }
        }

        throw new \RuntimeException("Kode kategori untuk awalan {$prefix} sudah habis.");
    }

    /**
     * @param  iterable<Category>  $categories
     * @return Collection<int, array{code: string, group: string, prefix: int, number: int}>
     */
    private function parsed(iterable $categories): Collection
    {
        return collect($categories)
            ->map(fn ($c) => preg_match(self::PATTERN, $c->code, $m)
                ? ['code' => $c->code, 'group' => (string) $c->group, 'prefix' => (int) $m[1], 'number' => (int) $m[2]]
                : null)
            ->filter()
            ->values();
    }
}
