<?php

namespace App\Http\Requests;

use App\Rules\AccessibleCashAccount;
use Illuminate\Foundation\Http\FormRequest;

class BankReconciliationRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'cash_account_id' => ['required', 'integer', 'exists:cash_accounts,id', new AccessibleCashAccount],
            'month' => ['required', 'date_format:Y-m'],
            'statement_balance' => ['required', 'numeric', 'min:-9999999999999.99', 'max:9999999999999.99'],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return ['statement_balance.required' => 'Saldo menurut rekening koran / hitung fisik wajib diisi.'];
    }
}
