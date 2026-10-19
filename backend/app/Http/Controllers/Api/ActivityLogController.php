<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityLogResource;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ActivityLogController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'subject_type' => ['nullable', 'string', 'max:50'],
            'user_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $logs = ActivityLog::query()
            ->with('user:id,name')
            ->when($filters['subject_type'] ?? null, fn ($q, $v) => $q->where('subject_type', 'like', "%{$v}"))
            ->when($filters['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 25);

        return ActivityLogResource::collection($logs);
    }
}
