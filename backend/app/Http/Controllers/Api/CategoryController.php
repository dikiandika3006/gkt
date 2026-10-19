<?php

namespace App\Http\Controllers\Api;

use App\Enums\CategoryNature;
use App\Enums\CategoryType;
use App\Http\Controllers\Controller;
use App\Http\Requests\CategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Services\CategoryCodeGenerator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $categories = Category::query()
            ->withCount('transactions')
            // Filter jenis transaksi ikut menyertakan kategori dua arah.
            ->when($request->filled('type'), fn ($q) => $q->whereIn('type', [$request->input('type'), CategoryType::Both->value]))
            ->when($request->filled('nature'), fn ($q) => $q->where('nature', $request->input('nature')))
            ->when($request->boolean('exclude_transfer'), fn ($q) => $q->where('nature', '!=', CategoryNature::Transfer->value))
            ->when($request->boolean('active_only'), fn ($q) => $q->where('is_active', true))
            ->when($request->filled('search'), fn ($q) => $q->where(function ($q) use ($request) {
                $q->where('name', 'like', '%'.$request->input('search').'%')
                    ->orWhere('code', 'like', '%'.$request->input('search').'%');
            }))
            ->orderBy('code')
            ->get();

        return CategoryResource::collection($categories);
    }

    public function store(CategoryRequest $request, CategoryCodeGenerator $codes): CategoryResource
    {
        $data = $request->validated();

        // Kode dibuat di dalam transaksi; jika bentrok dengan penyimpanan bersamaan, coba sekali lagi.
        $category = retry(2, fn () => DB::transaction(fn () => Category::create([
            ...$data,
            'code' => $codes->next($data['group'], CategoryType::from($data['type']), CategoryNature::from($data['nature'])),
        ])), 0, fn ($e) => $e instanceof UniqueConstraintViolationException);

        return new CategoryResource($category);
    }

    /** Pratinjau kode otomatis untuk form tambah kategori. */
    public function nextCode(Request $request, CategoryCodeGenerator $codes): JsonResponse
    {
        $data = $request->validate([
            'group' => ['required', 'string', 'max:100'],
            'type' => ['required', Rule::enum(CategoryType::class)],
            'nature' => ['required', Rule::enum(CategoryNature::class)],
        ]);

        return response()->json([
            'code' => $codes->next($data['group'], CategoryType::from($data['type']), CategoryNature::from($data['nature'])),
        ]);
    }

    public function show(Category $category): CategoryResource
    {
        return new CategoryResource($category->loadCount('transactions'));
    }

    public function update(CategoryRequest $request, Category $category): CategoryResource
    {
        // Mengubah ke "dua arah" selalu aman; arah lain hanya jika belum dipakai.
        $newType = $request->input('type');
        if ($category->transactions()->exists() && $newType !== $category->type->value && $newType !== CategoryType::Both->value) {
            abort(422, 'Jenis kategori tidak bisa diubah karena sudah dipakai transaksi.');
        }

        $category->update($request->validated());

        return new CategoryResource($category);
    }

    public function destroy(Category $category): JsonResponse
    {
        if ($category->transactions()->withTrashed()->exists()) {
            abort(422, 'Kategori sudah dipakai transaksi. Nonaktifkan saja.');
        }

        $category->delete();

        return response()->json(['message' => 'Kategori dihapus.']);
    }
}
