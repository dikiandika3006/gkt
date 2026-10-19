<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Insights\InsightEngine;
use App\Insights\InsightResult;
use Illuminate\Http\JsonResponse;

/** Insight keuangan untuk kartu di Dashboard. */
class InsightController extends Controller
{
    public function __invoke(InsightEngine $engine): JsonResponse
    {
        return response()->json([
            'generated_at' => now()->toIso8601String(),
            'data' => $engine->run()->map(fn (InsightResult $r) => $r->toArray())->values(),
        ]);
    }
}
