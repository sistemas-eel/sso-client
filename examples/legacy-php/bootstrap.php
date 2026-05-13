<?php
use PortalSistemas\SSOClient\Cache\FileCacheHandler;
use PortalSistemas\SSOClient\Core\SSOClient;
use PortalSistemas\SSOClient\Session\SessionHandler;

/**
 * Exemplo de bootstrap local para PHP legado.
 *
 * Neste modelo, a configuração da aplicação fica fora da biblioteca
 * e o bootstrap local monta os objetos a partir dela.
 */

$config = [
    'server_url' => 'https://portalsistemas.unidade.usp.br/portal-sistemas',
    'client_id' => 'seu_client_id',
    'client_secret' => 'seu_client_secret',
    'redirect_uri' => 'https://seu-sistema.com.br/sso/callback.php',
    'verify_ssl' => true,
    'ca_bundle' => null,
    'webhook_secret' => 'seu_webhook_secret',
    'home_path' => '/dashboard.php',
    'login_url' => '/sso/login.php',
    'logout_url' => '/sso/logout.php',
    'logout_redirect' => '/index.php',
    'cache_dir' => __DIR__ . '/cache',
    'session_prefix' => 'sso_',
];

$client = new SSOClient(
    $config['server_url'],
    $config['client_id'],
    $config['client_secret'],
    $config['redirect_uri'],
    $config['verify_ssl'],
    $config['ca_bundle']
);

$session = new SessionHandler($config['session_prefix']);
$cache = new FileCacheHandler($config['cache_dir']);

return [
    'config' => $config,
    'client' => $client,
    'session' => $session,
    'cache' => $cache,
];
