<?php

namespace PortalSistemas\SSOClient\Laravel\Http\Controllers;

use PortalSistemas\SSOClient\Core\SSOClient;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

class SSOController extends Controller
{
    private const SESSION_MANAGED_PERMISSIONS = 'sso_managed_permissions';

    /** @var SSOClient */
    protected $client;

    public function __construct(SSOClient $client)
    {
        $this->client = $client;
    }

    /**
     * Redirect directly to SSO.
     */
    public function login()
    {
        $state = Str::random(40);
        Session::put('oauth_state', $state);

        return redirect($this->client->getAuthorizationUrl($state));
    }

    /**
     * Handle the callback from SSO.
     */
    public function callback(Request $request)
    {
        $state = Session::remove('oauth_state');

        if (!$state || $request->state !== $state) {
            abort(403, 'Invalid OAuth state');
        }

        if (!$request->code) {
            abort(400, 'Authorization code missing');
        }

        $tokenResponse = $this->client->exchangeCodeForToken($request->code);

        if (!$tokenResponse) {
            return redirect('/')->with('error', 'Failed to exchange token');
        }

        $ssoUser = $this->client->getUserInfo($tokenResponse['access_token']);

        if (!$ssoUser) {
            return redirect('/')->with('error', 'Failed to get user info');
        }

        $userModelClass = (string) config('sso-client.user_model', \App\Models\User::class);

        /** @var \Illuminate\Contracts\Auth\Authenticatable $user */
        $user = $userModelClass::updateOrCreate(
            ['codpes' => $ssoUser['codpes']],
            [
                'name' => $ssoUser['name'] ?? '',
                'email' => $ssoUser['email'] ?? '',
                'email_verified_at' => now(),
            ]
        );

        $authorization = is_array($ssoUser['authorization'] ?? null) ? $ssoUser['authorization'] : null;
        $permissions = $this->extractPermissions($authorization);
        $this->syncManagedPermissionsIfEnabled($user, $permissions, $authorization !== null);

        Auth::login($user);

        // Store SSO session data
        Session::put('sso_access_token', $tokenResponse['access_token']);
        Session::put('sso_refresh_token', $tokenResponse['refresh_token'] ?? null);
        Session::put('sso_expires_at', now()->addSeconds($tokenResponse['expires_in'] ?? 3600));
        Session::put('sso_login_timestamp', now()->timestamp);
        Session::put('client_permissions', $permissions);
        Session::put('client_authorization', $authorization);

        return redirect()->intended(config('sso-client.home_path', '/home'));
    }

    /**
     * Local logout.
     */
    public function logout()
    {
        Auth::logout();
        Session::forget('client_permissions');
        Session::forget('client_authorization');
        Session::forget(self::SESSION_MANAGED_PERMISSIONS);
        Session::flush();

        return redirect(config('sso-client.logout_redirect', '/'));
    }

    /**
     * Webhook Logout (Backchannel).
     */
    public function webhookLogout(Request $request)
    {
        $webhookSecret = config('sso-client.webhook_secret');
        $signature = $request->header('X-Webhook-Signature');
        $timestamp = $request->header('X-Webhook-Timestamp');
        $nonce = $request->header('X-Webhook-Nonce');
        $rawBody = $request->getContent();
        $codpes = $request->input('codpes');

        if (
            !$codpes
            || !is_string($signature)
            || $signature === ''
            || !is_string($timestamp)
            || $timestamp === ''
            || !is_string($nonce)
            || $nonce === ''
            || !is_string($rawBody)
            || $rawBody === ''
            || !$webhookSecret
        ) {
            return response()->json(['error' => 'Missing parameters'], 400);
        }

        if (!ctype_digit($timestamp)) {
            return response()->json(['error' => 'Invalid timestamp'], 401);
        }

        $timestampInt = (int) $timestamp;
        $maxDriftSeconds = 300;
        if (abs(time() - $timestampInt) > $maxDriftSeconds) {
            return response()->json(['error' => 'Timestamp outside allowed window'], 401);
        }

        $nonceCacheKey = 'sso_webhook_nonce_' . hash('sha256', $nonce);
        if (Cache::has($nonceCacheKey)) {
            return response()->json(['success' => true, 'replay' => true]);
        }

        if (! $this->client->validateWebhookSignature($rawBody, $timestamp, $nonce, $signature, (string) $webhookSecret)) {
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        Cache::put($nonceCacheKey, now()->timestamp, now()->addSeconds($maxDriftSeconds));

        // Mark global logout in cache for middleware check
        Cache::put('sso_global_logout_'.$codpes, now()->timestamp, now()->addDays(1));

        return response()->json(['success' => true]);
    }

    /**
     * @param  array<string, mixed>|null  $authorization
     * @return array<int, string>
     */
    private function extractPermissions(?array $authorization): array
    {
        if (! $authorization) {
            return [];
        }

        return array_values(array_filter(array_map(static function ($permission) {
            return is_string($permission) ? trim($permission) : '';
        }, (array) ($authorization['permissions'] ?? [])), static function ($permission) {
            return $permission !== '';
        }));
    }

    /**
     * Sincroniza apenas permissões gerenciadas pelo SSO e preserva permissões locais.
     *
     * @param  array<int, string>  $newPermissions
     */
    private function syncManagedPermissionsIfEnabled($user, array $newPermissions, bool $hasAuthorization): void
    {
        if (! (bool) config('sso-client.authorization.sync_permissions', true)) {
            Session::forget(self::SESSION_MANAGED_PERMISSIONS);
            return;
        }

        $this->syncManagedPermissions($user, $newPermissions, $hasAuthorization);
    }

    /**
     * @param  array<int, string>  $newPermissions
     */
    private function syncManagedPermissions($user, array $newPermissions, bool $hasAuthorization): void
    {
        if (! method_exists($user, 'givePermissionTo') || ! method_exists($user, 'revokePermissionTo')) {
            Session::forget(self::SESSION_MANAGED_PERMISSIONS);
            return;
        }

        $previousManaged = array_values(array_filter(array_map(static function ($permission) {
            return is_string($permission) ? trim($permission) : '';
        }, (array) Session::get(self::SESSION_MANAGED_PERMISSIONS, []))));

        $targetManaged = $hasAuthorization ? $newPermissions : [];

        $toRevoke = array_values(array_diff($previousManaged, $targetManaged));
        $toGrant = array_values(array_diff($targetManaged, $previousManaged));

        foreach ($toRevoke as $permission) {
            $user->revokePermissionTo($permission);
        }

        foreach ($toGrant as $permission) {
            if (class_exists(Permission::class)) {
                Permission::findOrCreate($permission, 'web');
            }
            $user->givePermissionTo($permission);
        }

        Session::put(self::SESSION_MANAGED_PERMISSIONS, $targetManaged);
    }
}
