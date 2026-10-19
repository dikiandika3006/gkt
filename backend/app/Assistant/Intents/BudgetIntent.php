<?php

namespace App\Assistant\Intents;

use App\Assistant\AssistantAnswer;
use App\Assistant\ParsedQuestion;
use App\Support\Format;

/** "Pos mana yang over budget?", "Anggaran listrik sisa berapa?" */
class BudgetIntent extends BaseIntent
{
    public function score(ParsedQuestion $q): int
    {
        return $q->has('anggaran', 'rapb', 'lewat anggaran', 'over') ? 5 : 0;
    }

    public function answer(ParsedQuestion $q): AssistantAnswer
    {
        $year = $q->period->kind === 'year' ? $q->period->start->year : ($q->period->explicit ? $q->period->start->year : now()->year);
        $report = $this->budgets->realization($year);
        $rows = $report['rows'];

        if ($rows->isEmpty()) {
            return new AssistantAnswer("Anggaran tahun {$year} belum diisi. Isi di menu Anggaran agar bisa dipantau.", link: '/budgets', intent: 'budget');
        }

        $item = fn (array $r) => ['label' => ($r['category']['name'] ?? '-').' ('.$r['type_label'].')', 'value' => $r['actual'], 'note' => Format::percent($r['percentage'], 0).' dari '.Format::rupiah($r['amount'])];

        if ($q->hasSubject()) {
            $matched = $rows->whereIn('category_id', $q->categories->pluck('id'));
            if ($matched->isEmpty()) {
                return new AssistantAnswer("Belum ada anggaran {$year} untuk {$q->subjectLabel()}.", link: '/budgets', intent: 'budget');
            }
            $r = $matched->first();

            return new AssistantAnswer(
                sprintf(
                    'Anggaran %s %d: %s. Realisasi %s (%s), sisa %s. Status: %s.',
                    $r['category']['name'] ?? '-',
                    $year,
                    Format::rupiah($r['amount']),
                    Format::rupiah($r['actual']),
                    Format::percent($r['percentage']),
                    Format::rupiah($r['remaining']),
                    $r['status'],
                ),
                $matched->map($item)->values()->all(),
                ['Pos mana yang over budget?'],
                '/budgets',
                'budget',
            );
        }

        $flagged = $rows->whereIn('status', ['lewat', 'waspada', 'tertinggal'])->sortByDesc('percentage');
        $totals = $report['totals'];
        $summary = sprintf(
            'Anggaran pengeluaran %d: terpakai %s dari %s.',
            $year,
            Format::rupiah($totals['expense_actual']),
            Format::rupiah($totals['expense_budget']),
        );

        if ($flagged->isEmpty()) {
            return new AssistantAnswer($summary.' Semua pos masih dalam batas anggaran.', link: '/budgets', intent: 'budget');
        }

        $over = $rows->where('status', 'lewat')->count();

        return new AssistantAnswer(
            $summary.sprintf(' %d pos melewati anggaran, %d perlu diwaspadai.', $over, $flagged->count() - $over),
            $flagged->take(8)->map($item)->values()->all(),
            $flagged->take(2)->map(fn ($r) => 'Transaksi terakhir '.($r['category']['name'] ?? ''))->values()->all(),
            '/budgets',
            'budget',
        );
    }

    public function examples(): array
    {
        return ['Pos mana yang over budget?', 'Anggaran listrik sisa berapa?'];
    }
}
