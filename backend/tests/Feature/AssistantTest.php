<?php

namespace Tests\Feature;

use App\Assistant\QuestionParser;
use App\Enums\CashAccountType;
use App\Enums\TransactionType;
use App\Enums\UserRole;
use App\Models\Budget;
use App\Models\CashAccount;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AssistantTest extends TestCase
{
    use RefreshDatabase;

    private CashAccount $bank;

    private Category $listrik;

    private Category $atk;

    private Category $perpuluhan;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Viewer]));

        $this->bank = CashAccount::factory()->create(['name' => 'Rekening Gereja', 'type' => CashAccountType::Bank, 'opening_balance' => 10_000_000]);
        CashAccount::factory()->create(['name' => 'Kas Sekretariat', 'type' => CashAccountType::Cash, 'opening_balance' => 500_000]);
        $this->listrik = Category::factory()->expense()->create(['code' => '5-205', 'name' => 'Biaya Listrik', 'group' => 'Pengeluaran']);
        $this->atk = Category::factory()->expense()->create(['code' => '5-201', 'name' => 'Alat Tulis Kantor (ATK)', 'group' => 'Pengeluaran']);
        Category::factory()->expense()->create(['code' => '5-100', 'name' => 'Tunjangan Hamba Tuhan', 'group' => 'Tunjangan']);
        Category::factory()->expense()->create(['code' => '5-110', 'name' => 'Tunjangan Pekerja Gereja', 'group' => 'Tunjangan']);
        $this->perpuluhan = Category::factory()->create(['code' => '4-100', 'name' => 'Perpuluhan', 'group' => 'Perpuluhan']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function tx(Category $category, float $amount, string $date, TransactionType $type = TransactionType::Expense): void
    {
        Transaction::factory()->create([
            'date' => $date, 'type' => $type, 'category_id' => $category->id, 'cash_account_id' => $this->bank->id, 'amount' => $amount,
        ]);
    }

    private function ask(string $question): array
    {
        return $this->postJson('/api/assistant/ask', ['question' => $question])->assertOk()->json();
    }

    public function test_parser_understands_periods_categories_groups_and_accounts(): void
    {
        $parser = app(QuestionParser::class);

        $q = $parser->parse('pengeluaran listrik september');
        $this->assertSame('2026-09-01', $q->period->start->toDateString());
        $this->assertSame('Biaya Listrik', $q->categories->first()->name);

        $this->assertSame('2025-12-01', $parser->parse('atk desember')->period->start->toDateString(), 'bulan yang belum lewat = tahun lalu');
        $this->assertSame('2025-01-01', $parser->parse('pemasukan tahun lalu')->period->start->toDateString());
        $this->assertSame('year', $parser->parse('biaya listrik 2024')->period->kind);
        $this->assertSame('2026-09-01', $parser->parse('saldo bulan kemarin')->period->start->toDateString());
        $this->assertFalse($parser->parse('saldo')->period->explicit);

        $this->assertSame('Biaya Listrik', $parser->parse('5-205 bulan ini')->categories->first()->name);
        $this->assertSame('Alat Tulis Kantor (ATK)', $parser->parse('belanja atk')->categories->first()->name);

        $group = $parser->parse('tunjangan bulan ini');
        $this->assertSame('Tunjangan', $group->group);
        $this->assertCount(2, $group->categories);

        $this->assertSame('Kas Sekretariat', $parser->parse('saldo kas sekretariat')->accounts->first()->name);
        $this->assertSame('Rekening Gereja', $parser->parse('saldo rekening')->accounts->first()->name);
    }

    public function test_totals_top_compare_and_net_match_the_data(): void
    {
        $this->tx($this->listrik, 1_000_000, '2026-09-10');
        $this->tx($this->listrik, 1_500_000, '2026-10-10');
        $this->tx($this->atk, 300_000, '2026-10-11');
        $this->tx($this->perpuluhan, 5_000_000, '2026-10-01', TransactionType::Income);

        $answer = $this->ask('Berapa pengeluaran listrik bulan ini?');
        $this->assertSame('total', $answer['intent']);
        $this->assertStringContainsString('Rp 1.500.000', $answer['text']);

        $answer = $this->ask('pengeluaran terbesar bulan ini');
        $this->assertSame('top', $answer['intent']);
        $this->assertSame('Biaya Listrik', $answer['items'][0]['label']);

        $answer = $this->ask('pengeluaran listrik bulan ini dibanding bulan lalu');
        $this->assertSame('compare', $answer['intent']);
        $this->assertStringContainsString('naik 50,0%', $answer['text']);

        $answer = $this->ask('bulan ini surplus atau defisit?');
        $this->assertSame('net', $answer['intent']);
        $this->assertStringContainsString('SURPLUS Rp 3.200.000', $answer['text']);

        $answer = $this->ask('pemasukan bulan ini');
        $this->assertStringContainsString('Pemasukan operasional Oktober 2026', $answer['text']);
        $this->assertStringContainsString('Rp 5.000.000', $answer['text']);
    }

    public function test_balance_runway_budget_recent_and_help(): void
    {
        $this->tx($this->listrik, 1_500_000, '2026-02-10');
        Budget::create(['year' => 2026, 'category_id' => $this->listrik->id, 'type' => 'expense', 'amount' => 1_000_000]);

        $balance = $this->ask('saldonya berapa?');
        $this->assertSame('balance', $balance['intent']);
        $this->assertStringContainsString('Rp 9.000.000', $balance['text']); // 10 jt + 0,5 jt - 1,5 jt

        $this->assertSame('runway', $this->ask('saldo cukup untuk berapa bulan?')['intent']);

        $budget = $this->ask('pos mana yang over budget?');
        $this->assertSame('budget', $budget['intent']);
        $this->assertStringContainsString('1 pos melewati anggaran', $budget['text']);

        $recent = $this->ask('transaksi terakhir listrik');
        $this->assertSame('recent', $recent['intent']);
        $this->assertCount(1, $recent['items']);

        $this->assertNotEmpty($this->ask('bisa tanya apa saja?')['suggestions']);
    }

    public function test_peak_of_one_category_over_rolling_year(): void
    {
        $parser = app(QuestionParser::class);
        $year = $parser->parse('listrik setahun')->period;
        $this->assertSame('range', $year->kind);
        $this->assertSame('2025-11-01', $year->start->toDateString());
        $this->assertSame(6, $parser->parse('pengeluaran 6 bulan terakhir')->period->months());

        $this->tx($this->listrik, 1_000_000, '2026-01-14');
        $this->tx($this->listrik, 700_000, '2026-02-14');
        $this->tx($this->listrik, 2_500_000, '2026-02-25'); // Februari = bulan terbesar, transaksi terbesar
        $this->tx($this->listrik, 9_000_000, '2025-06-14'); // di luar 12 bulan terakhir

        $answer = $this->ask('pengeluaran listrik terbesar setahun');
        $this->assertSame('top', $answer['intent']);
        $this->assertStringContainsString('Februari 2026 sebesar Rp 3.200.000', $answer['text']);
        $this->assertStringContainsString('Rp 2.500.000', $answer['text']);
        $this->assertSame('Februari 2026', $answer['items'][0]['label']);

        // "terakhir" pada periode tidak boleh dibaca sebagai "transaksi terakhir".
        $this->assertSame('total', $this->ask('pengeluaran listrik 3 bulan terakhir')['intent']);
    }

    public function test_unknown_question_falls_back_with_suggestions_and_input_is_validated(): void
    {
        $answer = $this->ask('bagaimana cuaca besok?');
        $this->assertSame('fallback', $answer['intent']);
        $this->assertNotEmpty($answer['suggestions']);

        $this->postJson('/api/assistant/ask', ['question' => str_repeat('a', 301)])->assertUnprocessable();
        $this->postJson('/api/assistant/ask', [])->assertUnprocessable();
    }
}
