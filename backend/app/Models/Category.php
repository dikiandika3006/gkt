<?php

namespace App\Models;

use App\Enums\CategoryNature;
use App\Enums\CategoryType;
use App\Models\Concerns\LogsActivity;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name', 'group', 'type', 'nature', 'description', 'is_active'])]
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory, LogsActivity;

    protected function casts(): array
    {
        return [
            'type' => CategoryType::class,
            'nature' => CategoryNature::class,
            'is_active' => 'boolean',
        ];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}
