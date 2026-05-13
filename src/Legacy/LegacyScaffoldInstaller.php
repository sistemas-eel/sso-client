<?php

namespace PortalSistemas\SSOClient\Legacy;

class LegacyScaffoldInstaller
{
    /** @var array<string, string> */
    private const FILES = [
        'sso/config.php' => 'config',
        'sso/bootstrap.php' => 'bootstrap',
        'sso/login.php' => 'login',
        'sso/callback.php' => 'callback',
        'sso/logout.php' => 'logout',
        'sso/session-check.php' => 'session_check',
        'sso/cache/.gitignore' => 'cache_gitignore',
        'api/sso/webhook-logout.php' => 'webhook_logout',
    ];

    /**
     * @param array<string, mixed> $options
     * @return array<int, string>
     */
    public function install(string $targetDirectory, array $options = []): array
    {
        $targetDirectory = rtrim($targetDirectory, DIRECTORY_SEPARATOR);
        if ($targetDirectory === '') {
            $targetDirectory = getcwd() ?: '.';
        }

        $force = !empty($options['force']);
        $written = [];

        foreach (self::FILES as $relativePath => $templateName) {
            $absolutePath = $targetDirectory . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

            if (is_file($absolutePath) && !$force) {
                throw new \RuntimeException(sprintf(
                    'Arquivo já existe: %s. Use --force para sobrescrever.',
                    $relativePath
                ));
            }

            $directory = dirname($absolutePath);
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new \RuntimeException(sprintf('Não foi possível criar o diretório: %s', $directory));
            }

            if (file_put_contents($absolutePath, $this->template($templateName)) === false) {
                throw new \RuntimeException(sprintf('Não foi possível escrever o arquivo: %s', $absolutePath));
            }

            $written[] = $relativePath;
        }

        return $written;
    }

    private function template(string $name): string
    {
        switch ($name) {
            case 'config':
                return <<<'PHP'
<?php

return [
    'server_url' => getenv('SSO_SERVER_URL') ?: 'https://portalsistemas.unidade.usp.br/portal-sistemas',
    'client_id' => getenv('SSO_CLIENT_ID') ?: 'seu_client_id',
    'client_secret' => getenv('SSO_CLIENT_SECRET') ?: 'seu_client_secret',
    'redirect_uri' => getenv('SSO_REDIRECT_URI') ?: 'https://seu-sistema.com.br/sso/callback.php',
    'verify_ssl' => filter_var(getenv('SSO_VERIFY_SSL') ?: true, FILTER_VALIDATE_BOOLEAN),
    'ca_bundle' => getenv('SSO_CA_BUNDLE') ?: null,
    'webhook_secret' => getenv('SSO_WEBHOOK_SECRET') ?: 'seu_webhook_secret',
    'home_path' => getenv('SSO_HOME_PATH') ?: '/dashboard.php',
    'login_url' => getenv('SSO_LOGIN_URL') ?: '/sso/login.php',
    'logout_url' => getenv('SSO_LOGOUT_URL') ?: '/sso/logout.php',
    'logout_redirect' => getenv('SSO_LOGOUT_REDIRECT') ?: '/index.php',
    'cache_dir' => getenv('SSO_CACHE_DIR') ?: __DIR__ . '/cache',
    'session_prefix' => getenv('SSO_SESSION_PREFIX') ?: 'sso_',
];
PHP;
            case 'bootstrap':
                return <<<'PHP'
<?php

use PortalSistemas\SSOClient\Cache\FileCacheHandler;
use PortalSistemas\SSOClient\Core\SSOClient;
use PortalSistemas\SSOClient\Session\SessionHandler;

require_once __DIR__ . '/../vendor/autoload.php';

$config = require __DIR__ . '/config.php';

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
PHP;
            case 'login':
                return <<<'PHP'
<?php

require_once __DIR__ . '/bootstrap.php';

$state = bin2hex(random_bytes(20));
$session->set('oauth_state', $state);

header('Location: ' . $client->getAuthorizationUrl($state));
exit;
PHP;
            case 'callback':
                return <<<'PHP'
<?php

require_once __DIR__ . '/bootstrap.php';

$state = $session->remove('oauth_state');
if (!$state || !isset($_GET['state']) || $_GET['state'] !== $state) {
    http_response_code(403);
    exit('OAuth state inválido.');
}

if (!isset($_GET['code'])) {
    http_response_code(400);
    exit('Código de autorização não recebido.');
}

$tokenResponse = $client->exchangeCodeForToken($_GET['code']);
if (!$tokenResponse || !isset($tokenResponse['access_token'])) {
    header('Location: ' . $config['logout_redirect'] . '?error=token_failed');
    exit;
}

$ssoUser = $client->getUserInfo($tokenResponse['access_token']);
if (!$ssoUser || !isset($ssoUser['codpes'])) {
    header('Location: ' . $config['logout_redirect'] . '?error=user_info_failed');
    exit;
}

// Integre aqui com o cadastro local do seu sistema, se necessário.
// Exemplo: buscar/criar usuário local pelo campo $ssoUser['codpes'].

$session->set('user_authenticated', true);
$session->set('user_codpes', $ssoUser['codpes']);
$session->set('user_name', $ssoUser['name'] ?? '');
$session->set('user_email', $ssoUser['email'] ?? '');
$session->set('access_token', $tokenResponse['access_token']);
$session->set('refresh_token', $tokenResponse['refresh_token'] ?? null);
$session->set('expires_at', time() + ($tokenResponse['expires_in'] ?? 3600));
$session->set('login_timestamp', time());
$session->regenerate();

header('Location: ' . $config['home_path']);
exit;
PHP;
            case 'logout':
                return <<<'PHP'
<?php

require_once __DIR__ . '/bootstrap.php';

$session->clear();
$session->destroy();

header('Location: ' . $config['logout_redirect']);
exit;
PHP;
            case 'session_check':
                return <<<'PHP'
<?php

require_once __DIR__ . '/bootstrap.php';

if (!$session->has('user_authenticated') || !$session->get('user_authenticated')) {
    header('Location: ' . $config['login_url']);
    exit;
}

$userCodpes = $session->get('user_codpes');
$loginTime = $session->get('login_timestamp');
$globalLogoutTime = $cache->get('sso_global_logout_' . $userCodpes);

if ($globalLogoutTime && $loginTime && $globalLogoutTime >= $loginTime) {
    $session->clear();
    $session->destroy();
    header('Location: ' . $config['logout_redirect'] . '?reason=global_logout');
    exit;
}

$expiresAt = $session->get('expires_at');
$refreshToken = $session->get('refresh_token');

if ($expiresAt && time() > $expiresAt && $refreshToken) {
    $newToken = $client->refreshToken($refreshToken);

    if ($newToken && isset($newToken['access_token'])) {
        $session->set('access_token', $newToken['access_token']);
        $session->set('refresh_token', $newToken['refresh_token'] ?? $refreshToken);
        $session->set('expires_at', time() + ($newToken['expires_in'] ?? 3600));
    } else {
        $session->clear();
        $session->destroy();
        header('Location: ' . $config['login_url'] . '?reason=token_expired');
        exit;
    }
}

$userCodpes = $session->get('user_codpes');
$userName = $session->get('user_name');
$userEmail = $session->get('user_email');
$accessToken = $session->get('access_token');
PHP;
            case 'cache_gitignore':
                return "*\n!.gitignore\n";
            case 'webhook_logout':
                return <<<'PHP'
<?php

require_once __DIR__ . '/../../sso/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$rawBody = file_get_contents('php://input');
$input = json_decode($rawBody, true);
$codpes = is_array($input) ? ($input['codpes'] ?? null) : null;
$signature = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? null;
$timestamp = $_SERVER['HTTP_X_WEBHOOK_TIMESTAMP'] ?? null;
$nonce = $_SERVER['HTTP_X_WEBHOOK_NONCE'] ?? null;

if (!$codpes || !$signature || !$timestamp || !$nonce || !$rawBody || !$config['webhook_secret']) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Missing parameters']);
    exit;
}

if (!ctype_digit((string) $timestamp)) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid timestamp']);
    exit;
}

$timestampInt = (int) $timestamp;
$maxDriftSeconds = 300;
if (abs(time() - $timestampInt) > $maxDriftSeconds) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Timestamp outside allowed window']);
    exit;
}

$nonceCacheKey = 'sso_webhook_nonce_' . hash('sha256', (string) $nonce);
if ($cache->get($nonceCacheKey)) {
    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'replay' => true]);
    exit;
}

if (!$client->validateWebhookSignature($rawBody, (string) $timestamp, (string) $nonce, $signature, $config['webhook_secret'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid signature']);
    exit;
}

$cache->put($nonceCacheKey, time(), $maxDriftSeconds);
$cache->put('sso_global_logout_' . $codpes, time(), 86400);

http_response_code(200);
header('Content-Type: application/json');
echo json_encode(['success' => true]);
exit;
PHP;
        }

        throw new \InvalidArgumentException(sprintf('Template desconhecido: %s', $name));
    }
}
