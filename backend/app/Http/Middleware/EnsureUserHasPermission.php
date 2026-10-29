<?php

namespace App\Http\Middleware;

use App\Enums\Module;
use App\Enums\PermissionLevel;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasPermission
{
    /**
     * Pemakaian di route: ->middleware('permission:transactions,manage')
     * Beberapa modul (cukup salah satu): ->middleware('permission:reports|report_secretariat,view')
     */
    public function handle(Request $request, Closure $next, string $modules, string $level = 'view'): Response
    {
        $user = $request->user();
        $required = PermissionLevel::from($level);

        $allowed = $user && collect(explode('|', $modules))
            ->contains(fn (string $module) => $user->hasPermission(Module::from($module), $required));

        if (! $allowed) {
            abort(403, 'Anda tidak memiliki akses untuk aksi ini.');
        }

        return $next($request);
    }
}
