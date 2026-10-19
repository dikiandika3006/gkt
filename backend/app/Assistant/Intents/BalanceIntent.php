<?php

namespace App\Assistant\Intents;

use App\Assistant\AssistantAnswer;
use App\Assistant\ParsedQuestion;
use App\Support\Format;

/** "Berapa saldo rekening?", "Uang kas sekarang ada berapa?" */
class BalanceIntent extends BaseIntent
{
    public function score(ParsedQuestion $q): int
    {
        return $q->has('saldo', 'sisa uang', 'uang kas', 'posisi kas', 'uang gereja', 'kas sekarang', 'dana sekarang') ? 4 : 0;
    }

    public function answer(ParsedQuestion $q): AssistantAnswer
    {
        $balances = $this->report->balancesPerAccount();
        $selected = $q->accounts->isNotEmpty() ? $balances->whereIn('id', $q->accounts->pluck('id')) : $balances;
        $total = (float) $selected->sum('balance');

        $text = $q->accounts->isNotEmpty()
            ? sprintf('Saldo %s saat ini: %s.', $selected->pluck('name')->join(', ', ' & '), Format::rupiah($total))
            : sprintf('Total saldo semua kas & rekening saat ini: %s.', Format::rupiah($total));

        return new AssistantAnswer(
            $text,
            $selected->map(fn ($b) => ['label' => $b['name'], 'value' => $b['balance']])->values()->all(),
            ['Saldo cukup untuk berapa bulan?', 'Bulan ini surplus atau defisit?'],
            '/cash-accounts',
            'balance',
        );
    }

    public function examples(): array
    {
        return ['Berapa saldo sekarang?', 'Saldo rekening bank berapa?'];
    }
}
