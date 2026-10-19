<?php

namespace App\Http\Requests;

use App\Enums\CategoryNature;
use App\Enums\CategoryType;
use App\Enums\TransactionType;
use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class BudgetRequest extends FormRequest
{
    public function rules(): array
    {
        $budget = $this->route('budget');

        return [
            'year' => ['required', 'integer', 'between:2000,2100'],
            'category_id' => ['required', Rule::exists('categories', 'id')->whereNot('nature', CategoryNature::Transfer->value)],
            'type' => [
                'required',
                Rule::enum(TransactionType::class),
                Rule::unique('budgets')
                    ->where('year', $this->input('year'))
                    ->where('category_id', $this->input('category_id'))
                    ->ignore($budget),
            ],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999.99'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return ['type.unique' => 'Anggaran untuk kategori ini di tahun tersebut sudah ada.'];
    }

    /** Arah anggaran harus sesuai jenis kategori (kategori dua arah boleh keduanya). */
    public function after(): array
    {
        return [function (Validator $validator) {
            $category = Category::find($this->input('category_id'));
            $type = $this->input('type');
            if ($category && $category->type !== CategoryType::Both && $category->type->value !== $type) {
                $validator->errors()->add('type', 'Jenis anggaran tidak sesuai dengan jenis kategori.');
            }
        }];
    }
}
