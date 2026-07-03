<?php
/**
 * Inclua este arquivo no topo de páginas protegidas para validar:
 * - sessão autenticada
 * - logout global via webhook
 * - refresh de token quando necessário
 */

require_once __DIR__ . '/bootstrap.php';

// Verifica se usuário está autenticado
if (!$session->has('user_authenticated') || !$session->get('user_authenticated')) {
    header('Location: ' . $config['login_url']);
    exit;
}

// Verifica se houve logout global (backchannel)
$userCodpes = $session->get('user_codpes');
$loginTime = $session->get('login_timestamp');
$globalLogoutTime = $cache->get('sso_global_logout_' . $userCodpes);

if ($globalLogoutTime && $loginTime && $globalLogoutTime >= $loginTime) {
    // Logout global detectado - força novo login
    $session->clear();
    $session->destroy();
    header('Location: ' . $config['logout_redirect'] . '?reason=global_logout');
    exit;
}

// Verifica se token expirou e tenta renovar
$expiresAt = $session->get('expires_at');
$refreshToken = $session->get('refresh_token');

if ($expiresAt && time() > $expiresAt && $refreshToken) {
    // Token expirado - tenta renovar
    $newToken = $client->refreshToken($refreshToken);
    
    if ($newToken && isset($newToken['access_token'])) {
        // Token renovado com sucesso
        $session->set('access_token', $newToken['access_token']);
        $session->set('refresh_token', $newToken['refresh_token'] ?? $refreshToken);
        $session->set('expires_at', time() + ($newToken['expires_in'] ?? 3600));
    } else {
        // Falha ao renovar - força novo login
        $session->clear();
        $session->destroy();
        header('Location: ' . $config['login_url'] . '?reason=token_expired');
        exit;
    }
}

// ============================================================================
// VARIÁVEIS DE USUÁRIO DISPONÍVEIS
// ============================================================================

$userCodpes = $session->get('user_codpes');
$userName = $session->get('user_name');
$userEmail = $session->get('user_email');
$accessToken = $session->get('access_token');

// Agora você pode usar essas variáveis na sua página
// echo "Bem-vindo, {$userName} ({$userEmail})";
