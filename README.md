# SSO Client PHP - Cliente OAuth2 para Portal de Sistemas

[![PHP Version](https://img.shields.io/badge/php-%5E7.4%7C%5E8.0-blue.svg)](https://php.net)
[![Laravel Version](https://img.shields.io/badge/laravel-%5E8.0%7C%5E9.0%7C%5E10.0%7C%5E11.0%7C%5E12.0-red.svg)](https://laravel.com)
[![Guzzle Version](https://img.shields.io/badge/guzzle-%5E6.0%7C%5E7.0-orange.svg)](http://docs.guzzlephp.org)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

Cliente OAuth2 para integração com o Portal de Sistemas. Funciona com **Laravel 8+** e **PHP legado 7.4+**.

## Funcionalidades

- **Autenticação OAuth2** - Authorization Code Flow completo
- **Gerenciamento de Tokens** - Access token, refresh token e expiração
- **Dados do Usuário** - Obtém informações do usuário autenticado
- **Logout Backchannel** - Suporte a logout global via webhook
- **Validação HMAC-SHA256** - Segurança para webhooks
- **Framework-Agnóstico** - Funciona com Laravel ou PHP legado
- **SSL Configurável** - Suporte a verificação SSL e CA bundles personalizados

## Requisitos

### Core (Obrigatório)
- PHP 7.4 ou superior
- Guzzle 6.0 ou 7.0

### Opcional
- **Laravel**: ^8.0, ^9.0, ^10.0, ^11.0, ^12.0 (para integração automática)

## Instalação

```bash
composer require sistemas-eel/sso-client
```

## Documentação Rápida

### Opção 1: Laravel (Integração Automática)

#### 1. Publicar Configuração

```bash
php artisan vendor:publish --tag=sso-client-config
```

#### 2. Configurar Variáveis de Ambiente

```env
SSO_SERVER_URL=https://portalsistemas.unidade.usp.br/portal-sistemas
SSO_CLIENT_ID=seu_client_id
SSO_CLIENT_SECRET=seu_client_secret
SSO_REDIRECT_URI=https://seu-sistema.com.br/sso/callback
SSO_WEBHOOK_SECRET=seu_webhook_secret
SSO_VERIFY_SSL=true
SSO_SYNC_PERMISSIONS=true
```

#### 3. Rotas Disponíveis

A biblioteca registra automaticamente:

| Rota | Nome | Descrição |
|------|------|-----------|
| `GET /login` | `login` | Rota padrão de login do Laravel apontando para o SSO |
| `GET /logout` | `logout` | Rota padrão de logout do Laravel apontando para o logout local da biblioteca |
| `GET /sso/callback` | `sso.callback` | Processa callback OAuth |
| `POST /api/sso/webhook-logout` | `sso.webhook-logout` | Webhook logout backchannel |

O middleware `auth` do Laravel passa a redirecionar automaticamente para essa rota `login`, e a aplicação pode usar a rota `logout` para encerrar a sessão local pelo fluxo da biblioteca.

Se a aplicação já tiver uma autenticação própria, é possível desabilitar esse comportamento:

```env
SSO_LOGIN_ROUTE_ENABLED=false
SSO_LOGOUT_ROUTE_ENABLED=false
```

Se outra biblioteca ou a própria aplicação já registrar a rota `login` ou `logout`, a rota desta biblioteca pode não prevalecer, pois a ordem de carregamento dos providers afeta qual definição fica ativa no fim. Nesses casos, se você quiser que o login e o logout usem o fluxo desta biblioteca, sobrescreva manualmente as rotas no `routes/web.php` da aplicação:

```php
<?php

use PortalSistemas\SSOClient\Laravel\Http\Controllers\SSOController;
use Illuminate\Support\Facades\Route;

Route::middleware('web')->group(function () {
    Route::get('/login', [SSOController::class, 'login'])->name('login');
    Route::get('/logout', [SSOController::class, 'logout'])->name('logout');
});
```

Esse ajuste é especialmente importante quando já existe outra biblioteca registrando `/login` ou `/logout`, como ocorre com integrações SSO antigas ou pacotes Socialite customizados.

#### 4. Adicionar Middleware (Opcional)

Para validar sessões e detectar logout global:

```php
// app/Http/Kernel.php
protected $middlewareGroups = [
    'web' => [
        // ...
        \PortalSistemas\SSOClient\Laravel\Http\Middleware\CheckSSOSession::class,
    ],
];
```

#### 5. Usar em Controllers

```php
use Illuminate\Support\Facades\Auth;

if (Auth::check()) {
    $user = Auth::user();
    echo "Bem-vindo, {$user->name}";
}
```

#### 6. Permissões por área via `userinfo.authorization`

No callback OAuth, a biblioteca já consome:

```json
{
  "authorization": {
    "permissions": ["user", "admin"]
  }
}
```

Com isso, ela:

- sincroniza permissões gerenciadas pelo SSO sem recalcular setor/vínculo/codpes no cliente;
- preserva permissões locais não gerenciadas pelo SSO;
- guarda em sessão `client_permissions` e `client_authorization`;
- remove permissões SSO que desaparecerem no próximo login/callback.

Para sincronizar essas permissões na tabela local `permissions`, instale e configure o pacote `spatie/laravel-permission` na aplicação cliente:

```bash
composer require spatie/laravel-permission
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
php artisan migrate
```

No model de usuário da aplicação cliente, use o trait `HasRoles`:

```php
use Illuminate\Foundation\Auth\User as Authenticatable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasRoles;
}
```

Quando o SSO retornar `authorization.permissions`, por exemplo `["admin"]`, a biblioteca criará a permission `admin` se necessário e chamará `givePermissionTo('admin')` no usuário autenticado. No próximo login/callback, permissões gerenciadas pelo SSO que não vierem mais no retorno serão removidas do usuário, preservando permissões locais que não foram gerenciadas pelo SSO.

A sincronização com o provider local de permissões fica habilitada por padrão e pode ser desativada:

```env
SSO_SYNC_PERMISSIONS=false
```

Mesmo com a sincronização local desativada, a biblioteca continua guardando `client_permissions` em sessão para uso com o middleware `client_permission`.

Para proteger rotas por permission do client, use o middleware `client_permission`:

```php
Route::middleware(['auth', 'client_permission:user'])->group(function () {
    Route::get('/dashboard', fn () => 'ok');
});

Route::middleware(['auth', 'client_permission:admin'])->prefix('admin')->group(function () {
    Route::get('/', fn () => 'admin');
});
```

Também há helper para consulta direta:

```php
use PortalSistemas\SSOClient\Laravel\Support\ClientPermission;

if (ClientPermission::has('admin')) {
    // ...
}
```

---

### Opção 2: PHP Legado (7.4+)

#### 1. Criar a configuração local da aplicação

```php
<?php
// sso/config.php

return [
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
```

#### 2. Criar um bootstrap local

```php
<?php
// sso/bootstrap.php

use PortalSistemas\SSOClient\Cache\FileCacheHandler;
use PortalSistemas\SSOClient\Core\SSOClient;
use PortalSistemas\SSOClient\Session\SessionHandler;

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

Esse bootstrap disponibiliza:

- `$config`
- `$client`
- `$session`
- `$cache`

#### 3. Criar Arquivo de Login

```php
<?php
// sso/login.php

require_once __DIR__ . '/bootstrap.php';

$state = bin2hex(random_bytes(20));
$session->set('oauth_state', $state);

header('Location: ' . $client->getAuthorizationUrl($state));
exit;
```

#### 4. Criar Callback

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
$token = $client->exchangeCodeForToken($_GET['code']);
if (!$token || !isset($token['access_token'])) {
    die('Falha ao obter token');
}

// Obtém usuário
$user = $client->getUserInfo($token['access_token']);
if (!$user || !isset($user['codpes'])) {
    die('Falha ao obter dados do usuário');
}

# INTEGRAÇÃO COM O BANCO DE DADOS
# $pdo->prepare("SELECT * FROM users WHERE codpes = ?");

// Armazena sessão
$session->set('user_authenticated', true);
$session->set('user_codpes', $user['codpes']);
$session->set('user_name', $user['name'] ?? '');
$session->set('user_email', $user['email'] ?? '');
$session->set('access_token', $token['access_token']);
$session->set('refresh_token', $token['refresh_token'] ?? null);
$session->set('expires_at', time() + ($token['expires_in'] ?? 3600));
$session->set('login_timestamp', time());

$session->regenerate();

header('Location: ' . $config['home_path']);
exit;
```

#### 5. Verificar Sessão em Páginas Protegidas

```php
<?php
// sso/session-check.php

require_once __DIR__ . '/bootstrap.php';

if (!$session->has('user_authenticated') || !$session->get('user_authenticated')) {
    header('Location: ' . $config['login_url']);
    exit;
}

// Exemplo simples de logout global
$globalLogout = $cache->get('sso_global_logout_' . $session->get('user_codpes'));
if ($globalLogout && $session->get('login_timestamp') && $globalLogout >= $session->get('login_timestamp')) {
    $session->clear();
    $session->destroy();
    header('Location: ' . $config['logout_redirect'] . '?reason=global_logout');
    exit;
}

$userCodpes = $session->get('user_codpes');
$userName = $session->get('user_name');
echo "Bem-vindo, {$userName}";
```

## Documentação Completa

Para guias detalhados de integração, consulte:

- [Guia de Integração Completo](docs/INTEGRATION_GUIDE.md)
- [Exemplos para PHP Legado](examples/legacy-php/)
- Exemplo real de endpoint Agente IA em cliente legado: `os/api/agente/chamados.php` + `os/api/agente/ChamadosAgenteHandler.php`

## Referência da API

### SSOClient

```php
use PortalSistemas\SSOClient\Core\SSOClient;

$client = new SSOClient(
    $baseUrl,           // URL do servidor SSO
    $clientId,          // Client ID
    $clientSecret,      // Client Secret
    $redirectUri,       // URI de redirecionamento
    $verifySsl = true,  // Verificação SSL (padrão: true)
    $caBundle = null    // Caminho para CA bundle (opcional)
);
```

#### Métodos Disponíveis

| Método | Descrição | Retorno |
|--------|-----------|---------|
| `getAuthorizationUrl(string $state)` | URL de autorização | `string` |
| `exchangeCodeForToken(string $code)` | Troca código por token | `?array` |
| `getUserInfo(string $accessToken)` | Dados do usuário | `?array` |
| `refreshToken(string $refreshToken)` | Renova token | `?array` |

#### Formato do webhook de logout (backchannel)

O endpoint `/api/sso/webhook-logout` recebe:

- Headers: `X-Webhook-Signature`, `X-Webhook-Timestamp`, `X-Webhook-Nonce`
- Body JSON (exato): `{"codpes":"...","reason":"...","timestamp":...,"nonce":"..."}`

Validação esperada no cliente:

1. validar presença dos headers e do corpo;
2. validar janela do timestamp (ex.: 5 minutos);
3. validar replay via nonce em cache;
4. validar assinatura:

```php
$expected = hash_hmac('sha256', $timestamp . $nonce . $rawBody, $webhookSecret);
hash_equals($expected, $signature);
```

### SessionHandler (PHP Legado)

```php
use PortalSistemas\SSOClient\Session\SessionHandler;

$session = new SessionHandler('sso_');

$session->set('key', 'value');      // Armazena
$value = $session->get('key');      // Obtém
$oldValue = $session->remove('key');// Remove e retorna
$exists = $session->has('key');     // Verifica
$session->clear();                   // Limpa variáveis SSO
$session->destroy();                 // Destrói sessão
$session->regenerate();              // Regenera ID
```

### FileCacheHandler (PHP Legado)

```php
use PortalSistemas\SSOClient\Cache\FileCacheHandler;

$cache = new FileCacheHandler('/path/to/cache/dir');

$cache->put('key', 'value', 3600);  // Armazena (1h TTL)
$value = $cache->get('key');        // Obtém
$cache->forget('key');              // Remove
```

## Segurança

### SSL Verification

A biblioteca **habilita verificação SSL por padrão** para segurança máxima.

**Para produção** (recomendado):
```env
SSO_VERIFY_SSL=true
```

**Para desenvolvimento** (apenas se necessário):
```env
SSO_VERIFY_SSL=false
```

**Com CA bundle personalizado**:
```env
SSO_VERIFY_SSL=true
SSO_CA_BUNDLE=/path/to/ca-bundle.crt
```

### Webhook Secret

Sempre configure um `SSO_WEBHOOK_SECRET` forte para validar webhooks de logout:

```env
SSO_WEBHOOK_SECRET=chave_super_secura_gerada_aleatoriamente
```

## Estrutura do Projeto

```
sso-client-php/
├── config/
│   └── sso-client.php                 # Configuração Laravel
├── docs/
│   └── INTEGRATION_GUIDE.md
├── examples/
│   └── legacy-php/
│       ├── bootstrap.php
│       ├── login.php
│       └── sso-session-check.php
├── routes/
│   └── sso.php
├── src/
│   ├── Cache/
│   │   ├── CacheHandlerInterface.php
│   │   └── FileCacheHandler.php       # Cache arquivo
│   ├── Core/
│   │   └── SSOClient.php              # Cliente OAuth2 core
│   ├── Laravel/
│   │   ├── Http/
│   │   │   ├── Controllers/
│   │   │   │   └── SSOController.php
│   │   │   └── Middleware/
│   │   │       └── CheckSSOSession.php
│   │   └── SSOClientServiceProvider.php
│   ├── Legacy/
│   │   └── bootstrap.php              # Bootstrap oficial para PHP legado
│   └── Session/
│       ├── SessionHandler.php         # Sessão PHP legado
│       └── SessionHandlerInterface.php
├── composer.json
└── README.md
```

## 🔄 Fluxo de Autenticação

```
┌─────────────┐              ┌──────────────────┐              ┌─────────────┐
│  Cliente    │              │   Seu Sistema    │              │   Servidor  │
│  (Browser)  │              │                  │              │   SSO Portal   │
└─────┬───────┘              └────────┬─────────┘              └──────┬──────┘
      │                               │                               │
      │  1. Acessa /sso/login         │                               │
      ├──────────────────────────────►│                               │
      │                               │                               │
      │                               │  2. Gera state parameter      │
      │                               │                               │
      │  3. Redirect to SSO           │                               │
      │◄──────────────────────────────┤                               │
      │                               │                               │
      │  4. Authorization URL         │                               │
      ├──────────────────────────────────────────────────────────────►│
      │                               │                               │
      │                               │  5. Usuário faz login         │
      │                               │                               │
      │  6. Redirect com code         │                               │
      │◄──────────────────────────────┤                               │
      │                               │                               │
      │  7. GET /sso/callback?code    │                               │
      ├──────────────────────────────►│                               │
      │                               │                               │
      │                               │   8. Valida state             │
      │                               │                               │
      │                               │  9. POST /oauth/token         │
      │                               ├──────────────────────────────►│
      │                               │                               │
      │                               │  10. Retorna token            │
      │                               │◄──────────────────────────────┤
      │                               │                               │
      │                               │  11. GET /api/user            │
      │                               ├──────────────────────────────►│
      │                               │                               │
      │                               │  12. Retorna user info        │
      │                               │◄──────────────────────────────┤
      │                               │                               │
      │                               │  13. Cria/atualiza usuário    │
      │                               │  14. Login na sessão          │
      │                               │                               │
      │  15. Redirect /dashboard      │                               │
      │◄──────────────────────────────┤                               │
      │                               │                               │
```

## Testes

```bash
composer install --dev
vendor/bin/phpunit
```

## Licença

Este projeto está licenciado sob a [Licença GPL](LICENSE).

## Contribuindo

1. Faça um fork do projeto
2. Crie sua branch de feature (`git checkout -b feature/NovaFeature`)
3. Commit suas mudanças (`git commit -m 'Add some NovaFeature'`)
4. Push para a branch (`git push origin feature/NovaFeature`)
5. Abra um Pull Request

## Suporte

- **Issues**: https://github.com/sistemas-eel/sso-client/issues
- **Documentação**: [docs/INTEGRATION_GUIDE.md](docs/INTEGRATION_GUIDE.md)

---

**Nota**: Esta biblioteca é mantida pela comunidade Sistemas EEL e não é um produto oficial da USP.
