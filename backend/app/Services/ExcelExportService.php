<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Membuat file .xlsx sederhana dari baris-baris laporan. */
class ExcelExportService
{
    private const MONEY = '#,##0;[Red]-#,##0';

    /**
     * money_from = indeks kolom (0-based) mulai format angka rupiah.
     *
     * @param  array<int, array{title: string, heading?: string[], headers: string[], rows: array<int, array<int, mixed>>, money_from?: int}>  $sheets
     */
    public function download(string $filename, array $sheets): StreamedResponse
    {
        $book = new Spreadsheet;
        $book->removeSheetByIndex(0);

        foreach ($sheets as $sheet) {
            $this->fillSheet($book->createSheet(), $sheet);
        }

        $book->setActiveSheetIndex(0);

        return new StreamedResponse(function () use ($book) {
            (new Xlsx($book))->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'max-age=0',
        ]);
    }

    private function fillSheet(Worksheet $ws, array $sheet): void
    {
        $ws->setTitle(mb_substr($sheet['title'], 0, 31));

        $row = 1;
        foreach ($sheet['heading'] ?? [] as $line) {
            $ws->setCellValue([1, $row], $line);
            $ws->getStyle([1, $row])->getFont()->setBold($row === 1);
            $row++;
        }
        if ($row > 1) {
            $row++;
        }

        $headerRow = $row;
        $ws->fromArray($sheet['headers'], null, 'A'.$headerRow);
        $lastCol = count($sheet['headers']);
        $ws->getStyle([1, $headerRow, $lastCol, $headerRow])->getFont()->setBold(true);

        foreach (array_values($sheet['rows']) as $r => $cells) {
            foreach (array_values($cells) as $c => $value) {
                $coordinate = [$c + 1, $headerRow + 1 + $r];
                // String ditulis eksplisit sebagai teks agar tidak dieksekusi sebagai formula (formula injection).
                is_string($value)
                    ? $ws->setCellValueExplicit($coordinate, $value, DataType::TYPE_STRING)
                    : $ws->setCellValue($coordinate, $value);
            }
        }
        $lastRow = $headerRow + max(1, count($sheet['rows']));

        if (isset($sheet['money_from'])) {
            $ws->getStyle([$sheet['money_from'] + 1, $headerRow + 1, $lastCol, $lastRow])
                ->getNumberFormat()->setFormatCode(self::MONEY);
        }

        foreach (range(1, $lastCol) as $col) {
            $ws->getColumnDimensionByColumn($col)->setAutoSize(true);
        }
        $ws->freezePane([1, $headerRow + 1]);
    }
}
