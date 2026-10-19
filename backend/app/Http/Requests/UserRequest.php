<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserRequest extends FormRequest
{
    public function rules(): array
    {
        $isUpdate = $this->route('user') !== null;

        return [
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'alpha_dash', 'max:50', Rule::unique('users')->ignore($this->route('user'))],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users')->ignore($this->route('user'))],
            'password' => [$isUpdate ? 'nullable' : 'required', Password::min(8)],
            'role' => ['required', Rule::enum(UserRole::class)],
            'is_active' => ['boolean'],
        ];
    }
}
