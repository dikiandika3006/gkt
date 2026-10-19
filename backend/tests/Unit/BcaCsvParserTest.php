<?php

namespace Tests\Unit;

use App\Services\BankImport\BcaCsvParser;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class BcaCsvParserTest extends TestCase
{
    public function test_parses_header_and_rows(): void
    {
        $result = (new BcaCsvParser)->parse(file_get_contents(__DIR__.'/../Fixtures/bca-mutasi.csv'));

        $this->assertSame('0190123456', $result['account_number']);
        $this->assertSame('GEREJA CONTOH', $result['account_name']);
        $this->assertCount(5, $result['rows']);

        [$transfer, $admin, $salary, $qris, $bifast] = $result['rows'];

        $this->assertSame(['2026-10-01', 'income', 500000.0, 10500000.0], [$transfer['date'], $transfer['type'], $transfer['amount'], $transfer['balance']]);
        $this->assertSame('Pers. 1/10 No 099', $transfer['remark']);
        $this->assertSame('BUDI SANTOSO', $transfer['name']);

        $this->assertSame(['expense', 15000.0, ''], [$admin['type'], $admin['amount'], $admin['name']]);
        $this->assertSame('ANDI PRATAMA', $salary['name']);
        $this->assertSame(222.0, $qris['amount']);
        $this->assertSame('CITRA LESTARI', $bifast['name']);

        $this->assertCount(5, array_unique(array_column($result['rows'], 'key')));
    }

    public function test_same_file_produces_same_keys(): void
    {
        $content = file_get_contents(__DIR__.'/../Fixtures/bca-mutasi.csv');

        $this->assertSame(
            array_column((new BcaCsvParser)->parse($content)['rows'], 'key'),
            array_column((new BcaCsvParser)->parse(str_replace("\n", "\r\n", $content))['rows'], 'key'),
        );
    }

    public function test_rejects_unknown_format(): void
    {
        $this->expectException(RuntimeException::class);

        (new BcaCsvParser)->parse("nama,jumlah\nabc,100\n");
    }
}
