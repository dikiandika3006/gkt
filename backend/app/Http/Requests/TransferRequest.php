<?php

namespace App\Http\Requests;

use App\Rules\AccessibleCashAccount;
use App\Services\PocketService;
use App\Services\TransferService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class TransferRequest extends FormRequest
{
    public function rules(): array
    {
        $isUpdate = $this->route('group') !== null;
        $activeAccount = Rule::exists('cash_accounts', 'id')->where('is_active', true);

        return [
            'date' => ['required', 'date'],
            'from_cash_account_id' => [$isUpdate ? 'prohibited' : 'required', 'integer', $activeAccount, new AccessibleCashAccount],
            'to_cash_account_id' => [$isUpdate ? 'prohibited' : 'required', 'integer', $activeAccount, new AccessibleCashAccount],
            'from_pocket_id' => [$isUpdate ? 'prohibited' : 'nullable', 'integer'],
            'to_pocket_id' => [$isUpdate ? 'prohibited' : 'nullable', 'integer'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999.99'],
            'description' => ['required', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $pockets = app(PocketService::class);
                $amount = (float) $this->input('amount');
                $group = $this->route('group');

                if ($group) {
                    // Kas & pocket tidak bisa diubah saat edit; cek saldo pocket dengan nominal baru.
                    $legs = app(TransferService::class)->legs($group);
                    $changes = [];
                    foreach ($legs as $leg) {
                        if ($leg->pocket_id) {
                            $changes[$leg->pocket_id] = ($changes[$leg->pocket_id] ?? 0.0) + PocketService::signed($leg->type, $amount);
                        }
                    }
                    if ($error = $pockets->shortfallError($changes, null, $group)) {
                        $validator->errors()->add('amount', $error);
                    }

                    return;
                }

                $from = (int) $this->input('from_cash_account_id');
                $to = (int) $this->input('to_cash_account_id');
                $fromPocket = $this->filled('from_pocket_id') ? (int) $this->input('from_pocket_id') : null;
                $toPocket = $this->filled('to_pocket_id') ? (int) $this->input('to_pocket_id') : null;

                if ($error = $pockets->membershipError($from, $fromPocket)) {
                    $validator->errors()->add('from_pocket_id', $error);
                }
                if ($error = $pockets->membershipError($to, $toPocket)) {
                    $validator->errors()->add('to_pocket_id', $error);
                }
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if ($from === $to && $fromPocket === $toPocket) {
                    $validator->errors()->add($fromPocket ? 'to_pocket_id' : 'to_cash_account_id', $fromPocket ? 'Pocket tujuan harus berbeda dari pocket asal.' : 'Kas tujuan harus berbeda dari kas asal.');

                    return;
                }

                $changes = [];
                if ($fromPocket) {
                    $changes[$fromPocket] = -$amount;
                }
                if ($toPocket) {
                    $changes[$toPocket] = ($changes[$toPocket] ?? 0.0) + $amount;
                }
                if ($error = $pockets->shortfallError($changes)) {
                    $validator->errors()->add('amount', $error);
                }
            },
        ];
    }
}
