<?php

namespace App\Http\Requests;

use App\Enums\CategoryNature;
use App\Enums\TransactionType;
use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class TransactionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'date' => ['required', 'date'],
            'type' => ['required', Rule::enum(TransactionType::class)],
            'category_id' => ['required', Rule::exists('categories', 'id')->where('is_active', true)],
            'cash_account_id' => ['required', Rule::exists('cash_accounts', 'id')->where('is_active', true)],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999.99'],
            'description' => ['required', 'string', 'max:255'],
            'counterparty' => ['nullable', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'recurring_expense_id' => ['nullable', 'integer', 'exists:recurring_expenses,id', 'required_with:recurring_period'],
            'recurring_period' => ['nullable', 'date_format:Y-m', 'required_with:recurring_expense_id'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                /** @var Transaction|null $existing */
                $existing = $this->route('transaction');
                if ($existing?->isManaged()) {
                    $validator->errors()->add('type', 'Transaksi ini dikelola dari modul Mutasi Antar Kas / LPJ. Ubah dari sana.');

                    return;
                }

                $category = Category::find($this->input('category_id'));
                $type = TransactionType::from($this->input('type'));

                if (! $category->type->allows($type)) {
                    $validator->errors()->add('category_id', 'Kategori tidak sesuai dengan jenis transaksi.');
                }

                if ($category->nature === CategoryNature::Transfer) {
                    $validator->errors()->add('category_id', 'Gunakan menu Mutasi Antar Kas untuk kategori ini.');
                }

                if ($this->filled('recurring_expense_id') && $type !== TransactionType::Expense) {
                    $validator->errors()->add('recurring_expense_id', 'Biaya rutin hanya untuk transaksi pengeluaran.');
                }
            },
        ];
    }
}
