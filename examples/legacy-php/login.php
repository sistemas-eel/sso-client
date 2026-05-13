<?php
/**
 * Exemplo de integração SSO em aplicação PHP legado (7.4+).
 *
 * Este arquivo concentra login, callback, logout local e webhook logout.
 * Em produção, você pode separar cada rota em um arquivo próprio.
 */

require_once __DIR__ . '/bootstrap.php';

use PortalSistemas\SSOClient\Cache\FileCacheHandler;
use PortalSistemas\SSOClient\Core\SSOClient;
use PortalSistemas\SSOClient\Session\SessionHandler;

// ============================================================================
// ROTEAMENTO SIMPLES
// ============================================================================

$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

switch ($requestUri) {
    case '/sso/login.php':
        handleLogin($client, $session);
        break;
        
    case '/sso/callback.php':
        handleCallback($client, $session, $config);
        break;
        
    case '/sso/logout.php':
        handleLogout($session, $config);
        break;
        
    case '/api/sso/webhook-logout.php':
        handleWebhookLogout($client, $cache, $config);
        break;
        
    default:
        echo "Página principal - Use /sso/login.php para autenticar";
        break;
}

// ============================================================================
// HANDLERS
// ============================================================================

/**
 * Redireciona usuário para o servidor SSO.
 */
function handleLogin(SSOClient $client, SessionHandler $session): void
{
    // Gera state parameter seguro
    $state = bin2hex(random_bytes(20));
    $session->set('oauth_state', $state);
    
    // Redireciona para SSO
    $authUrl = $client->getAuthorizationUrl($state);
    header('Location: ' . $authUrl);
    exit;
}

/**
 * Processa o callback do SSO após autenticação.
 */
function handleCallback(SSOClient $client, SessionHandler $session, array $config): void
{
    // Valida state parameter
    $state = $session->remove('oauth_state');
    
    if (!$state || !isset($_GET['state']) || $_GET['state'] !== $state) {
        http_response_code(403);
        die('OAuth state inválido. Possível ataque CSRF.');
    }
    
    if (!isset($_GET['code'])) {
        http_response_code(400);
        die('Código de autorização não recebido.');
    }
    
    // Troca código por token
    $tokenResponse = $client->exchangeCodeForToken($_GET['code']);
    
    if (!$tokenResponse || !isset($tokenResponse['access_token'])) {
        header('Location: /index.php?error=token_failed');
        exit;
    }
    
    // Obtém informações do usuário
    $ssoUser = $client->getUserInfo($tokenResponse['access_token']);
    
    if (!$ssoUser || !isset($ssoUser['codpes'])) {
        header('Location: /index.php?error=user_info_failed');
        exit;
    }
    
    // ========================================================================
    // AQUI: Integração com seu sistema de usuários
    // ========================================================================
    // Exemplo com PDO:
    // $pdo = new PDO('mysql:host=localhost;dbname=seu_db', 'user', 'pass');
    // $stmt = $pdo->prepare("SELECT * FROM users WHERE codpes = ?");
    // $stmt->execute([$ssoUser['codpes']]);
    // $user = $stmt->fetch(PDO::FETCH_ASSOC);
    // 
    // if (!$user) {
    //     $stmt = $pdo->prepare(
    //         "INSERT INTO users (codpes, name, email) VALUES (?, ?, ?)"
    //     );
    //     $stmt->execute([$ssoUser['codpes'], $ssoUser['name'], $ssoUser['email']]);
    //     $userId = $pdo->lastInsertId();
    // } else {
    //     $userId = $user['id'];
    // }
    // ========================================================================
    
    // Armazena dados da sessão SSO
    $session->set('user_authenticated', true);
    $session->set('user_codpes', $ssoUser['codpes']);
    $session->set('user_name', $ssoUser['name'] ?? '');
    $session->set('user_email', $ssoUser['email'] ?? '');
    $session->set('access_token', $tokenResponse['access_token']);
    $session->set('refresh_token', $tokenResponse['refresh_token'] ?? null);
    $session->set('expires_at', time() + ($tokenResponse['expires_in'] ?? 3600));
    $session->set('login_timestamp', time());
    
    // Redireciona para página principal
    $session->regenerate();
    header('Location: ' . $config['home_path']);
    exit;
}

/**
 * Realiza logout local.
 */
function handleLogout(SessionHandler $session, array $config): void
{
    $session->clear();
    $session->destroy();
    
    header('Location: ' . $config['logout_redirect']);
    exit;
}

/**
 * Processa webhook de logout backchannel.
 */
function handleWebhookLogout(
    SSOClient $client,
    FileCacheHandler $cache,
    array $config
): void {
    // Apenas método POST é aceito
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
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
        echo json_encode(['error' => 'Missing parameters']);
        exit;
    }

    if (!ctype_digit((string) $timestamp)) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid timestamp']);
        exit;
    }

    $timestampInt = (int) $timestamp;
    $maxDriftSeconds = 300;
    if (abs(time() - $timestampInt) > $maxDriftSeconds) {
        http_response_code(401);
        echo json_encode(['error' => 'Timestamp outside allowed window']);
        exit;
    }

    $nonceCacheKey = 'sso_webhook_nonce_' . hash('sha256', (string) $nonce);
    if ($cache->get($nonceCacheKey)) {
        http_response_code(200);
        echo json_encode(['success' => true, 'replay' => true]);
        exit;
    }
    
    // Valida assinatura HMAC-SHA256
    if (!$client->validateWebhookSignature($rawBody, (string) $timestamp, (string) $nonce, $signature, $config['webhook_secret'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid signature']);
        exit;
    }
    
    header('Content-Type: application/json');

    $cache->put($nonceCacheKey, time(), $maxDriftSeconds);

    // Registra logout no cache para verificação em middleware
    $cache->put('sso_global_logout_' . $codpes, time(), 86400); // 24 horas
    
    http_response_code(200);
    echo json_encode(['success' => true]);
    exit;
}
