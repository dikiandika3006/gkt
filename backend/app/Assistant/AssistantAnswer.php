<?php

namespace App\Assistant;

/** Jawaban asisten untuk satu pertanyaan. */
final class AssistantAnswer
{
    /**
     * @param  array<int, array{label: string, value: float, note?: string}>  $items  rincian angka
     * @param  string[]  $suggestions  pertanyaan lanjutan yang bisa diklik
     */
    public function __construct(
        public readonly string $text,
        public readonly array $items = [],
        public readonly array $suggestions = [],
        public readonly ?string $link = null,
        public readonly ?string $intent = null,
    ) {}

    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'items' => $this->items,
            'suggestions' => array_values(array_unique($this->suggestions)),
            'link' => $this->link,
            'intent' => $this->intent,
        ];
    }
}
