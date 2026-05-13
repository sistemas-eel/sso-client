<?php

namespace PortalSistemas\SSOClient\Core;

use PortalSistemas\SSOClient\Agente\AgenteAuthException;
use PortalSistemas\SSOClient\Agente\TokenValidator;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Cliente OAuth2 para comunicação com o Portal de Sistemas.
 *
 * Esta classe implementa o fluxo Authorization Code do OAuth2,
 * permitindo autenticação, obtenção de tokens, consulta de dados
 * do usuário e renovação de tokens.
 *
 * ## Exemplo de Uso Básico
 *
 * ```php
 * use PortalSistemas\SSOClient\Core\SSOClient;
 *
 * $client = new SSOClient(
 *     'https://portalsistemas.unidade.usp.br/portal-sistemas',
 *     'seu_client_id',
 *     'seu_client_secret',
 *     'https://seu-sistema.com.br/sso/callback',
 *     true  // Verificação SSL habilitada
 * );
 *
 * // Passo 1: Redirecionar usuário para autorização
 * $state = bin2hex(random_bytes(20));
 * $authUrl = $client->getAuthorizationUrl($state);
 * header('Location: ' . $authUrl);
 * exit;
 *
 * // Passo 2: No callback, trocar código por token
 * $tokenResponse = $client->exchangeCodeForToken($_GET['code']);
 * if ($tokenResponse && isset($tokenResponse['access_token'])) {
 *     // Passo 3: Obter dados do usuário
 *     $userInfo = $client->getUserInfo($tokenResponse['access_token']);
 *     echo "Usuário: {$userInfo['name']} ({$userInfo['email']})";
 * }
 * ```
 *
 * ## Estrutura de Respostas
 *
 * ### exchangeCodeForToken() e refreshToken()
 *
 * Retorna um array associativo com as seguintes chaves (quando bem-sucedido):
 *
 * ```php
 * [
 *     'access_token'  => 'eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9...',
 *     'refresh_token' => 'def50200a1b2c3d4e5f6...',
 *     'expires_in'    => 3600,
 *     'token_type'    => 'Bearer',
 *     'scope'         => '*'
 * ]
 * ```
 *
 * Em caso de erro, retorna `null`. Verifique logs do Guzzle para detalhes.
 *
 * ### getUserInfo()
 *
 * Retorna um array associativo com os dados do usuário:
 *
 * ```php
 * [
 *     'codpes' => 12345678,
 *     'name'   => 'João da Silva',
 *     'email'  => 'joao.silva@usp.br'
 * ]
 * ```
 *
 * A estrutura exata depende da implementação do servidor SSO.
 *
 * ## Tratamento de Erros
 *
 * Todos os métodos que fazem requisições HTTP retornam `null` em caso de falha.
 * Para obter detalhes do erro, consulte os logs do Guzzle ou habilite debug:
 *
 * ```php
 * $client = new SSOClient($baseUrl, $clientId, $clientSecret, $redirectUri);
 * // Configure Guzzle com debug nas opções do cliente HTTP
 * ```
 *
 * @package PortalSistemas\SSOClient\Core
 * @author  Gustavo
 * @license MIT
 * @link    https://github.com/sistemas-eel/sso-client
 */
class SSOClient
{
    /** @var string URL base do servidor SSO */
    private $baseUrl;

    /** @var string Identificador do cliente OAuth */
    private $clientId;

    /** @var string Segredo do cliente OAuth */
    private $clientSecret;

    /** @var string URI de redirecionamento registrada no servidor SSO */
    private $redirectUri;

    /** @var Client Cliente HTTP Guzzle para requisições */
    private $httpClient;

    /**
     * Inicializa o cliente SSO com as configurações fornecidas.
     *
     * @param string      $baseUrl      URL base do servidor SSO (ex: https://portalsistemas.unidade.usp.br/portal-sistemas)
     * @param string      $clientId     Identificador do cliente OAuth registrado no Portal
     * @param string      $clientSecret Segredo do cliente OAuth
     * @param string      $redirectUri  URI de redirecionamento (deve estar registrada no Portal)
     * @param bool        $verifySsl    Habilita verificação de certificado SSL (padrão: true)
     * @param string|null $caBundle     Caminho para arquivo CA bundle personalizado (opcional)
     *
     * @example
     * ```php
     * // Uso básico com SSL habilitado
     * $client = new SSOClient(
     *     'https://portalsistemas.unidade.usp.br/portal-sistemas',
     *     'meu_client_id',
     *     'meu_client_secret',
     *     'https://meu-sistema.com.br/sso/callback'
     * );
     *
     * // Com CA bundle personalizado
     * $client = new SSOClient(
     *     'https://portalsistemas.unidade.usp.br/portal-sistemas',
     *     'meu_client_id',
     *     'meu_client_secret',
     *     'https://meu-sistema.com.br/sso/callback',
     *     true,
     *     '/etc/ssl/certs/ca-bundle.crt'
     * );
     *
     * // Desabilitar SSL (apenas desenvolvimento!)
     * $client = new SSOClient(
     *     'https://portalsistemas.unidade.usp.br/portal-sistemas',
     *     'meu_client_id',
     *     'meu_client_secret',
     *     'https://meu-sistema.com.br/sso/callback',
     *     false
     * );
     * ```
     */
    public function __construct(string $baseUrl, string $clientId, string $clientSecret, string $redirectUri, bool $verifySsl = true, ?string $caBundle = null)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->redirectUri = $redirectUri;

        $sslConfig = ['verify' => $verifySsl];

        if ($caBundle !== null) {
            $sslConfig['verify'] = $caBundle;
        }

        $sslConfig['timeout'] = 5.0;

        $this->httpClient = new Client($sslConfig);
    }

    /**
     * Retorna a URL para redirecionar o usuário para o login do SSO.
     *
     * Este método gera a URL de autorização OAuth2 que deve ser usada
     * para redirecionar o navegador do usuário ao servidor SSO.
     *
     * @param string $state Token aleatório para prevenção de ataques CSRF.
     *                      Deve ser armazenado na sessão e validado no callback.
     *                      Recomenda-se: `bin2hex(random_bytes(20))`
     *
     * @return string URL completa de autorização
     *
     * @example
     * ```php
     * // Gera state parameter seguro
     * $state = bin2hex(random_bytes(20));
     *
     * // Armazena na sessão para validação posterior
     * $_SESSION['oauth_state'] = $state;
     *
     * // Gera URL e redireciona
     * $authUrl = $client->getAuthorizationUrl($state);
     * header('Location: ' . $authUrl);
     * exit;
     * ```
     *
     * @see https://oauth.net/2/grant-types/authorization-code/ OAuth2 Authorization Code Flow
     */
    public function getAuthorizationUrl(string $state): string
    {
        $params = [
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'scope' => '*',
            'state' => $state,
        ];

        return $this->baseUrl . '/oauth/authorize?' . http_build_query($params);
    }

    /**
     * Troca o código de autorização por um Access Token.
     *
     * Este método deve ser chamado no callback do SSO, após o usuário
     * ser redirecionado de volta à aplicação. Ele troca o código de
     * autorização temporário por um access token utilizável.
     *
     * @param string $code Código de autorização recebido no callback (?code=...)
     *
     * @return array|null Array com dados do token ou null em caso de erro.
     *                    Estrutura do array de sucesso:
     *                    ```php
     *                    [
     *                        'access_token'  => 'eyJhbGci...',  // Token de acesso
     *                        'refresh_token' => 'def502...',    // Token de renovação
     *                        'expires_in'    => 3600,           // Duração em segundos
     *                        'token_type'    => 'Bearer',       // Tipo do token
     *                        'scope'         => '*'             // Escopos autorizados
     *                    ]
     *                    ```
     *
     * @example
     * ```php
     * // No callback.php
     * $code = $_GET['code'] ?? null;
     *
     * if (!$code) {
     *     die('Código de autorização não recebido');
     * }
     *
     * $tokenResponse = $client->exchangeCodeForToken($code);
     *
     * if (!$tokenResponse || !isset($tokenResponse['access_token'])) {
     *     die('Falha ao obter token de acesso');
     * }
     *
     * $accessToken = $tokenResponse['access_token'];
     * $refreshToken = $tokenResponse['refresh_token'] ?? null;
     * $expiresIn = $tokenResponse['expires_in'] ?? 3600;
     * ```
     *
     * @see https://oauth.net/2/grant-types/authorization-code/ Step 4: Token Request
     */
    public function exchangeCodeForToken(string $code): ?array
    {
        try {
            $response = $this->httpClient->post($this->baseUrl . '/oauth/token', [
                'form_params' => [
                    'grant_type' => 'authorization_code',
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'redirect_uri' => $this->redirectUri,
                    'code' => $code,
                ],
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            return null;
        }
    }

    /**
     * Obtém informações do usuário autenticado no SSO.
     *
     * Utiliza o access token obtido via exchangeCodeForToken() para
     * consultar os dados do usuário no servidor SSO.
     *
     * @param string $accessToken Access Token válido obtido em exchangeCodeForToken()
     *
     * @return array|null Array com dados do usuário ou null em caso de erro.
     *                    Estrutura típica do array:
     *                    ```php
     *                    [
     *                        'codpes' => 12345678,          // Número USP (int)
     *                        'name'   => 'João da Silva',  // Nome completo
     *                        'email'  => 'joao@usp.br',    // Email institucional
     *                        'dpto'   => 'eel.usp.br'      // Departamento/área (opcional)
     *                    ]
     *                    ```
     *                    Nota: A estrutura exata depende da implementação do servidor SSO.
     *
     * @example
     * ```php
     * $userInfo = $client->getUserInfo($accessToken);
     *
     * if (!$userInfo || !isset($userInfo['codpes'])) {
     *     die('Falha ao obter informações do usuário');
     * }
     *
     * echo "Bem-vindo, {$userInfo['name']}!";
     * echo "Número USP: {$userInfo['codpes']}";
     * echo "Email: {$userInfo['email']}";
     *
     * // Integração com banco de dados local
     * $stmt = $pdo->prepare("SELECT * FROM users WHERE codpes = ?");
     * $stmt->execute([$userInfo['codpes']]);
     * $user = $stmt->fetch(PDO::FETCH_ASSOC);
     *
     * if (!$user) {
     *     $stmt = $pdo->prepare(
     *         "INSERT INTO users (codpes, name, email) VALUES (?, ?, ?)"
     *     );
     *     $stmt->execute([$userInfo['codpes'], $userInfo['name'], $userInfo['email']]);
     * }
     * ```
     *
     * @see exchangeCodeForToken() Para obter o access token
     */
    public function getUserInfo(string $accessToken): ?array
    {
        try {
            $response = $this->httpClient->get($this->baseUrl . '/api/user', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Accept' => 'application/json',
                ],
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            return null;
        }
    }

    /**
     * Renova o access token usando o refresh token.
     *
     * Quando um access token expira (após o tempo indicado em expires_in),
     * este método permite obter um novo token sem exigir que o usuário
     * faça login novamente.
     *
     * @param string $refreshToken Refresh Token obtido em exchangeCodeForToken()
     *
     * @return array|null Array com novos dados do token ou null em caso de erro.
     *                    Estrutura similar a exchangeCodeForToken():
     *                    ```php
     *                    [
     *                        'access_token'  => 'novo_eyJhbGci...',
     *                        'refresh_token' => 'novo_def502...',  // Pode ser o mesmo ou novo
     *                        'expires_in'    => 3600,
     *                        'token_type'    => 'Bearer'
     *                    ]
     *                    ```
     *
     * @example
     * ```php
     * // Verifica se token expirou
     * $expiresAt = $_SESSION['sso_expires_at'] ?? 0;
     * $refreshToken = $_SESSION['sso_refresh_token'] ?? null;
     *
     * if (time() > $expiresAt && $refreshToken) {
     *     $newToken = $client->refreshToken($refreshToken);
     *
     *     if ($newToken && isset($newToken['access_token'])) {
     *         // Token renovado com sucesso
     *         $_SESSION['sso_access_token'] = $newToken['access_token'];
     *         $_SESSION['sso_refresh_token'] = $newToken['refresh_token'] ?? $refreshToken;
     *         $_SESSION['sso_expires_at'] = time() + ($newToken['expires_in'] ?? 3600);
     *     } else {
     *         // Falha ao renovar - redirecionar para login
     *         unset($_SESSION['sso_access_token'], $_SESSION['sso_refresh_token']);
     *         header('Location: /login.php');
     *         exit;
     *     }
     * }
     * ```
     *
     * @see exchangeCodeForToken() Para obter o refresh token inicial
     */
    public function refreshToken(string $refreshToken): ?array
    {
        try {
            $response = $this->httpClient->post($this->baseUrl . '/oauth/token', [
                'form_params' => [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refreshToken,
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                ],
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            return null;
        }
    }

    /**
     * Valida um access token do Agente IA via introspecção remota no SSO.
     *
     * @param string $token
     * @param array<int, string> $requiredScopes
     * @param array<string, mixed> $options
     * @return array<string, mixed>|null
     */
    public function validateAccessToken(string $token, array $requiredScopes = [], array $options = []): ?array
    {
        $config = array_merge([
            'introspection_endpoint' => $this->baseUrl . '/api/oauth/introspect',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'required_scopes' => $requiredScopes,
            'timeout' => 5,
            'verify_ssl' => true,
        ], $options);

        $validator = new TokenValidator($config);

        try {
            return $validator->validate($token);
        } catch (AgenteAuthException $e) {
            return null;
        }
    }

    /**
     * Valida a assinatura de um webhook de logout backchannel.
     *
     * O servidor SSO pode notificar a aplicação sobre logouts globais
     * (quando o usuário faz logout em outro sistema). Este método valida
     * a assinatura HMAC-SHA256 da notificação para garantir autenticidade.
     *
     * @param string $rawBody       Corpo JSON bruto recebido no webhook
     * @param string $timestamp     Timestamp recebido no header X-Webhook-Timestamp
     * @param string $nonce         Nonce recebido no header X-Webhook-Nonce
     * @param string $signature     Assinatura HMAC-SHA256 recebida no header X-Webhook-Signature
     * @param string $webhookSecret Segredo compartilhado configurado no Portal SSO
     *
     * @return bool True se a assinatura é válida, false caso contrário
     *
     * @example
     * ```php
     * // webhook-logout.php
     * $rawBody = file_get_contents('php://input');
     * $input = json_decode($rawBody, true);
     * $codpes = is_array($input) ? ($input['codpes'] ?? null) : null;
     * $signature = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? null;
     * $timestamp = $_SERVER['HTTP_X_WEBHOOK_TIMESTAMP'] ?? null;
     * $nonce = $_SERVER['HTTP_X_WEBHOOK_NONCE'] ?? null;
     *
     * if (!$codpes || !$signature || !$timestamp || !$nonce || !$rawBody) {
     *     http_response_code(400);
     *     echo json_encode(['error' => 'Missing parameters']);
     *     exit;
     * }
     *
     * if (!$client->validateWebhookSignature($rawBody, $timestamp, $nonce, $signature, $webhookSecret)) {
     *     http_response_code(401);
     *     echo json_encode(['error' => 'Invalid signature']);
     *     exit;
     * }
     *
     * // Assinatura válida - registra logout no cache
     * $cache->put('sso_global_logout_' . $codpes, time(), 86400);
     *
     * http_response_code(200);
     * echo json_encode(['success' => true]);
     * ```
     *
     * @see https://tools.ietf.org/html/rfc2104 HMAC-SHA256 specification
     */
    public function validateWebhookSignature(string $rawBody, string $timestamp, string $nonce, string $signature, string $webhookSecret): bool
    {
        $expected = hash_hmac('sha256', $timestamp . $nonce . $rawBody, $webhookSecret);
        return hash_equals($expected, $signature);
    }
}
