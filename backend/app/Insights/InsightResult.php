<?php

namespace App\Insights;

/** Hasil satu insight untuk ditampilkan sebagai kartu di Dashboard. */
final class InsightResult
{
    public const SEVERITIES = ['danger', 'warning', 'info', 'success'];

    /**
     * @param  'danger'|'warning'|'info'|'success'  $severity
     * @param  array<int, array{label: string, value: float, note?: string}>  $items  rincian opsional (mis. daftar kategori)
     */
    public function __construct(
        public readonly string $key,
        public readonly string $severity,
        public readonly string $title,
        public readonly string $message,
        public readonly ?string $recommendation = null,
        public readonly ?string $link = null,
        public readonly array $items = [],
    ) {
        if (! in_array($severity, self::SEVERITIES, true)) {
            throw new \InvalidArgumentException("Severity tidak dikenal: {$severity}");
        }
    }

    public function rank(): int
    {
        return array_search($this->severity, self::SEVERITIES, true);
    }

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'severity' => $this->severity,
            'title' => $this->title,
            'message' => $this->message,
            'recommendation' => $this->recommendation,
            'link' => $this->link,
            'items' => $this->items,
        ];
    }
}
