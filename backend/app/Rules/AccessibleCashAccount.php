<?php

namespace App\Rules;

use App\Services\PermissionService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Auth;

/** Kas harus termasuk akses kas role user (menu Hak Akses → Akses Kas di Mutasi). */
class AccessibleCashAccount implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $user = Auth::user();
        if ($user && is_numeric($value) && ! app(PermissionService::class)->canUseCashAccount($user, (int) $value)) {
            $fail('Anda tidak punya akses ke kas ini.');
        }
    }
}
