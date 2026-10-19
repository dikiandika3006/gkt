<?php

namespace App\Assistant;

use App\Enums\CashAccountType;
use App\Enums\CategoryNature;
use App\Models\CashAccount;
use App\Models\Category;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/** Memahami pertanyaan berbahasa Indonesia: periode, kategori/kelompok, dan kas yang dimaksud. */
class QuestionParser
{
    private const MONTHS = [
        'januari' => 1, 'jan' => 1, 'februari' => 2, 'pebruari' => 2, 'feb' => 2, 'maret' => 3, 'mar' => 3,
        'april' => 4, 'apr' => 4, 'mei' => 5, 'juni' => 6, 'jun' => 6, 'juli' => 7, 'jul' => 7,
        'agustus' => 8, 'agu' => 8, 'agt' => 8, 'agst' => 8, 'september' => 9, 'sep' => 9, 'sept' => 9,
        'oktober' => 10, 'okt' => 10, 'november' => 11, 'nov' => 11, 'nop' => 11, 'desember' => 12, 'des' => 12,
    ];

    /** Kata pada nama kategori yang terlalu umum untuk dijadikan penanda. */
    private const STOPWORDS = [
        'biaya', 'persembahan', 'pengeluaran', 'pemasukan', 'gereja', 'lain', 'dan', 'ke', 'dari', 'kas',
        'support', 'pendapatan', 'mutasi', 'antar', 'masuk', 'keluar', 'kegiatan', 'untuk', 'atau',
    ];

    /** Frasa sehari-hari diseragamkan agar mudah dikenali. */
    private const SYNONYMS = [
        'uang keluar' => 'pengeluaran', 'uang masuk' => 'pemasukan', 'over budget' => 'lewat anggaran',
        'overbudget' => 'lewat anggaran', 'budget' => 'anggaran', 'bln' => 'bulan', 'thn' => 'tahun',
        'kemaren' => 'kemarin', 'brp' => 'berapa', 'gimana' => 'bagaimana', 'trx' => 'transaksi',
    ];

    /** @var Collection<int, Category>|null */
    private ?Collection $categories = null;

    public function parse(string $question, ?Carbon $now = null): ParsedQuestion
    {
        $now ??= now();
        $text = $this->normalize($question);

        [$categories, $group] = $this->matchCategory($text);

        return new ParsedQuestion(
            original: $question,
            text: $text,
            period: $this->matchPeriod($text, $now),
            categories: $categories,
            group: $group,
            accounts: $this->matchAccounts($text),
        );
    }

    public function normalize(string $question): string
    {
        $text = mb_strtolower($question);
        // Pertahankan tanda hubung di kode (5-205) & kata ulang (lain-lain), selain itu jadi spasi.
        $text = preg_replace('/[^a-z0-9\-\s]/u', ' ', $text);
        $text = preg_replace('/(?<![a-z0-9])-|-(?![a-z0-9])/', ' ', $text);
        $text = ' '.preg_replace('/\s+/', ' ', trim($text)).' ';

        foreach (self::SYNONYMS as $from => $to) {
            $text = str_replace(' '.$from.' ', ' '.$to.' ', $text);
        }

        return trim($text);
    }

    private function matchPeriod(string $text, Carbon $now): Period
    {
        $padded = ' '.$text.' ';
        $tokens = explode(' ', $text);
        $year = collect($tokens)->first(fn ($t) => preg_match('/^(20\d{2})$/', $t));

        // "N bulan terakhir" (mis. 6 bulan terakhir).
        if (preg_match('/ (\d{1,2}) bulan (terakhir|belakangan|ke belakang) /', $padded, $m) && (int) $m[1] >= 1) {
            return Period::lastMonths($now, min(36, (int) $m[1]));
        }

        // Frasa relatif: ambil yang paling awal disebut.
        $relative = [
            'setahun' => fn () => Period::lastMonths($now, 12),
            'satu tahun terakhir' => fn () => Period::lastMonths($now, 12),
            'setahun terakhir' => fn () => Period::lastMonths($now, 12),
            'sepanjang tahun' => fn () => Period::year($now->year),
            'hari ini' => fn () => new Period($now->copy()->startOfDay(), $now->copy()->endOfDay(), 'day'),
            'kemarin' => fn () => new Period($now->copy()->subDay()->startOfDay(), $now->copy()->subDay()->endOfDay(), 'day'),
            'minggu ini' => fn () => new Period($now->copy()->startOfWeek(), $now->copy()->endOfWeek(), 'week'),
            'bulan ini' => fn () => Period::month($now),
            'bulan sekarang' => fn () => Period::month($now),
            'bulan lalu' => fn () => Period::month($now->copy()->subMonthNoOverflow()),
            'bulan kemarin' => fn () => Period::month($now->copy()->subMonthNoOverflow()),
            'bulan sebelumnya' => fn () => Period::month($now->copy()->subMonthNoOverflow()),
            'tahun ini' => fn () => Period::year($now->year),
            'tahun lalu' => fn () => Period::year($now->year - 1),
            'tahun kemarin' => fn () => Period::year($now->year - 1),
        ];

        $found = collect($relative)
            ->map(fn ($make, $phrase) => ['pos' => strpos($padded, ' '.$phrase.' '), 'make' => $make])
            ->filter(fn ($m) => $m['pos'] !== false)
            ->sortBy('pos');

        // Nama bulan (mis. "september", "sept 2025").
        $month = null;
        $monthPos = PHP_INT_MAX;
        foreach (self::MONTHS as $name => $number) {
            $pos = strpos($padded, ' '.$name.' ');
            if ($pos !== false && $pos < $monthPos) {
                [$month, $monthPos] = [$number, $pos];
            }
        }

        if ($month !== null && ($found->isEmpty() || $monthPos < $found->first()['pos'])) {
            $y = $year ? (int) $year : ($month > $now->month ? $now->year - 1 : $now->year);

            return Period::month(Carbon::create($y, $month, 1));
        }

        if ($found->isNotEmpty()) {
            return ($found->first()['make'])();
        }

        if ($year) {
            return Period::year((int) $year);
        }

        return Period::month($now, explicit: false);
    }

    /** @return array{0: Collection<int, Category>, 1: ?string} */
    private function matchCategory(string $text): array
    {
        $categories = $this->categories ??= Category::query()
            ->where('nature', '!=', CategoryNature::Transfer->value)
            ->orderBy('code')
            ->get();
        $tokens = array_flip(explode(' ', $text));

        // 1. Kode kategori disebut langsung (mis. 5-205).
        $byCode = $categories->first(fn (Category $c) => isset($tokens[mb_strtolower($c->code)]));
        if ($byCode) {
            return [collect([$byCode]), null];
        }

        $distinct = fn (string $name) => collect(preg_split('/[\s\-&\/(),.]+/', mb_strtolower($name)))
            ->filter(fn ($w) => mb_strlen($w) >= 3 && ! in_array($w, self::STOPWORDS, true))
            ->unique()
            ->values();

        // 2. Kata khas pada nama kategori.
        $scored = $categories
            ->map(function (Category $c) use ($distinct, $tokens) {
                $words = $distinct($c->name);
                $hits = $words->filter(fn ($w) => isset($tokens[$w]))->count();

                return ['category' => $c, 'hits' => $hits, 'ratio' => $words->isEmpty() ? 0 : $hits / $words->count()];
            })
            ->filter(fn ($s) => $s['hits'] > 0)
            ->sortByDesc(fn ($s) => [$s['hits'], $s['ratio']]);

        // 3. Nama kelompok (mis. "komisi", "tunjangan").
        $group = $categories->pluck('group')->unique()->first(function (string $g) use ($distinct, $tokens) {
            $words = $distinct($g);

            return $words->isNotEmpty() && $words->every(fn ($w) => isset($tokens[$w]));
        });

        if ($scored->isEmpty()) {
            return $group ? [$categories->where('group', $group)->values(), $group] : [collect(), null];
        }

        $best = $scored->first();
        $ties = $scored->filter(fn ($s) => $s['hits'] === $best['hits']);

        // Beberapa kategori sama-sama cocok & semuanya satu kelompok yang disebut -> jumlahkan per kelompok.
        if ($ties->count() > 1 && $group && $ties->every(fn ($s) => $s['category']->group === $group)) {
            return [$categories->where('group', $group)->values(), $group];
        }

        return [collect([$best['category']]), null];
    }

    /** @return Collection<int, CashAccount> */
    private function matchAccounts(string $text): Collection
    {
        $padded = ' '.$text.' ';
        $accounts = CashAccount::query()->orderBy('code')->get();

        if (str_contains($padded, ' sekretariat ')) {
            return $accounts->filter(fn (CashAccount $a) => str_contains(mb_strtolower($a->name), 'sekretariat'))->values();
        }
        if (preg_match('/ (rekening|bank) /', $padded)) {
            return $accounts->where('type', CashAccountType::Bank)->values();
        }
        if (preg_match('/ (tunai|cash) /', $padded)) {
            return $accounts->where('type', CashAccountType::Cash)->values();
        }

        return collect();
    }
}
