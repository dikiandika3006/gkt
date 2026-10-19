<?php

namespace App\Assistant;

use App\Models\CashAccount;
use App\Models\Category;
use Illuminate\Support\Collection;

/** Hasil pemahaman satu pertanyaan: teks ternormalisasi + entitas yang dikenali. */
final class ParsedQuestion
{
    /**
     * @param  Collection<int, Category>  $categories  kategori yang dimaksud (satu kategori, atau semua isi satu kelompok)
     * @param  Collection<int, CashAccount>  $accounts
     */
    public function __construct(
        public readonly string $original,
        public readonly string $text,
        public readonly Period $period,
        public readonly Collection $categories,
        public readonly ?string $group,
        public readonly Collection $accounts,
    ) {}

    /** Jumlah frasa yang muncul di pertanyaan (per kata utuh; akhiran "-nya" ikut dikenali, mis. "saldonya"). */
    public function count(array $phrases): int
    {
        $haystack = ' '.$this->text.' ';

        return collect($phrases)
            ->filter(fn (string $p) => str_contains($haystack, ' '.$p.' ') || str_contains($haystack, ' '.$p.'nya '))
            ->count();
    }

    public function has(string ...$phrases): bool
    {
        return $this->count($phrases) > 0;
    }

    public function hasSubject(): bool
    {
        return $this->categories->isNotEmpty();
    }

    /** Nama yang ditampilkan untuk subjek kategori/kelompok. */
    public function subjectLabel(): ?string
    {
        if ($this->group !== null) {
            return 'kelompok '.$this->group;
        }

        return $this->categories->first()?->name;
    }

    /** Arah yang dimaksud: 'income' | 'expense' | null (tidak jelas). */
    public function direction(): ?string
    {
        $income = $this->count(['pemasukan', 'masuk', 'penerimaan', 'persembahan', 'perpuluhan', 'kolekte', 'diterima', 'pendapatan']);
        $expense = $this->count(['pengeluaran', 'keluar', 'biaya', 'bayar', 'dibayar', 'belanja', 'habis', 'dipakai']);

        if ($income === $expense) {
            $type = $this->categories->first()?->type?->value;

            return in_array($type, ['income', 'expense'], true) ? $type : null;
        }

        return $income > $expense ? 'income' : 'expense';
    }
}
