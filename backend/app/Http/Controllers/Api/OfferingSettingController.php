<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\OfferingSettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Master Pos Persembahan (jadwal Minggu ke-N, rekening pos, daftar kebaktian). */
class OfferingSettingController extends Controller
{
    public function __construct(private OfferingSettingService $settings) {}

    public function show(): JsonResponse
    {
        return response()->json($this->settings->get());
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'funds' => ['required', 'array', 'min:1', 'max:50'],
            'funds.*.id' => ['nullable', 'integer', 'exists:offering_funds,id'],
            'funds.*.key' => ['nullable', 'string', 'max:40'],
            'funds.*.name' => ['required', 'string', 'max:100', 'distinct:ignore_case'],
            'funds.*.cash_account_id' => ['nullable', 'integer', Rule::exists('cash_accounts', 'id')],
            'funds.*.bank_name' => ['nullable', 'string', 'max:50'],
            'funds.*.account_number' => ['nullable', 'string', 'max:50'],
            'funds.*.account_holder' => ['nullable', 'string', 'max:100'],
            'funds.*.is_active' => ['boolean'],
            'schedule' => ['required', 'array'],
            'schedule.*' => ['nullable'],
            'services' => ['required', 'array', 'min:1', 'max:10'],
            'services.*' => ['required', 'string', 'max:30', 'distinct'],
        ], [
            'funds.*.name.distinct' => 'Nama pos tidak boleh sama.',
            'services.min' => 'Isi minimal satu kebaktian.',
        ]);

        $this->settings->save($data);

        return response()->json($this->settings->get());
    }
}
