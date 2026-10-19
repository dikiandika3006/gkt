<?php

namespace App\Http\Requests;

use App\Enums\TransactionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Simpan hasil review import mutasi bank. Validasi pecahan & kategori dilakukan di BankImportService. */
class BankImportRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'cash_account_id' => ['required', Rule::exists('cash_accounts', 'id')->where('is_active', true)],
            'rows' => ['required', 'array', 'min:1', 'max:1000'],
            'rows.*.key' => ['required', 'string', 'regex:/^[a-f0-9]{40}$/', 'distinct'],
            'rows.*.date' => ['required', 'date'],
            'rows.*.type' => ['required', Rule::enum(TransactionType::class)],
            'rows.*.amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999.99'],
            'rows.*.description' => ['required', 'string', 'max:500'],
            'rows.*.lines' => ['required', 'array', 'min:1', 'max:20'],
            'rows.*.lines.*.category_id' => ['required', 'integer'],
            'rows.*.lines.*.amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999.99'],
            'rows.*.lines.*.counterparty' => ['nullable', 'string', 'max:255'],
            'rows.*.lines.*.description' => ['nullable', 'string', 'max:255'],
            'rows.*.lines.*.recurring_expense_id' => ['nullable', 'integer', 'exists:recurring_expenses,id'],
            'rows.*.lines.*.recurring_period' => ['nullable', 'date_format:Y-m'],
        ];
    }

    public function messages(): array
    {
        return [
            'rows.*.lines.*.category_id.required' => 'Semua pecahan wajib diberi kategori.',
            'rows.*.lines.*.amount.gt' => 'Nominal pecahan harus lebih dari 0.',
        ];
    }
}
