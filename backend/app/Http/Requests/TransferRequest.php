<?php

namespace App\Http\Requests;

use App\Rules\AccessibleCashAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransferRequest extends FormRequest
{
    public function rules(): array
    {
        $isUpdate = $this->route('group') !== null;
        $activeAccount = Rule::exists('cash_accounts', 'id')->where('is_active', true);

        return [
            'date' => ['required', 'date'],
            'from_cash_account_id' => [$isUpdate ? 'prohibited' : 'required', 'integer', $activeAccount, new AccessibleCashAccount],
            'to_cash_account_id' => [$isUpdate ? 'prohibited' : 'required', 'integer', 'different:from_cash_account_id', $activeAccount, new AccessibleCashAccount],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999.99'],
            'description' => ['required', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return ['to_cash_account_id.different' => 'Kas tujuan harus berbeda dari kas asal.'];
    }
}
