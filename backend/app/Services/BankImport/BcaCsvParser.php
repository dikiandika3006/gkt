<?php

namespace App\Services\BankImport;

use App\Enums\TransactionType;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Parser CSV "Mutasi Rekening" KlikBCA Bisnis.
 * Header: No. rekening, Nama, Periode, Kode Mata Uang; lalu kolom
 * Tanggal Transaksi, Keterangan, Cabang, Jumlah ("1,500,000.00 CR"), Saldo.
 */
class BcaCsvParser
{
    /**
     * @return array{account_number: ?string, account_name: ?string, period: ?string, rows: list<array{key: string, date: string, type: string, amount: float, balance: ?float, description: string, remark: string, name: string}>}
     */
    public function parse(string $content): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $lines = preg_split('/\r\n|\r|\n/', $content);

        $meta = ['account_number' => null, 'account_name' => null, 'period' => null];
        $rows = [];

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            $cells = array_map(fn ($c) => trim((string) $c), str_getcsv($line, ',', '"', ''));

            if (preg_match('/^No\.?\s*rekening\s*:\s*(.+)$/i', $cells[0], $m)) {
                $meta['account_number'] = trim($m[1]);
            } elseif (preg_match('/^Nama\s*:\s*(.+)$/i', $cells[0], $m)) {
                $meta['account_name'] = trim($m[1]);
            } elseif (preg_match('/^Periode\s*:\s*(.+)$/i', $cells[0], $m)) {
                $meta['period'] = trim($m[1]);
            } elseif (count($cells) >= 4 && preg_match('#^\d{2}/\d{2}/\d{4}$#', $cells[0])) {
                $rows[] = $this->parseRow($cells, $meta['account_number']);
            }
        }

        if ($rows === []) {
            throw new RuntimeException('Format file tidak dikenali. Gunakan CSV mutasi rekening dari KlikBCA Bisnis.');
        }

        return [...$meta, 'rows' => $rows];
    }

    private function parseRow(array $cells, ?string $account): array
    {
        [$dateText, $description, , $amountText] = $cells;
        $balanceText = $cells[4] ?? '';

        if (! preg_match('/^([\d,]+(?:\.\d+)?)\s*(CR|DB)$/i', $amountText, $m)) {
            throw new RuntimeException("Nominal tidak dikenali: \"{$amountText}\".");
        }

        $amount = (float) str_replace(',', '', $m[1]);
        $type = strtoupper($m[2]) === 'CR' ? TransactionType::Income : TransactionType::Expense;
        $balance = $balanceText !== '' ? (float) str_replace(',', '', $balanceText) : null;
        $date = Carbon::createFromFormat('d/m/Y', $dateText)->toDateString();
        $description = preg_replace('/\s+$/', '', $description);

        [$remark, $name] = $this->splitDescription($description);

        return [
            'key' => sha1(implode('|', [preg_replace('/\D/', '', (string) $account), $date, preg_replace('/\s+/', ' ', $description), $amount, $balance])),
            'date' => $date,
            'type' => $type->value,
            'amount' => $amount,
            'balance' => $balance,
            'description' => $description,
            'remark' => $remark,
            'name' => $name,
        ];
    }

    /**
     * Keterangan BCA dipisah ≥2 spasi: [kode trx + nominal] [berita] [NAMA (maks 18 karakter)].
     *
     * @return array{0: string, 1: string} [berita, nama]
     */
    private function splitDescription(string $description): array
    {
        $parts = array_values(array_filter(preg_split('/\s{2,}/', trim($description)), fn ($p) => $p !== ''));

        if (str_starts_with($parts[0] ?? '', 'TRSF E-BANKING')) {
            $rest = array_slice($parts, 1);
            $name = count($rest) >= 2 ? array_pop($rest) : '';

            return [implode(' ', $rest), $name];
        }

        // BI-FAST: "... KE 535 NAMA PENERIMA" / "... DARI 014 NAMA PENGIRIM".
        foreach ($parts as $part) {
            if (preg_match('/^(?:KE|DARI)\s+\d{3}\s+(.+)$/', $part, $m)) {
                return [$parts[0], trim($m[1])];
            }
        }

        return [implode(' ', $parts), ''];
    }
}
