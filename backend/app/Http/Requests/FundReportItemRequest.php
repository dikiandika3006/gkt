<?php

namespace App\Http\Requests;

use App\Enums\CategoryType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Satu nota dalam LPJ. Jumlah dihitung server = qty × harga satuan. */
class FundReportItemRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'date' => ['required', 'date'],
            'category_id' => ['required', Rule::exists('categories', 'id')
                ->where('is_active', true)
                ->where('nature', 'operational')
                ->whereIn('type', [CategoryType::Expense->value, CategoryType::Both->value])],
            'description' => ['required', 'string', 'max:255'],
            'qty' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'unit' => ['nullable', 'string', 'max:30'],
            'unit_price' => ['required', 'numeric', 'gt:0', 'max:9999999999999.99'],
        ];
    }
}
