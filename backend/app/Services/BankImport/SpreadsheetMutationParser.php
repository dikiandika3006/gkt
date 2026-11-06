<?php

namespace App\Services\BankImport;

use App\Enums\TransactionType;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;

/**
 * Parser mutasi dari Excel/CSV dengan format bebas: baris judul, kolom & arah debit/kredit ditebak,
 * lalu bisa dikoreksi user lewat $options (sheet, header_row, mapping, direction).
 *
 * Arah: "bank" = sudut pandang rekening koran (Debet = uang keluar, Kredit = masuk);
 *       "book" = sudut pandang buku kas (Debit = masuk, Kredit = keluar).
 */
class SpreadsheetMutationParser
{
    public const ROLES = ['date', 'description', 'debit', 'credit', 'amount', 'direction', 'balance', 'pocket', 'name'];

    /** Sinonim judul kolom (huruf kecil, tanpa tanda baca). */
    private const SYNONYMS = [
        'date' => ['tanggal', 'tgl', 'date', 'tanggal transaksi', 'tgl transaksi', 'transaction date'],
        'description' => ['keterangan', 'uraian', 'deskripsi', 'description', 'remark', 'berita', 'ket'],
        'debit' => ['debet', 'debit', 'db', 'keluar', 'pengeluaran', 'mutasi debet', 'mutasi debit', 'uang keluar'],
        'credit' => ['kredit', 'credit', 'cr', 'masuk', 'pemasukan', 'mutasi kredit', 'uang masuk'],
        'amount' => ['jumlah', 'nominal', 'mutasi', 'amount', 'nilai'],
        'direction' => ['jenis', 'tipe', 'type', 'db cr', 'd k', 'dk'],
        'balance' => ['saldo', 'balance', 'saldo akhir'],
        'pocket' => ['pocket', 'group mutasi', 'group', 'kantong', 'pos dana'],
        'name' => ['nama', 'name', 'penerima', 'pengirim', 'nama pengirim', 'nama penerima'],
    ];

    private const MONTHS = [
        'jan' => 1, 'januari' => 1, 'january' => 1, 'feb' => 2, 'februari' => 2, 'february' => 2, 'mar' => 3, 'maret' => 3, 'march' => 3,
        'apr' => 4, 'april' => 4, 'mei' => 5, 'may' => 5, 'jun' => 6, 'juni' => 6, 'june' => 6, 'jul' => 7, 'juli' => 7, 'july' => 7,
        'agu' => 8, 'agt' => 8, 'agustus' => 8, 'aug' => 8, 'august' => 8, 'sep' => 9, 'sept' => 9, 'september' => 9,
        'okt' => 10, 'oktober' => 10, 'oct' => 10, 'october' => 10, 'nov' => 11, 'nopember' => 11, 'november' => 11,
        'des' => 12, 'desember' => 12, 'dec' => 12, 'december' => 12,
    ];

    /**
     * @param  array{sheet?: ?string, header_row?: ?int, mapping?: ?array<string, ?int>, direction?: ?string}  $options
     */
    public function parse(string $path, string $extension, array $options = []): array
    {
        $sheets = $this->load($path, $extension);
        $sheet = $sheets[$options['sheet'] ?? ''] ?? $this->bestSheet($sheets);

        $grid = $sheet->toArray(null, true, false, false);
        // toArray memakai nilai hasil hitung; untuk xlsx dari Excel nilai rumus tersimpan juga terbaca.
        $grid = array_map(fn ($row) => array_map(fn ($v) => is_string($v) ? trim($v) : $v, $row), $grid);

        $headerIndex = isset($options['header_row']) ? max(0, (int) $options['header_row'] - 1) : $this->detectHeaderRow($grid);
        $headers = array_map(fn ($v) => (string) $v, $grid[$headerIndex] ?? []);
        $mapping = $this->mergeMapping($this->detectMapping($headers), $options['mapping'] ?? null, count($headers));
        $meta = $this->meta(array_slice($grid, 0, $headerIndex));

        [$entries, $openings] = $this->entries(array_slice($grid, $headerIndex + 1, null, true), $mapping);

        $autoDirection = $this->detectDirection($entries, $openings, $mapping);
        $direction = in_array($options['direction'] ?? null, ['bank', 'book'], true) ? $options['direction'] : $autoDirection;

        $rows = $this->rows($entries, $direction, $meta['account_number']);

        return [
            ...$meta,
            'rows' => $rows,
            'openings' => $openings,
            'detection' => [
                'sheet' => $sheet->getTitle(),
                'sheets' => array_keys($sheets),
                'header_row' => $headerIndex + 1,
                'columns' => $this->columns($grid, $headerIndex, $headers),
                'mapping' => $mapping,
                'direction' => $direction,
                'direction_detected' => $autoDirection,
            ],
        ];
    }

    /** @return array<string, Worksheet> */
    private function load(string $path, string $extension): array
    {
        try {
            if (in_array($extension, ['csv', 'txt'], true)) {
                $reader = new Csv;
                $reader->setDelimiter($this->csvDelimiter($path));
                $reader->setInputEncoding(Csv::GUESS_ENCODING);
            } else {
                $reader = IOFactory::createReaderForFile($path);
            }
            $reader->setReadDataOnly(true);
            $book = $reader->load($path);
        } catch (\Throwable $e) {
            throw new RuntimeException('File tidak bisa dibaca. Pastikan formatnya CSV, XLSX, atau XLS.');
        }

        $sheets = [];
        foreach ($book->getAllSheets() as $sheet) {
            $sheets[$sheet->getTitle()] = $sheet;
        }

        return $sheets;
    }

    private function csvDelimiter(string $path): string
    {
        $sample = (string) file_get_contents($path, false, null, 0, 8192);
        $counts = [';' => substr_count($sample, ';'), ',' => substr_count($sample, ','), "\t" => substr_count($sample, "\t")];
        arsort($counts);

        return (string) array_key_first($counts);
    }

    /** Sheet dengan baris bertanggal terbanyak di bawah header yang terdeteksi. @param array<string, Worksheet> $sheets */
    private function bestSheet(array $sheets): Worksheet
    {
        $best = null;
        $bestScore = -1;
        foreach ($sheets as $sheet) {
            $grid = $sheet->toArray(null, true, false, false);
            $header = $this->detectHeaderRow($grid);
            $dateColumn = $this->detectMapping(array_map(fn ($v) => (string) $v, $grid[$header] ?? []))['date'];
            $score = 0;
            if ($dateColumn !== null && $this->headerScore(array_map(fn ($v) => (string) $v, $grid[$header] ?? [])) > 0) {
                foreach (array_slice($grid, $header + 1) as $row) {
                    $score += $this->date($row[$dateColumn] ?? null) !== null ? 1 : 0;
                }
            }
            if ($score > $bestScore) {
                [$best, $bestScore] = [$sheet, $score];
            }
        }

        return $best ?? throw new RuntimeException('File tidak berisi sheet.');
    }

    private function detectHeaderRow(array $grid): int
    {
        $best = 0;
        $bestScore = 0;
        foreach (array_slice($grid, 0, 30, true) as $i => $row) {
            $score = $this->headerScore(array_map(fn ($v) => (string) $v, $row));
            if ($score > $bestScore) {
                [$best, $bestScore] = [$i, $score];
            }
        }

        return $best;
    }

    /** Skor baris header: jumlah kolom yang cocok sinonim, wajib ada tanggal & nominal. */
    private function headerScore(array $cells): int
    {
        $roles = array_filter(array_map(fn ($c) => $this->roleOf($c)[0], $cells));
        $has = fn (string $role) => in_array($role, $roles, true);

        if (! $has('date') || ! ($has('debit') || $has('credit') || $has('amount'))) {
            return 0;
        }

        return count($roles);
    }

    /** @return array{0: ?string, 1: int} [peran, skor] — skor 2 = sama persis, 1 = mengandung. */
    private function roleOf(string $header): array
    {
        $text = $this->normalize($header);
        if ($text === '') {
            return [null, 0];
        }

        $best = [null, 0];
        foreach (self::SYNONYMS as $role => $words) {
            foreach ($words as $word) {
                $score = $text === $word ? 2 : (preg_match('/\b'.preg_quote($word, '/').'\b/', $text) ? 1 : 0);
                if ($score > $best[1]) {
                    $best = [$role, $score];
                }
            }
        }

        return $best;
    }

    private function normalize(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower($text))));
    }

    /** @return array<string, ?int> peran → indeks kolom (0-based) */
    private function detectMapping(array $headers): array
    {
        $candidates = [];
        foreach ($headers as $index => $header) {
            [$role, $score] = $this->roleOf($header);
            if (! $role) {
                continue;
            }
            $text = $this->normalize($header);
            // Saldo: utamakan saldo versi bank daripada saldo hasil hitung.
            if ($role === 'balance') {
                $score += preg_match('/bank|bca|menurut|rekening/', $text) ? 2 : (str_contains($text, 'hitung') ? -1 : 0);
            }
            $candidates[$role][] = [$index, $score];
        }

        $mapping = array_fill_keys(self::ROLES, null);
        foreach ($candidates as $role => $list) {
            usort($list, fn ($a, $b) => $b[1] <=> $a[1] ?: $a[0] <=> $b[0]);
            $mapping[$role] = $list[0][0];
        }

        return $mapping;
    }

    /** Override dari layar menang; indeks di luar kolom diabaikan. */
    private function mergeMapping(array $detected, ?array $override, int $columnCount): array
    {
        if (! $override) {
            return $detected;
        }

        $mapping = array_fill_keys(self::ROLES, null);
        foreach (self::ROLES as $role) {
            $value = $override[$role] ?? null;
            $mapping[$role] = is_numeric($value) && (int) $value >= 0 && (int) $value < max($columnCount, 1) ? (int) $value : null;
        }

        return $mapping;
    }

    /** No. rekening, nama, periode dari baris judul di atas header. */
    private function meta(array $rowsAbove): array
    {
        $meta = ['account_number' => null, 'account_name' => null, 'period' => null];
        foreach ($rowsAbove as $row) {
            $text = implode(' ', array_filter(array_map(fn ($v) => (string) $v, $row)));
            if (! $meta['account_number'] && preg_match('/rek(?:ening)?\.?\s*:?\s*([\d][\d\-. ]{5,}\d)/i', $text, $m)) {
                $meta['account_number'] = trim($m[1]);
            }
            if (! $meta['period'] && preg_match('/periode\s*:?\s*([^|]+)/i', $text, $m)) {
                $meta['period'] = trim($m[1]);
            }
            if (! $meta['account_name'] && preg_match('/^nama\s*:\s*([^|]+)/i', $text, $m)) {
                $meta['account_name'] = trim($m[1]);
            }
        }

        return $meta;
    }

    /**
     * Baris data mentah (sebelum arah ditentukan) + saldo awal dari file.
     *
     * @return array{0: list<array<string, mixed>|null>, 1: list<array{pocket: ?string, amount: float}>}
     */
    private function entries(array $rows, array $mapping): array
    {
        $cell = fn (array $row, string $role) => $mapping[$role] !== null ? ($row[$mapping[$role]] ?? null) : null;
        $entries = [];
        $openings = [];

        foreach ($rows as $line => $row) {
            if (! array_filter($row, fn ($v) => $v !== null && $v !== '')) {
                continue;
            }

            $description = trim((string) $cell($row, 'description'));
            $pocket = trim((string) $cell($row, 'pocket')) ?: null;
            $debit = $this->number($cell($row, 'debit'));
            $credit = $this->number($cell($row, 'credit'));
            $balance = $this->number($cell($row, 'balance'));
            $balance = $balance === null ? null : round($balance, 2);

            if (str_starts_with(mb_strtolower($description), 'saldo awal')) {
                if ($pocket !== null && ($debit !== null || $credit !== null)) {
                    // Excel buku kas: saldo awal pocket ada di kolom Debit/Kredit (kolom Saldo = saldo berjalan rekening).
                    $amount = ($debit ?? 0) - ($credit ?? 0);
                } else {
                    // Rekening koran: saldo awal bisa ada di kolom saldo mana pun (mis. "Saldo (hitung)").
                    $numbers = array_filter(array_map(fn ($v) => is_numeric($v) ? (float) $v : null, $row), fn ($v) => $v !== null);
                    $amount = $balance ?? (reset($numbers) ?: 0.0);
                }
                $openings[] = ['pocket' => $pocket, 'amount' => round((float) $amount, 2), 'line' => $line + 1];

                continue;
            }

            $date = $this->date($cell($row, 'date'));
            if (! $date) {
                $entries[] = null; // judul/total/ringkasan

                continue;
            }

            $amount = $this->number($cell($row, 'amount'));
            $sign = $this->directionSign($cell($row, 'direction'), $cell($row, 'amount'));

            $entries[] = [
                'line' => $line + 1,
                'date' => $date,
                'description' => $description,
                'name' => trim((string) $cell($row, 'name')),
                'pocket' => $pocket,
                'debit' => $debit,
                'credit' => $credit,
                'amount' => $amount,
                'sign' => $sign,
                'balance' => $balance,
            ];
        }

        // Saldo awal ganda per pocket (mis. 2024 = 0 lalu 2025 = nilai): pakai yang terakhir.
        $last = [];
        foreach ($openings as $o) {
            $last[$o['pocket'] ?? ''] = ['pocket' => $o['pocket'], 'amount' => $o['amount']];
        }

        return [$entries, array_values($last)];
    }

    /** +1 masuk, −1 keluar, null tidak diketahui. */
    private function directionSign(mixed $direction, mixed $rawAmount): ?int
    {
        $text = mb_strtolower(trim((string) $direction));
        if ($text !== '') {
            if (preg_match('/^(cr|k|kredit|credit|masuk|in|\+)/', $text)) {
                return 1;
            }
            if (preg_match('/^(db|d|debet|debit|keluar|out|-)/', $text)) {
                return -1;
            }
        }

        $raw = trim((string) $rawAmount);
        if (preg_match('/\b(CR)\b/i', $raw)) {
            return 1;
        }
        if (preg_match('/\b(DB)\b/i', $raw)) {
            return -1;
        }

        $value = $this->number($rawAmount);

        return $value === null ? null : ($value < 0 ? -1 : 1);
    }

    /** Uji saldo berjalan: kredit menambah saldo → "bank", debit menambah saldo → "book". */
    private function detectDirection(array $entries, array $openings, array $mapping): string
    {
        $valid = array_values(array_filter($entries));
        if ($mapping['balance'] === null || ($mapping['debit'] === null && $mapping['credit'] === null)) {
            return 'bank';
        }

        $bank = 0;
        $book = 0;
        $prev = count($openings) === 1 && $openings[0]['pocket'] === null ? $openings[0]['amount'] : null;
        foreach ($valid as $e) {
            if ($e['balance'] === null) {
                continue;
            }
            if ($prev !== null) {
                $d = (float) ($e['debit'] ?? 0);
                $c = (float) ($e['credit'] ?? 0);
                if (abs($prev + $c - $d - $e['balance']) < 0.02) {
                    $bank++;
                } elseif (abs($prev + $d - $c - $e['balance']) < 0.02) {
                    $book++;
                }
            }
            $prev = $e['balance'];
        }

        return $book > $bank ? 'book' : 'bank';
    }

    /** @return list<array{key: string, date: string, type: string, amount: float, balance: ?float, description: string, remark: string, name: string, pocket: ?string}> */
    private function rows(array $entries, string $direction, ?string $account): array
    {
        $rows = [];
        $seen = [];
        $digits = preg_replace('/\D/', '', (string) $account);

        foreach (array_filter($entries) as $e) {
            $legs = [];
            if ($e['debit'] !== null || $e['credit'] !== null) {
                $in = $direction === 'bank' ? $e['credit'] : $e['debit'];
                $out = $direction === 'bank' ? $e['debit'] : $e['credit'];
                if ($in) {
                    $legs[] = [TransactionType::Income, abs($in)];
                }
                if ($out) {
                    $legs[] = [TransactionType::Expense, abs($out)];
                }
            } elseif ($e['amount']) {
                $sign = $e['sign'] ?? 1;
                $legs[] = [$sign > 0 ? TransactionType::Income : TransactionType::Expense, abs($e['amount'])];
            }

            foreach ($legs as [$type, $amount]) {
                $amount = round((float) $amount, 2);
                $description = preg_replace('/\s+/', ' ', $e['description']) ?: ($e['pocket'] ?? 'Mutasi');
                $base = implode('|', [$digits, $e['date'], $description, $amount, $e['balance'], $e['pocket'], $type->value]);
                $seen[$base] = ($seen[$base] ?? 0) + 1;

                [$remark, $name] = (new BcaCsvParser)->splitDescription($e['description']);

                $rows[] = [
                    'key' => sha1($base.'|'.$seen[$base]),
                    'date' => $e['date'],
                    'type' => $type->value,
                    'amount' => $amount,
                    'balance' => $e['balance'],
                    'description' => mb_substr($description, 0, 500),
                    'remark' => $remark,
                    'name' => $e['name'] !== '' ? $e['name'] : $name,
                    'pocket' => $e['pocket'],
                ];
            }
        }

        return $rows;
    }

    /** Daftar kolom + contoh nilai untuk layar pemetaan. */
    private function columns(array $grid, int $headerIndex, array $headers): array
    {
        $width = max(count($headers), ...array_map('count', array_slice($grid, $headerIndex, 6)));
        $samples = array_slice($grid, $headerIndex + 1, 40);
        $columns = [];

        for ($i = 0; $i < $width; $i++) {
            $values = [];
            foreach ($samples as $row) {
                $v = $row[$i] ?? null;
                if ($v !== null && $v !== '') {
                    $values[] = mb_substr((string) $v, 0, 40);
                }
                if (count($values) >= 2) {
                    break;
                }
            }
            $columns[] = ['index' => $i, 'letter' => Coordinate::stringFromColumnIndex($i + 1), 'header' => (string) ($headers[$i] ?? ''), 'samples' => $values];
        }

        return $columns;
    }

    /** Angka format Indonesia/Inggris: "1.234.567,89", "1,234,567.89", "Rp 1.000", "(15.000)", "-15000". */
    public function number(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $text = strtoupper(trim((string) $value));
        $negative = str_starts_with($text, '(') && str_ends_with($text, ')') || str_starts_with($text, '-') || str_ends_with($text, '-');
        $text = preg_replace('/[^0-9.,]/', '', $text);
        if ($text === '' || ! preg_match('/\d/', $text)) {
            return null;
        }

        $lastDot = strrpos($text, '.');
        $lastComma = strrpos($text, ',');
        if ($lastDot !== false && $lastComma !== false) {
            // Pemisah desimal = yang paling kanan.
            $decimal = $lastDot > $lastComma ? '.' : ',';
        } elseif ($lastComma !== false) {
            // "1,5" / "1000,25" = desimal; "1,000,000" / "15,000" = ribuan.
            $decimal = substr_count($text, ',') === 1 && strlen($text) - $lastComma - 1 <= 2 ? ',' : null;
        } elseif ($lastDot !== false) {
            $decimal = substr_count($text, '.') === 1 && strlen($text) - $lastDot - 1 <= 2 ? '.' : null;
        } else {
            $decimal = null;
        }

        $thousand = $decimal === '.' ? ',' : '.';
        $text = str_replace($decimal === null ? ['.', ','] : [$thousand], '', $text);
        if ($decimal === ',') {
            $text = str_replace(',', '.', $text);
        }

        $number = (float) $text;

        return $negative ? -$number : $number;
    }

    /** Tanggal: serial Excel, d/m/Y, d-m-Y, Y-m-d, "31 Des 2025". */
    public function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            $serial = (float) $value;

            return $serial > 20000 && $serial < 80000 ? Carbon::instance(ExcelDate::excelToDateTimeObject($serial))->toDateString() : null;
        }

        $text = mb_strtolower(trim((string) $value));
        if (preg_match('#^(\d{4})[-/.](\d{1,2})[-/.](\d{1,2})#', $text, $m)) {
            return $this->ymd((int) $m[1], (int) $m[2], (int) $m[3]);
        }
        if (preg_match('#^(\d{1,2})[-/.](\d{1,2})[-/.](\d{2,4})#', $text, $m)) {
            $year = (int) $m[3] < 100 ? 2000 + (int) $m[3] : (int) $m[3];

            return $this->ymd($year, (int) $m[2], (int) $m[1]);
        }
        if (preg_match('/^(\d{1,2})[\s\-]+([a-z]+)[\s\-]+(\d{2,4})/', $text, $m) && isset(self::MONTHS[$m[2]])) {
            $year = (int) $m[3] < 100 ? 2000 + (int) $m[3] : (int) $m[3];

            return $this->ymd($year, self::MONTHS[$m[2]], (int) $m[1]);
        }

        return null;
    }

    private function ymd(int $year, int $month, int $day): ?string
    {
        return checkdate($month, $day, $year) && $year >= 1990 && $year <= 2100 ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null;
    }
}
