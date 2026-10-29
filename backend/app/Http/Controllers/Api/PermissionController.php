<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PermissionRequest;
use App\Services\PermissionService;
use Illuminate\Http\JsonResponse;

/** Menu Hak Akses (admin): matriks role × modul. */
class PermissionController extends Controller
{
    public function __construct(private PermissionService $permissions) {}

    public function index(): JsonResponse
    {
        return response()->json($this->permissions->matrixForEditing());
    }

    public function update(PermissionRequest $request): JsonResponse
    {
        $this->permissions->save($request->validated('permissions'));

        return response()->json([
            'message' => 'Hak akses disimpan.',
            ...$this->permissions->matrixForEditing(),
        ]);
    }
}
