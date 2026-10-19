<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;

/** File bukti (nota/kwitansi). Disimpan di disk privat, diunduh lewat route ber-auth. */
#[Fillable(['attachable_type', 'attachable_id', 'path', 'original_name', 'mime_type', 'size', 'uploaded_by'])]
class Attachment extends Model
{
    public const DISK = 'local';

    protected static function booted(): void
    {
        static::deleted(fn (Attachment $attachment) => Storage::disk(self::DISK)->delete($attachment->path));
    }

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
