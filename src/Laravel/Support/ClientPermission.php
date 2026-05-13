<?php

namespace PortalSistemas\SSOClient\Laravel\Support;

use Illuminate\Support\Facades\Session;

class ClientPermission
{
    public static function has(string $permission): bool
    {
        $permission = trim($permission);
        if ($permission === '') {
            return false;
        }

        return in_array($permission, (array) Session::get('client_permissions', []), true);
    }
}
