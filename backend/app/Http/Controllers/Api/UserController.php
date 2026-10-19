<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UserController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return UserResource::collection(User::orderBy('name')->get());
    }

    public function store(UserRequest $request): UserResource
    {
        return new UserResource(User::create($request->validated()));
    }

    public function show(User $user): UserResource
    {
        return new UserResource($user);
    }

    public function update(UserRequest $request, User $user): UserResource
    {
        $data = $request->validated();

        if (empty($data['password'])) {
            unset($data['password']);
        }

        if ($user->is($request->user()) && ($data['role'] !== $user->role->value || ! ($data['is_active'] ?? true))) {
            abort(422, 'Anda tidak bisa mengubah role atau menonaktifkan akun sendiri.');
        }

        $user->update($data);

        if (! $user->is_active) {
            $user->tokens()->delete();
        }

        return new UserResource($user);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($user->is($request->user())) {
            abort(422, 'Anda tidak bisa menghapus akun sendiri.');
        }

        $user->tokens()->delete();
        $user->delete();

        return response()->json(['message' => 'Pengguna dihapus.']);
    }
}
