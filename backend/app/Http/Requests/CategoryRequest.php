<?php

namespace App\Http\Requests;

use App\Enums\CategoryNature;
use App\Enums\CategoryType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CategoryRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:20', Rule::unique('categories')->ignore($this->route('category'))],
            'name' => ['required', 'string', 'max:255'],
            'group' => ['required', 'string', 'max:100'],
            'type' => ['required', Rule::enum(CategoryType::class)],
            'nature' => ['required', Rule::enum(CategoryNature::class)],
            'description' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ];
    }
}
