<?php

namespace App\Http\Requests;

use App\Enums\CashAccountType;
use App\Models\CashAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            // Rekening ber-pocket: saldo awal = Σ saldo awal pocket (dihitung di service).
            'opening_balance' => ['exclude_if:has_pockets,true', 'required', 'numeric', 'min:0', 'max:9999999999999.99'],
            'is_active' => ['boolean'],
            'has_pockets' => ['boolean'],
            'pockets' => ['exclude_unless:has_pockets,true', 'required', 'array', 'min:1', 'max:50'],
            'pockets.*.id' => ['nullable', 'integer'],
            'pockets.*.name' => ['required', 'string', 'max:100', 'distinct:ignore_case'],
            'pockets.*.opening_balance' => ['required', 'numeric', 'min:0', 'max:9999999999999.99'],
            'pockets.*.is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'pockets.required' => 'Tambahkan minimal satu pocket.',
            'pockets.min' => 'Tambahkan minimal satu pocket.',
            'pockets.*.name.required' => 'Nama pocket wajib diisi.',
            'pockets.*.name.distinct' => 'Nama pocket tidak boleh sama.',
            'pockets.*.opening_balance.min' => 'Saldo awal pocket tidak boleh negatif.',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                /** @var CashAccount|null $account */
                $account = $this->route('cash_account');
                $hasPockets = $this->boolean('has_pockets');

                if ($account && $hasPockets && ! $account->has_pockets
                    && $account->transactions()->withTrashed()->whereNull('pocket_id')->exists()) {
                    $validator->errors()->add('has_pockets', 'Rekening ini sudah punya transaksi tanpa pocket, jadi tidak bisa diubah menjadi rekening ber-pocket.');
                }

                if ($account && ! $hasPockets && $account->has_pockets
                    && $account->transactions()->withTrashed()->whereNotNull('pocket_id')->exists()) {
                    $validator->errors()->add('has_pockets', 'Pocket rekening ini sudah dipakai transaksi, jadi "Pakai Pocket" tidak bisa dimatikan.');
                }

                if ($hasPockets && $account) {
                    $ownIds = $account->pockets()->pluck('id')->all();
                    foreach ($this->input('pockets', []) as $i => $pocket) {
                        if (! empty($pocket['id']) && ! in_array((int) $pocket['id'], $ownIds, true)) {
                            $validator->errors()->add("pockets.{$i}.id", 'Pocket tidak ditemukan di rekening ini.');
                        }
                    }
                }
            },
        ];
    }
}
