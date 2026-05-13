<?php

namespace PortalSistemas\SSOClient\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Session;

class CheckSSOSession
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        if (Auth::check()) {
            $user = Auth::user();
            
            // Replicado/Senhaunica compatibility
            $codpes = $user->codpes ?? $user->id;

            $globalLogoutTime = Cache::get('sso_global_logout_' . $codpes);
            $loginTime = Session::get('sso_login_timestamp');

            // If a global logout event was recorded AFTER the current session started
            if ($globalLogoutTime && $loginTime && $globalLogoutTime >= $loginTime) {
                Auth::logout();
                Session::flush();

                Session::put('url.intended', $request->fullUrl());

                $loginRouteName = (string) config('sso-client.login_route.name', 'login');
                if ($loginRouteName !== '' && app('router')->has($loginRouteName)) {
                    return redirect()->route($loginRouteName);
                }

                $loginPath = '/' . ltrim((string) config('sso-client.login_route.path', '/login'), '/');
                return redirect($loginPath);
            }
        }

        return $next($request);
    }
}
