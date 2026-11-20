<?php

namespace App\Services;

use App\Models\TitheMember;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;

/**
 * Import master nomor perpuluhan dari Excel/CSV (format sheet "Master No Perpuluhan": No | Nama | Kode | Alias).
 * Upsert per kode; baris tanpa nama (nomor kosong) dilewati.
 */
class TitheMemberImportService
{
    private const SHEET = 'master no perpuluhan';

    private const HEADERS = [
        'code' => ['kode', 'no perpuluhan', 'nomor perpuluhan', 'nomor'],
        'name' => ['nama', 'nama jemaat', 'name'],
        'alias' => ['alias', 'inisial'],
    ];

    /** @return array{created: int, updated: int, unchanged: int, skipped: int} */
    public function import(string $path, string $extension): array
    {
        $rows = $this->readRows($this->sheet($path, $extension));
        if ($rows === []) {
            throw new RuntimeException('Tidak ada data nomor perpuluhan yang bisa dibaca dari file.');
        }

        return DB::transaction(function () use ($rows) {
            $result = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0];
            $existing = TitheMember::whereIn('code', array_column(array_filter($rows), 'code'))->get()->keyBy('code');

            foreach ($rows as $row) {
                if ($row === null) {
                    $result['skipped']++;

                    continue;
                }

                $member = $existing->get($row['code']) ?? new TitheMember(['code' => $row['code'], 'is_active' => true]);
                $member->fill(['name' => $row['name'], 'alias' => $row['alias']]);

                if (! $member->exists) {
                    $member->save();
                    $existing->put($row['code'], $member);
                    $result['created']++;
                } elseif ($member->isDirty()) {
                    $member->save();
                    $result['updated']++;
                } else {
                    $result['unchanged']++;
                }
            }

            return $result;
        });
    }

    private function sheet(string $path, string $extension): Worksheet
    {
        try {
            $reader = in_array($extension, ['csv', 'txt'], true) ? new Csv : IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $book = $reader->load($path);
        } catch (\Throwable) {
            throw new RuntimeException('File tidak bisa dibaca. Pastikan formatnya XLSX, XLS, atau CSV.');
        }

        foreach ($book->getAllSheets() as $sheet) {
            if (Str::lower(trim($sheet->getTitle())) === self::SHEET) {
                return $sheet;
            }
        }

        return $book->getSheet(0);
    }

    /** @return list<array{code: int, name: string, alias: ?string}|null> null = baris dilewati */
    private function readRows(Worksheet $sheet): array
    {
        $grid = $sheet->toArray(null, true, false, false);
        [$headerIndex, $columns] = $this->detectHeader($grid);

        $rows = [];
        foreach (array_slice($grid, $headerIndex + 1) as $cells) {
            $code = $this->code($cells[$columns['code']] ?? null);
            $name = $this->text($cells[$columns['name']] ?? null);
            $alias = isset($columns['alias']) ? $this->text($cells[$columns['alias']] ?? null) : null;

            if ($code === null && $name === null) {
                continue; // baris kosong
            }

            $rows[] = ($code === null || $name === null)
                ? null
                : ['code' => $code, 'name' => Str::limit($name, 255, ''), 'alias' => $alias === null ? null : Str::limit(str_replace('/', '', $alias), 20, '')];
        }

        return $rows;
    }

    /** @return array{0: int, 1: array<string, int>} */
    private function detectHeader(array $grid): array
    {
        foreach (array_slice($grid, 0, 15, true) as $index => $cells) {
            $columns = [];
            foreach ($cells as $col => $value) {
                $label = Str::lower(trim(preg_replace('/[^a-z0-9 ]+/i', ' ', (string) $value)));
                $label = preg_replace('/\s+/', ' ', $label);
                foreach (self::HEADERS as $role => $synonyms) {
                    if (! isset($columns[$role]) && in_array($label, $synonyms, true)) {
                        $columns[$role] = $col;
                    }
                }
            }
            if (isset($columns['code'], $columns['name'])) {
                return [$index, $columns];
            }
        }

        throw new RuntimeException('Kolom "Nama" dan "Kode" tidak ditemukan di file.');
    }

    private function code(mixed $value): ?int
    {
        $value = trim((string) $value);
        if (! preg_match('/^0*(\d{1,6})(\.0+)?$/', $value, $m)) {
            return null;
        }

        return (int) $m[1] > 0 ? (int) $m[1] : null;
    }

    private function text(mixed $value): ?string
    {
        $value = preg_replace('/\s+/', ' ', trim((string) $value));

        return $value === '' ? null : $value;
    }
}
