<?php

namespace App\Http\Requests;

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
            'from_cash_account_id' => [$isUpdate ? 'prohibited' : 'required', 'integer', $activeAccount],
            'to_cash_account_id' => [$isUpdate ? 'prohibited' : 'required', 'integer', 'different:from_cash_account_id', $activeAccount],
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
