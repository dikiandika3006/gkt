<?php

namespace App\Models\Concerns;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Catat setiap create/update/delete model ke tabel activity_logs (audit trail).
 */
trait LogsActivity
{
    protected static function bootLogsActivity(): void
    {
        static::created(fn (Model $model) => static::writeActivity($model, 'created', null, $model->getAttributes()));

        static::updated(function (Model $model) {
            $changes = collect($model->getChanges())->except(['updated_at'])->all();
            if ($changes === []) {
                return;
            }

            static::writeActivity($model, 'updated', array_intersect_key($model->getOriginal(), $changes), $changes);
        });

        static::deleted(fn (Model $model) => static::writeActivity($model, 'deleted', $model->getOriginal(), null));
    }

    private static function writeActivity(Model $model, string $action, ?array $before, ?array $after): void
    {
        $hidden = array_flip($model->getHidden());

        ActivityLog::create([
            'user_id' => Auth::id(),
            'action' => $action,
            'subject_type' => $model->getMorphClass(),
            'subject_id' => $model->getKey(),
            'before' => $before === null ? null : array_diff_key($before, $hidden),
            'after' => $after === null ? null : array_diff_key($after, $hidden),
        ]);
    }
}
