<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Validasi pengajuan dana + rincian rencana. */
class FundRequestRequest extends FormRequest
{
    public function rules(): array
    {
        $fundRequest = $this->route('fund_request');

        return [
            'cash_account_id' => [
                $fundRequest ? 'prohibited' : 'required', 'integer',
                Rule::exists('cash_accounts', 'id')->where('is_active', true),
            ],
            'period' => [
                'required', 'date_format:Y-m',
                Rule::unique('fund_requests')
                    ->where('cash_account_id', $fundRequest?->cash_account_id ?? $this->input('cash_account_id'))
                    ->ignore($fundRequest),
            ],
            'request_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999.99'],
        ];
    }

    public function messages(): array
    {
        return [
            'period.unique' => 'Pengajuan untuk kas & periode ini sudah ada.',
            'items.required' => 'Isi minimal satu rincian rencana.',
        ];
    }
}
