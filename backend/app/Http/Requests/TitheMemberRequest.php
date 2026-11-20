<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TitheMemberRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $alias = trim((string) $this->input('alias'));
        $this->merge(['alias' => $alias === '' ? null : $alias]);
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'integer', 'min:1', 'max:999999', Rule::unique('tithe_members')->ignore($this->route('tithe_member'))],
            'name' => ['required', 'string', 'max:255'],
            'alias' => ['nullable', 'string', 'max:20', 'not_regex:/\//'],
            'is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.required' => 'Nomor wajib diisi.',
            'code.integer' => 'Nomor harus berupa angka.',
            'code.min' => 'Nomor minimal 1.',
            'code.max' => 'Nomor terlalu besar.',
            'code.unique' => 'Nomor perpuluhan sudah dipakai.',
            'name.required' => 'Nama wajib diisi.',
            'name.max' => 'Nama maksimal 255 karakter.',
            'alias.max' => 'Alias maksimal 20 karakter.',
            'alias.not_regex' => 'Alias tidak boleh mengandung tanda "/".',
        ];
    }
}
