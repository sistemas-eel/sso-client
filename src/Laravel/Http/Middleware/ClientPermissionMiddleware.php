<?php

namespace PortalSistemas\SSOClient\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;

class ClientPermissionMiddleware
{
    /**
     * @param  \Closure(\Illuminate\Http\Request): mixed  $next
     */
    public function handle(Request $request, Closure $next, string $permission)
    {
        $permission = trim($permission);
        $permissions = (array) Session::get('client_permissions', []);

        if ($permission === '' || ! in_array($permission, $permissions, true)) {
            abort(403, 'Acesso negado.');
        }

        return $next($request);
    }
}
