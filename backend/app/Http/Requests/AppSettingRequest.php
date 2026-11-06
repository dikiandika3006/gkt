<?php

namespace App\Http\Requests;

use App\Services\AppSettingService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AppSettingRequest extends FormRequest
{
    public function rules(): array
    {
        return collect(AppSettingService::KEYS)
            ->mapWithKeys(fn (string $key) => [$key => ['sometimes', 'nullable', 'integer', Rule::exists('cash_accounts', 'id')]])
            ->all();
    }

    public function messages(): array
    {
        return collect(AppSettingService::KEYS)
            ->mapWithKeys(fn (string $key) => ["{$key}.exists" => 'Kas/rekening yang dipilih tidak ditemukan.'])
            ->all();
    }
}
