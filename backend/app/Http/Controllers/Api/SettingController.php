<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AppSettingRequest;
use App\Services\AppSettingService;
use Illuminate\Http\JsonResponse;

/** Menu Setting (admin): pengaturan umum aplikasi. */
class SettingController extends Controller
{
    public function __construct(private AppSettingService $settings) {}

    public function show(): JsonResponse
    {
        return response()->json($this->settings->get());
    }

    public function update(AppSettingRequest $request): JsonResponse
    {
        $this->settings->save($request->validated());

        return response()->json($this->settings->get());
    }
}
