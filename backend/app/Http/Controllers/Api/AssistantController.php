<?php

namespace App\Http\Controllers\Api;

use App\Assistant\Contracts\Answerer;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Asisten "Tanya Keuangan": hanya membaca data, tidak pernah mengubahnya. */
class AssistantController extends Controller
{
    public function ask(Request $request): JsonResponse
    {
        $data = $request->validate(['question' => ['required', 'string', 'max:300']]);

        /** @var Answerer $answerer */
        $answerer = app(config('assistant.answerer'));

        return response()->json($answerer->answer($data['question'])->toArray());
    }

    public function starters(): JsonResponse
    {
        return response()->json(['questions' => config('assistant.starter_questions', [])]);
    }
}
