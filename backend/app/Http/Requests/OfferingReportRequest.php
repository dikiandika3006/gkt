<?php

namespace App\Http\Requests;

use App\Enums\OfferingSection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class OfferingReportRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'service_date' => ['required', 'date'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:300'],
            'lines.*.section' => ['required', Rule::enum(OfferingSection::class)],
            'lines.*.label' => ['nullable', 'string', 'max:255'],
            'lines.*.name' => ['nullable', 'string', 'max:255'],
            'lines.*.amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999.99'],
            // Rekening tujuan ditentukan server dari pos; client hanya mengirim pos (jika ada).
            'lines.*.offering_fund_id' => ['nullable', 'integer', 'exists:offering_funds,id'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                foreach ((array) $this->input('lines', []) as $i => $line) {
                    $section = OfferingSection::tryFrom((string) ($line['section'] ?? ''));
                    if (! $section) {
                        continue;
                    }
                    if ($section->requiresName() && blank($line['name'] ?? null)) {
                        $validator->errors()->add("lines.{$i}.name", 'Nama / kode wajib diisi.');
                    }
                    if (! $section->requiresName() && blank($line['label'] ?? null)) {
                        $validator->errors()->add("lines.{$i}.label", 'Keterangan wajib diisi.');
                    }
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'lines.required' => 'Isi minimal satu baris persembahan.',
            'lines.*.amount.gt' => 'Jumlah harus lebih dari 0.',
            'period_end.after_or_equal' => 'Akhir periode tidak boleh sebelum awal periode.',
        ];
    }
}
