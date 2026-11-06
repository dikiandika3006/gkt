<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Restore database dari file riwayat backup (name) atau upload (.sql / .sql.gz). */
class BackupRestoreRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'konfirmasi' => ['required', 'in:RESTORE'],
            'name' => ['required_without:file', 'nullable', 'string', 'max:100'],
            'file' => ['required_without:name', 'nullable', 'file'],
        ];
    }

    public function messages(): array
    {
        return [
            'konfirmasi.required' => 'Ketik RESTORE (huruf besar) untuk konfirmasi.',
            'konfirmasi.in' => 'Ketik RESTORE (huruf besar) untuk konfirmasi.',
            'name.required_without' => 'Pilih file backup dari riwayat atau upload file .sql / .sql.gz.',
            'file.required_without' => 'Pilih file backup dari riwayat atau upload file .sql / .sql.gz.',
            'file.file' => 'Upload file gagal. Periksa batas ukuran upload server.',
        ];
    }
}
