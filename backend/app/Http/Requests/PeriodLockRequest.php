<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PeriodLockRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'month' => ['required', 'date_format:Y-m', 'unique:period_locks,month', 'before_or_equal:'.now()->format('Y-m')],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'month.unique' => 'Bulan ini sudah ditutup buku.',
            'month.before_or_equal' => 'Bulan yang belum berjalan tidak bisa ditutup buku.',
        ];
    }
}
