<?php

namespace App\Http\Requests;

use App\Enums\CashAccountType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CashAccountRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:20', Rule::unique('cash_accounts')->ignore($this->route('cash_account'))],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(CashAccountType::class)],
            'bank_name' => ['nullable', 'required_if:type,bank', 'string', 'max:255'],
            'account_number' => ['nullable', 'string', 'max:50'],
            'opening_balance' => ['required', 'numeric', 'min:0', 'max:9999999999999.99'],
            'is_active' => ['boolean'],
        ];
    }
}
