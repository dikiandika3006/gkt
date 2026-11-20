<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Database\Factories\TitheMemberFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** Nomor perpuluhan jemaat. Label tampil di mutasi/rekap: kode 3 digit + "/" + alias, mis. 001/SVN. */
#[Fillable(['code', 'name', 'alias', 'is_active'])]
class TitheMember extends Model
{
    /** @use HasFactory<TitheMemberFactory> */
    use HasFactory, LogsActivity;

    protected function casts(): array
    {
        return [
            'code' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public static function formatLabel(int $code, ?string $alias): string
    {
        $number = str_pad((string) $code, 3, '0', STR_PAD_LEFT);
        $alias = trim((string) $alias);

        return $alias === '' ? $number : "{$number}/{$alias}";
    }

    protected function label(): Attribute
    {
        return Attribute::get(fn () => static::formatLabel($this->code, $this->alias));
    }
}
