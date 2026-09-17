# Guia de Integração SSO Client PHP

## Sumário

1. [Instalação](#instalação)
2. [Uso com Laravel](#uso-com-laravel)
3. [Uso com PHP Legado (7.4+)](#uso-com-php-legado-74)
4. [Referência da API](#referência-da-api)
5. [Solução de problemas](#solução-de-problemas)
6. [Suporte](#suporte)

---

## Instalação

### Via Composer

```bash
composer require sistemas-eel/sso-client
```

### Requisitos

- **PHP**: 7.4 ou superior
- **Guzzle**: ^6.0 ou ^7.0
- **Laravel** (opcional): ^8.0, ^9.0, ^10.0, ^11.0, ^12.0, ^13.0

### Configuração de Variáveis de Ambiente

Adicione ao seu `.env`:

```env
SSO_SERVER_URL=https://portalsistemas.unidade.usp.br/portal-sistemas
SSO_CLIENT_ID=seu_client_id
SSO_CLIENT_SECRET=seu_client_secret
SSO_REDIRECT_URI=https://seu-sistema.com.br/sso/callback
# Proteção dos fluxos OAuth pendentes no Laravel
SSO_OAUTH_STATE_TTL=600
SSO_OAUTH_STATE_MAX_PENDING=10
SSO_OAUTH_ROUTE_LOCK_SECONDS=30
SSO_OAUTH_ROUTE_LOCK_WAIT_SECONDS=30
SSO_WEBHOOK_SECRET=seu_webhook_secret
SSO_SYNC_PERMISSIONS=true

# SSL Configuration
SSO_VERIFY_SSL=true
# Em produção HTTPS
SESSION_SECURE_COOKIE=true
# SSO_CA_BUNDLE=/path/to/ca-bundle.crt  # Opcional
```

`SSO_OAUTH_STATE_TTL` define por quantos segundos cada fluxo permanece válido.
`SSO_OAUTH_STATE_MAX_PENDING` limita quantas tentativas são mantidas pela
sessão para compatibilidade e controle de fluxos sobrepostos.

Na integração Laravel, cada fluxo novo é registrado no cache e vinculado a um
cookie HTTP-only exclusivo, derivado do hash do `state`. O cache armazena
somente o hash do vínculo, o horário de emissão e o destino pretendido. O valor
secreto do vínculo permanece no navegador.

No callback, cache e cookie precisam corresponder. O registro é consumido sob
lock pelo próprio `state`, impedindo reutilização mesmo quando callbacks chegam
com IDs de sessão diferentes. Estados das versões anteriores continuam
aceitos pela sessão durante a atualização.

O destino pretendido também pertence ao fluxo. Destinos relativos ou absolutos
da mesma origem são aceitos; destinos externos são descartados. Essas mudanças
não alteram o scaffold para PHP legado.

`SSO_OAUTH_ROUTE_LOCK_SECONDS` define por quanto tempo o lock da sessão pode
permanecer adquirido. `SSO_OAUTH_ROUTE_LOCK_WAIT_SECONDS` define quanto uma
requisição aguarda esse lock.

As rotas automáticas de login e callback também bloqueiam requisições
concorrentes da mesma sessão. O bloqueio da rota evita sobrescritas durante a
emissão, enquanto o lock específico do `state` garante consumo único após uma
regeneração da sessão.

O driver de cache precisa armazenar os fluxos pendentes e oferecer locks
atômicos. `database` e `redis` são opções usuais. Em aplicações com múltiplas
instâncias, todas devem usar o mesmo cache compartilhado. Limpar o cache
invalida autenticações ainda em andamento.

---

## Uso com Laravel

### 1. Instalação Automática

O provedor de serviço é registrado automaticamente via Composer. Nenhuma configuração adicional é necessária.

### 2. Publicar Configuração

```bash
php artisan vendor:publish --tag=sso-client-config
```

### 3. Rotas Disponíveis

A biblioteca registra automaticamente as seguintes rotas:

| Rota | Nome | Descrição |
|------|------|-----------|
| `GET /login` | `login` | Rota padrão de login do Laravel apontando para o SSO |
| `GET /logout` | `logout` | Rota padrão de logout do Laravel apontando para o logout local da biblioteca |
| `GET /sso/callback` | `sso.callback` | Processa callback OAuth |
| `POST /api/sso/webhook-logout` | `sso.webhook-logout` | Webhook logout backchannel |

Com isso, o middleware `auth` do Laravel pode redirecionar usuários não autenticados diretamente para o fluxo SSO sem exigir uma rota `login` definida pela aplicação, e a aplicação pode usar a rota `logout` para sair pelo fluxo local da biblioteca.

Se o sistema já possuir uma tela de autenticação própria, desabilite a rota controlada pela biblioteca:

```env
SSO_LOGIN_ROUTE_ENABLED=false
SSO_LOGOUT_ROUTE_ENABLED=false
```

Se outro pacote ou a própria aplicação já registrar a rota `login` ou `logout`, a rota desta biblioteca pode não assumir o controle sozinha, porque a ordem de carregamento dos providers influencia qual definição fica ativa no final. Quando você quiser sobrescrever explicitamente esse comportamento, faça o bind manual das rotas no `routes/web.php` da aplicação:

```php
<?php

use SistemasEel\SSOClient\Laravel\Http\Controllers\SSOController;
use Illuminate\Support\Facades\Route;

Route::middleware('web')->group(function () {
    Route::get('/login', [SSOController::class, 'login'])
        ->block(30, 30)
        ->name('login');

    Route::get('/logout', [SSOController::class, 'logout'])
        ->name('logout');
});
```

Esse cenário costuma acontecer quando já existe outra biblioteca registrando `/login` ou `/logout`, como pacotes SSO anteriores ou integrações Socialite personalizadas.

A rota `sso.callback` permanece registrada automaticamente pelo pacote, já com
bloqueio. Se a aplicação também precisar sobrescrevê-la, preserve
`->block(30, 30)` na definição manual.

### 4. Middleware de Sessão (Obrigatório em Produção)

Adicione o middleware `CheckSSOSession` ao grupo `web` para validar sessões e detectar logout global. Em aplicações Laravel com sessão web, este middleware deve ser usado em produção para que eventos de logout global ou revogação encerrem a sessão local do usuário. Sem ele, o login OAuth continua funcionando, mas a aplicação cliente não reage ao logout global recebido pelo webhook. O local dessa configuração depende da versão do Laravel.

Em Laravel 8, 9 e 10, use o `app/Http/Kernel.php`:

```php
// app/Http/Kernel.php

protected $middlewareGroups = [
    'web' => [
        // ...
        \SistemasEel\SSOClient\Laravel\Http\Middleware\CheckSSOSession::class,
    ],
];
```

Em Laravel 11 ou superior, o esqueleto padrão não possui mais `app/Http/Kernel.php`. Nesse caso, registre o middleware em `bootstrap/app.php`:

```php
use Illuminate\Foundation\Configuration\Middleware;
use SistemasEel\SSOClient\Laravel\Http\Middleware\CheckSSOSession;

->withMiddleware(function (Middleware $middleware): void {
    $middleware->appendToGroup('web', [
        CheckSSOSession::class,
    ]);
})
```

Após alterar a configuração de middleware, limpe os caches da aplicação:

```bash
php artisan optimize:clear
```

### 5. Personalizar Modelo de Usuário

Por padrão, o controller usa `App\Models\User`. Para personalizar:

```php
// Em seu AppServiceProvider
public function boot(): void
{
    // Opção 1: Estender o SSOController
    // Opção 2: Usar eventos (em desenvolvimento)
}
```

### 6. Verificar Autenticação

```php
use Illuminate\Support\Facades\Auth;

if (Auth::check()) {
    $user = Auth::user();
    echo "Bem-vindo, " . $user->name;
}
```

### 7. Usar Cliente SSO Diretamente

```php
use SistemasEel\SSOClient\Core\SSOClient;

public function meuMetodo(SSOClient $client)
{
    $userInfo = $client->getUserInfo($accessToken);
    // ...
}
```

---

## Uso com PHP Legado (7.4+)

### 1. Instalação

```bash
composer require sistemas-eel/sso-client
```

### 2. Caminho Recomendado: Gerar Arquivos Base

A biblioteca pode criar a estrutura inicial da integração legada. Rode o comando na raiz do sistema cliente, ou seja, no mesmo diretório onde fica o `composer.json` da aplicação legada:

```bash
vendor/bin/sso-client-legacy-scaffold
```

Para gerar em outro diretório:

```bash
vendor/bin/sso-client-legacy-scaffold /caminho/do/projeto
```

Por segurança, o comando não sobrescreve arquivos existentes. Para regenerar os arquivos:

```bash
vendor/bin/sso-client-legacy-scaffold --force
```

Depois de gerar, ajuste `sso/config.php` ou configure as variáveis de ambiente `SSO_SERVER_URL`, `SSO_CLIENT_ID`, `SSO_CLIENT_SECRET`, `SSO_REDIRECT_URI` e `SSO_WEBHOOK_SECRET`.

### 3. Estrutura Gerada

Após executar o scaffold, a aplicação terá estes arquivos:

```
seu-projeto/
├── vendor/
├── api/
│   └── sso/
│       └── webhook-logout.php
├── sso/
│   ├── cache/
│   │   └── .gitignore
│   ├── config.php
│   ├── bootstrap.php
│   ├── login.php
│   ├── callback.php
│   ├── logout.php
│   └── session-check.php
├── composer.json
└── index.php
```

Resumo dos arquivos:

| Arquivo | Função |
|---------|--------|
| `sso/config.php` | Configuração local e leitura de variáveis `SSO_*` |
| `sso/bootstrap.php` | Inicializa cliente, sessão e cache |
| `sso/login.php` | Inicia o fluxo OAuth |
| `sso/callback.php` | Processa callback, busca usuário e cria sessão |
| `sso/logout.php` | Encerra a sessão local |
| `sso/session-check.php` | Proteção de páginas, refresh token e logout global |
| `api/sso/webhook-logout.php` | Endpoint de logout backchannel |
| `sso/cache/.gitignore` | Mantém o diretório de cache fora do versionamento |

### 4. Entenda e Ajuste os Arquivos Gerados

As próximas seções mostram o conteúdo principal dos arquivos gerados. Você não precisa copiar esses exemplos se usou o scaffold; use-os apenas para revisar e adaptar a integração ao sistema legado.

### 4.1. Configuração Local da Aplicação

O arquivo `sso/config.php` armazena credenciais e URLs do sistema legado:

```php
<?php
// sso/config.php

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
```

### 4.2. Bootstrap Compartilhado

O arquivo `sso/bootstrap.php` consome a configuração e instancia os objetos reutilizáveis:

```php
<?php
// sso/bootstrap.php

use SistemasEel\SSOClient\Cache\FileCacheHandler;
use SistemasEel\SSOClient\Core\SSOClient;
use SistemasEel\SSOClient\Session\SessionHandler;

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
```

### 4.3. Login

```php
<?php
// sso/login.php

require_once __DIR__ . '/bootstrap.php';

// Gera state parameter
$state = bin2hex(random_bytes(20));
$session->set('oauth_state', $state);

// Redireciona para SSO
$authUrl = $client->getAuthorizationUrl($state);
header('Location: ' . $authUrl);
exit;
```

### 4.4. Callback

```php
<?php
// sso/callback.php

require_once __DIR__ . '/bootstrap.php';

// Valida state
$state = $session->remove('oauth_state');
if (!$state || $_GET['state'] !== $state) {
    die('OAuth state inválido');
}

// Troca código por token
$tokenResponse = $client->exchangeCodeForToken($_GET['code']);
if (!$tokenResponse || !isset($tokenResponse['access_token'])) {
    die('Falha ao obter token');
}

// Obtém usuário
$ssoUser = $client->getUserInfo($tokenResponse['access_token']);
if (!$ssoUser || !isset($ssoUser['codpes'])) {
    die('Falha ao obter dados do usuário');
}

// ========================================================================
# INTEGRE COM SEU BANCO DE DADOS AQUI
// ========================================================================
// $pdo = new PDO('mysql:host=localhost;dbname=db', 'user', 'pass');
// $stmt = $pdo->prepare("SELECT * FROM users WHERE codpes = ?");
// $stmt->execute([$ssoUser['codpes']]);
// $user = $stmt->fetch(PDO::FETCH_ASSOC);
//
// if (!$user) {
//     $stmt = $pdo->prepare(
//         "INSERT INTO users (codpes, name, email) VALUES (?, ?, ?)"
//     );
//     $stmt->execute([$ssoUser['codpes'], $ssoUser['name'], $ssoUser['email']]);
// }
// ========================================================================

// Armazena sessão
$session->set('user_authenticated', true);
$session->set('user_codpes', $ssoUser['codpes']);
$session->set('user_name', $ssoUser['name'] ?? '');
$session->set('user_email', $ssoUser['email'] ?? '');
$session->set('access_token', $tokenResponse['access_token']);
$session->set('refresh_token', $tokenResponse['refresh_token'] ?? null);
$session->set('expires_at', time() + ($tokenResponse['expires_in'] ?? 3600));
$session->set('login_timestamp', time());

// Redireciona
$session->regenerate();
header('Location: ' . $config['home_path']);
exit;
```

### 4.5. Verificação de Sessão

```php
<?php
// sso/session-check.php

require_once __DIR__ . '/bootstrap.php';

// Verifica autenticação
if (!$session->has('user_authenticated') || !$session->get('user_authenticated')) {
    header('Location: ' . $config['login_url']);
    exit;
}

// Verifica logout global
$codpes = $session->get('user_codpes');
$loginTime = $session->get('login_timestamp');
$globalLogout = $cache->get('sso_global_logout_' . $codpes);

if ($globalLogout && $loginTime && $globalLogout >= $loginTime) {
    $session->clear();
    $session->destroy();
    header('Location: ' . $config['logout_redirect'] . '?reason=global_logout');
    exit;
}

// Token expirado? Renova
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

// Usuário válido - disponibiliza variáveis
$userCodpes = $session->get('user_codpes');
$userName = $session->get('user_name');
$userEmail = $session->get('user_email');
$accessToken = $session->get('access_token');
```

### 4.6. Logout

```php
<?php
// sso/logout.php

require_once __DIR__ . '/bootstrap.php';

$session->clear();
$session->destroy();

header('Location: ' . $config['logout_redirect']);
exit;
```

### 4.7. Webhook de Logout Backchannel

```php
<?php
// api/sso/webhook-logout.php

require_once __DIR__ . '/../sso/bootstrap.php';

$rawBody = file_get_contents('php://input');
$input = json_decode($rawBody, true);
$codpes = $input['codpes'] ?? null;
$signature = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? null;
$timestamp = $_SERVER['HTTP_X_WEBHOOK_TIMESTAMP'] ?? null;
$nonce = $_SERVER['HTTP_X_WEBHOOK_NONCE'] ?? null;

if (!$codpes || !$signature || !$timestamp || !$nonce || !$rawBody) {
    http_response_code(400);
    echo json_encode(['error' => 'Parâmetros ausentes']);
    exit;
}

if (!ctype_digit((string) $timestamp)) {
    http_response_code(401);
    echo json_encode(['error' => 'Timestamp inválido']);
    exit;
}

$timestampInt = (int) $timestamp;
$maxDriftSeconds = 300;
if (abs(time() - $timestampInt) > $maxDriftSeconds) {
    http_response_code(401);
    echo json_encode(['error' => 'Timestamp fora da janela permitida']);
    exit;
}

$nonceCacheKey = 'sso_webhook_nonce_' . hash('sha256', $nonce);
if ($cache->get($nonceCacheKey)) {
    // Idempotente em retry
    http_response_code(200);
    echo json_encode(['success' => true, 'replay' => true]);
    exit;
}

$expected = hash_hmac('sha256', $timestamp . $nonce . $rawBody, (string) $config['webhook_secret']);
if (!hash_equals($expected, $signature)) {
    http_response_code(401);
    echo json_encode(['error' => 'Assinatura inválida']);
    exit;
}

$cache->put($nonceCacheKey, time(), $maxDriftSeconds);
// Registra logout
$cache->put('sso_global_logout_' . $codpes, time(), 86400);

http_response_code(200);
echo json_encode(['success' => true]);
exit;
```

---

## Referência da API

### SSOClient

#### `getAuthorizationUrl(string $state): string`

Retorna a URL para redirecionar o usuário ao servidor SSO.

```php
$state = bin2hex(random_bytes(20));
$url = $client->getAuthorizationUrl($state);
```

#### `exchangeCodeForToken(string $code): ?array`

Troca o código de autorização por um access token.

```php
$token = $client->exchangeCodeForToken($_GET['code']);
// Retorna: ['access_token' => '...', 'refresh_token' => '...', 'expires_in' => 3600]
```

#### `getUserInfo(string $accessToken): ?array`

Obtém informações do usuário autenticado.

```php
$user = $client->getUserInfo($token['access_token']);
// Retorna: ['codpes' => 12345, 'name' => 'João Silva', 'email' => 'joao@usp.br']
```

#### `refreshToken(string $refreshToken): ?array`

Renova o access token usando o refresh token.

```php
$newToken = $client->refreshToken($refreshToken);
```

#### Assinatura do webhook (HMAC)

Formato esperado:

```php
$signature = hash_hmac('sha256', $timestamp . $nonce . $rawBody, $webhookSecret);
```

O cliente deve validar com `hash_equals` após conferir timestamp e nonce.

### SessionHandler (PHP Legado)

#### `set(string $key, $value): void`

```php
$session->set('user_codpes', 12345);
```

#### `get(string $key, $default = null): mixed`

```php
$codpes = $session->get('user_codpes');
```

#### `remove(string $key): mixed`

```php
$state = $session->remove('oauth_state');
```

#### `has(string $key): bool`

```php
if ($session->has('user_authenticated')) { ... }
```

#### `clear(): void`

```php
$session->clear(); // Limpa variáveis SSO
```

#### `destroy(): void`

```php
$session->destroy(); // Destroi sessão completamente
```

### FileCacheHandler (PHP Legado)

#### `put(string $key, $value, int $ttl): bool`

```php
$cache->put('sso_global_logout_12345', time(), 86400); // 24h
```

#### `get(string $key, $default = null): mixed`

```php
$logoutTime = $cache->get('sso_global_logout_12345');
```

#### `forget(string $key): bool`

```php
$cache->forget('sso_global_logout_12345');
```

## Solução de problemas

### Erro: "OAuth state inválido"

**Causas possíveis**:

- a sessão da aplicação não foi preservada entre login e callback;
- o callback chegou depois do TTL configurado;
- o `state` é desconhecido, malformado ou já foi utilizado;
- uma aba antiga restaurou ou repetiu uma URL de callback;
- rotas de login ou callback sobrescritas manualmente sem bloqueio de sessão;
- requisições realmente concorrentes da mesma sessão sobrescreveram estados
  pendentes em uma versão antiga do pacote;
- o cache da aplicação foi limpo entre login e callback;
- as instâncias da aplicação não compartilham o mesmo cache;
- o cookie de vínculo do fluxo não retornou ao callback;
- em produção HTTPS, confira `SESSION_SECURE_COOKIE=true`, domínio, caminho e
  `SameSite` dos cookies.

**Verificações**:

1. confirme cookie, domínio, caminho, HTTPS e armazenamento da sessão;
2. confira `SSO_OAUTH_STATE_TTL` e o tempo decorrido durante o login;
3. confirme que todas as instâncias usam o mesmo cache compartilhado;
4. verifique se o cache não foi limpo durante o fluxo;
5. em produção HTTPS, confirme `SESSION_SECURE_COOKIE=true`;
6. confira se rotas manuais de login e callback usam `->block(30, 30)`;
7. inicie um novo login em vez de recarregar uma URL antiga do callback;
8. não desabilite a validação de `state`, pois ela protege o callback contra
   CSRF.

### Erro: "SSL certificate problem"

**Causa**: Servidor SSO tem certificado SSL inválido ou auto-assinado.

**Solução** (apenas para desenvolvimento):
```env
SSO_VERIFY_SSL=false
```

**Solução correta**: Configure o CA bundle adequado:
```env
SSO_VERIFY_SSL=true
SSO_CA_BUNDLE=/path/to/ca-bundle.crt
```

### Erro: "Não foi possível trocar o código por token"

**Causas possíveis**:
- Client ID ou Secret incorretos
- Redirect URI não corresponde ao registrado no Portal
- Código de autorização já foi usado

**Solução**: Verifique credenciais e se redirect URI está correto.

### Logout backchannel não funciona

**Causa**: Webhook não está sendo chamado ou cache não está funcionando.

**Solução**:
1. Verifique se URL do webhook está acessível publicamente
2. Verifique se `SSO_WEBHOOK_SECRET` está configurado corretamente
3. Verifique logs do servidor SSO

---

## Suporte

Para dúvidas ou problemas, abra uma issue no repositório:
https://github.com/sistemas-eel/sso-client/issues
