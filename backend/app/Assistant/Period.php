<?php

namespace App\Assistant;

use App\Support\Format;
use Illuminate\Support\Carbon;

/** Rentang waktu yang disebut (atau diasumsikan) dalam pertanyaan. */
final class Period
{
    /** @param 'day'|'week'|'month'|'year'|'range' $kind  range = N bulan terakhir */
    public function __construct(
        public readonly Carbon $start,
        public readonly Carbon $end,
        public readonly string $kind,
        public readonly bool $explicit = true,
    ) {}

    public static function month(Carbon $date, bool $explicit = true): self
    {
        return new self($date->copy()->startOfMonth(), $date->copy()->endOfMonth(), 'month', $explicit);
    }

    public static function year(int $year): self
    {
        return new self(Carbon::create($year, 1, 1)->startOfDay(), Carbon::create($year, 12, 31)->endOfDay(), 'year');
    }

    /** $months bulan terakhir, termasuk bulan berjalan. */
    public static function lastMonths(Carbon $now, int $months): self
    {
        return new self($now->copy()->startOfMonth()->subMonthsNoOverflow($months - 1), $now->copy()->endOfMonth(), 'range');
    }

    public function months(): int
    {
        return (int) round($this->start->diffInMonths($this->end->copy()->addDay()));
    }

    /** Periode sebanding sebelumnya (bulan lalu, tahun lalu, dst). */
    public function previous(): self
    {
        return match ($this->kind) {
            'month' => self::month($this->start->copy()->subMonthNoOverflow()),
            'year' => self::year($this->start->year - 1),
            'week' => new self($this->start->copy()->subWeek(), $this->end->copy()->subWeek(), 'week'),
            'range' => new self(
                $this->start->copy()->subMonthsNoOverflow($this->months()),
                $this->start->copy()->subDay()->endOfDay(),
                'range',
            ),
            default => new self($this->start->copy()->subDay(), $this->end->copy()->subDay(), 'day'),
        };
    }

    public function label(): string
    {
        return match ($this->kind) {
            'month' => Format::month($this->start),
            'year' => 'tahun '.$this->start->year,
            'week' => 'minggu '.Format::date($this->start).' – '.Format::date($this->end),
            'range' => $this->end->gte(now()->startOfMonth())
                ? sprintf('%d bulan terakhir (%s – %s)', $this->months(), $this->shortMonth($this->start), $this->shortMonth($this->end))
                : sprintf('periode %s – %s', $this->shortMonth($this->start), $this->shortMonth($this->end)),
            default => Format::date($this->start),
        };
    }

    private function shortMonth(Carbon $date): string
    {
        return $date->copy()->locale('id')->translatedFormat('M Y');
    }
}
