<?php

use SistemasEel\SSOClient\Cache\FileCacheHandler;
use SistemasEel\SSOClient\Core\SSOClient;
use SistemasEel\SSOClient\Session\SessionHandler;

/**
 * Bootstrap compartilhado para integracoes PHP legadas.
 *
 * Uso:
 * $bootstrap = require __DIR__ . '/../vendor/sistemas-eel/sso-client/src/Legacy/bootstrap.php';
 * $config = $bootstrap['config'];
 * $client = $bootstrap['client'];
 * $session = $bootstrap['session'];
 * $cache = $bootstrap['cache'];
 */

if (!class_exists(SSOClient::class)) {
    $autoloadCandidates = [
        __DIR__ . '/../../vendor/autoload.php',
        __DIR__ . '/../../../autoload.php',
    ];

    $autoloadLoaded = false;

    foreach ($autoloadCandidates as $autoloadFile) {
        if (is_file($autoloadFile)) {
            require_once $autoloadFile;
            $autoloadLoaded = true;
            break;
        }
    }

    if (!$autoloadLoaded) {
        throw new RuntimeException(
            'Não foi possível localizar o autoload do Composer para inicializar o SSO Client.'
        );
    }
}

$env = static function (string $key, $default = null) {
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

    return ($value === false || $value === null || $value === '') ? $default : $value;
};

$envBool = static function (string $key, bool $default = false) use ($env): bool {
    $value = $env($key);

    if ($value === null) {
        return $default;
    }

    $normalized = strtolower((string) $value);

    if (in_array($normalized, ['1', 'true', 'on', 'yes'], true)) {
        return true;
    }

    if (in_array($normalized, ['0', 'false', 'off', 'no'], true)) {
        return false;
    }

    return $default;
};

$defaultCacheDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'sso-client-cache';

$config = [
    'server_url' => (string) $env('SSO_SERVER_URL', 'https://portalsistemas.unidade.usp.br/portal-sistemas'),
    'client_id' => (string) $env('SSO_CLIENT_ID', ''),
    'client_secret' => (string) $env('SSO_CLIENT_SECRET', ''),
    'redirect_uri' => (string) $env('SSO_REDIRECT_URI', ''),
    'verify_ssl' => $envBool('SSO_VERIFY_SSL', true),
    'ca_bundle' => $env('SSO_CA_BUNDLE'),
    'webhook_secret' => (string) $env('SSO_WEBHOOK_SECRET', ''),
    'home_path' => (string) $env('SSO_HOME_PATH', '/'),
    'login_url' => (string) $env('SSO_LOGIN_URL', '/sso/login.php'),
    'logout_url' => (string) $env('SSO_LOGOUT_URL', '/sso/logout.php'),
    'logout_redirect' => (string) $env('SSO_LOGOUT_REDIRECT', '/'),
    'cache_dir' => (string) $env('SSO_CACHE_DIR', $defaultCacheDir),
    'session_prefix' => (string) $env('SSO_SESSION_PREFIX', 'sso_'),
];

$cache = new FileCacheHandler($config['cache_dir']);

return [
    'config' => $config,
    'client' => new SSOClient(
        $config['server_url'],
        $config['client_id'],
        $config['client_secret'],
        $config['redirect_uri'],
        $config['verify_ssl'],
        $config['ca_bundle']
    ),
    'session' => new SessionHandler($config['session_prefix']),
    'cache' => $cache,
];
