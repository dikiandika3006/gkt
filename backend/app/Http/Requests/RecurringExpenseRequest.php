<?php

namespace App\Http\Requests;

use App\Enums\CategoryType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecurringExpenseRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'category_id' => ['required', Rule::exists('categories', 'id')
                ->whereIn('type', [CategoryType::Expense->value, CategoryType::Both->value])
                ->where('nature', 'operational')],
            'counterparty' => ['nullable', 'string', 'max:255'],
            'bank_info' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999.99'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
