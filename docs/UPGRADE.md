# Guia de atualização

Este guia reúne as ações necessárias ao atualizar o `sso-client`.

Leia todas as seções entre a versão atualmente instalada e a versão de
destino. Por exemplo, ao atualizar da `beta.2` para a `beta.6`, aplique também
as orientações das versões `beta.3`, `beta.4` e `beta.5`.

Após qualquer alteração no `.env` ou nos arquivos de configuração, execute:

```bash
php artisan optimize:clear
```

## De `1.0.0-beta.5` para `1.0.0-beta.6`

### Laravel 11 ou superior

Configure o redirecionamento de visitantes para transportar o destino daquela
requisição até a rota de login.

Em `bootstrap/app.php`, dentro de `withMiddleware()`, adicione:

```php
$middleware->redirectGuestsTo(
    fn (Request $request) => route('login', [
        'intended' => $request->fullUrl(),
    ]),
);
```

O arquivo também precisa importar:

```php
use Illuminate\Http\Request;
```

Isso impede que abas simultâneas disputem o único `url.intended` armazenado na
sessão. O pacote valida o destino e aceita apenas caminhos relativos ou URLs
absolutas da mesma origem da aplicação.

Se a aplicação sobrescrever a rota de login, mantenha o nome `login` ou ajuste
o exemplo para o nome utilizado pelo projeto.

### Laravel 8, 9 ou 10

No método `unauthenticated()` de `app/Exceptions/Handler.php`, redirecione para
a rota de login incluindo o destino:

```php
protected function unauthenticated(
    $request,
    AuthenticationException $exception
) {
    if ($request->expectsJson()) {
        return response()->json([
            'message' => $exception->getMessage(),
        ], 401);
    }

    return redirect()->guest(route('login', [
        'intended' => $request->fullUrl(),
    ]));
}
```

Importe também:

```php
use Illuminate\Auth\AuthenticationException;
```

## De `1.0.0-beta.4` para `1.0.0-beta.5`

Nenhuma alteração na aplicação é necessária.

Essa versão apenas restaura a compatibilidade de sintaxe de `routes/sso.php`
com PHP 7.4.

## De `1.0.0-beta.3` para `1.0.0-beta.4`

Em aplicações publicadas por HTTPS, adicione ao `.env`:

```env
SESSION_SECURE_COOKIE=true
```

O fluxo OAuth passa a usar o cache do Laravel para sobreviver à regeneração da
sessão. Verifique se:

- `CACHE_STORE` usa um driver com locks atômicos, como `database` ou `redis`;
- todas as instâncias da aplicação usam o mesmo cache compartilhado;
- o cache não é limpo enquanto houver autenticações em andamento;
- os cookies da aplicação usam domínio, caminho e `SameSite` compatíveis com o
  callback.

Em desenvolvimento HTTP local, mantenha `SESSION_SECURE_COOKIE` ausente ou
como `false`.

## De `1.0.0-beta.2` para `1.0.0-beta.3`

As rotas automáticas de login e callback já recebem bloqueio de sessão.

Se a aplicação declara essas rotas manualmente, aplique o bloqueio:

```php
Route::get('/login', [SSOController::class, 'login'])
    ->block(30, 30)
    ->name('login');

Route::get('/sso/callback', [SSOController::class, 'callback'])
    ->block(30, 30)
    ->name('sso.callback');
```

Adicione ao `.env`:

```env
SSO_OAUTH_ROUTE_LOCK_SECONDS=30
SSO_OAUTH_ROUTE_LOCK_WAIT_SECONDS=30
```

O driver de cache precisa oferecer locks atômicos.

## De `1.0.0-beta.1` para `1.0.0-beta.2`

Adicione ao `.env`:

```env
SSO_OAUTH_STATE_TTL=600
SSO_OAUTH_STATE_MAX_PENDING=10
```

Essas opções permitem manter vários estados OAuth pendentes na mesma sessão.

## Configuração publicada anteriormente

Quando `config/sso-client.php` já existir na aplicação, o Composer não o
atualizará automaticamente.

Compare o arquivo publicado com:

```text
vendor/sistemas-eel/sso-client/config/sso-client.php
```

Copie manualmente as novas opções necessárias. Evite publicar a configuração
com `--force` sem revisar o diff, pois isso pode sobrescrever personalizações,
URLs e nomes de rotas do projeto.

## Verificação após a atualização

Execute:

```bash
php artisan optimize:clear
php artisan route:list | grep -E 'login|sso/callback'
```

Depois valide:

1. acesso comum a uma página protegida;
2. retorno à página originalmente solicitada;
3. logout e novo login;
4. múltiplas abas restauradas simultaneamente;
5. ausência de `403 Estado OAuth inválido`;
6. ausência de redirecionamentos inesperados para a raiz.
