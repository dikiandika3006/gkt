<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BankImportRequest;
use App\Rules\AccessibleCashAccount;
use App\Services\BankImport\BankImportService;
use App\Services\BankImport\BcaCsvParser;
use App\Services\BankImport\SpreadsheetMutationParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/** Import mutasi rekening dari CSV KlikBCA atau file CSV/Excel dengan format bebas. */
class BankImportController extends Controller
{
    public function __construct(private BankImportService $service) {}

    public function preview(Request $request, BcaCsvParser $bca, SpreadsheetMutationParser $spreadsheet): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'extensions:csv,txt,xlsx,xls', 'max:5120'],
            'cash_account_id' => ['nullable', 'integer', 'exists:cash_accounts,id', new AccessibleCashAccount],
            'sheet' => ['nullable', 'string', 'max:100'],
            'header_row' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'mapping' => ['nullable', 'array'],
            'mapping.*' => ['nullable', 'integer', 'min:0', 'max:200'],
            'direction' => ['nullable', Rule::in(['bank', 'book'])],
        ], ['file.extensions' => 'File harus CSV, XLSX, atau XLS.']);

        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension());
        $custom = array_filter([$data['sheet'] ?? null, $data['header_row'] ?? null, $data['mapping'] ?? null, $data['direction'] ?? null]) !== [];

        try {
            $parsed = null;
            // CSV KlikBCA (Jumlah "1,000.00 CR") tetap memakai parser khususnya, kecuali user mengatur kolom sendiri.
            if (! $custom && in_array($extension, ['csv', 'txt'], true)) {
                try {
                    $parsed = $bca->parse($file->get());
                } catch (RuntimeException) {
                    $parsed = null;
                }
            }

            $parsed ??= $spreadsheet->parse($file->getRealPath(), $extension, [
                'sheet' => $data['sheet'] ?? null,
                'header_row' => $data['header_row'] ?? null,
                'mapping' => $data['mapping'] ?? null,
                'direction' => $data['direction'] ?? null,
            ]);

            $preview = $this->service->previewParsed($parsed, $data['cash_account_id'] ?? null);
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json($preview);
    }

    public function store(BankImportRequest $request): JsonResponse
    {
        $data = $request->validated();
        $count = $this->service->commit((int) $data['cash_account_id'], $data['rows'], $request->user()->id, (bool) ($data['confirm_negative'] ?? false));

        return response()->json(['message' => "{$count} transaksi berhasil diimpor.", 'count' => $count], 201);
    }
}
