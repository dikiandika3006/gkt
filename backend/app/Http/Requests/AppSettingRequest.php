<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AppSettingRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'dashboard_cash_account_id' => ['present', 'nullable', 'integer', Rule::exists('cash_accounts', 'id')],
        ];
    }

    public function messages(): array
    {
        return [
            'dashboard_cash_account_id.present' => 'Pilih rekening yang tampil di dashboard.',
            'dashboard_cash_account_id.exists' => 'Kas/rekening yang dipilih tidak ditemukan.',
        ];
    }
}
