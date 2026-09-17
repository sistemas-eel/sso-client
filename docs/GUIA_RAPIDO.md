# Guia rápido de integração do SSO Client com Laravel

Este guia apresenta o caminho mais curto para autenticar uma aplicação Laravel
no Portal de Sistemas usando `sistemas-eel/sso-client`.

Ele foi escrito para quem ainda não conhece OAuth. Para configurações
avançadas, PHP legado e referência completa, consulte o
[README](../README.md) e o
[guia de integração completo](INTEGRATION_GUIDE.md).

## O que a integração faz

Quando uma página protegida exige autenticação:

1. o Laravel envia o usuário para a rota `/login`;
2. o pacote redireciona o navegador ao Portal de Sistemas;
3. depois do login, o Portal retorna para `/sso/callback`;
4. o pacote valida o retorno, cria ou atualiza o usuário local e inicia a
   sessão do Laravel.

A senha institucional nunca é recebida nem armazenada pela aplicação cliente.

## 1. Antes de começar

Solicite o cadastro da aplicação no Portal de Sistemas. Você precisará destas
informações:

- URL do servidor SSO;
- identificador do cliente (`client_id`);
- segredo do cliente (`client_secret`);
- URL exata de callback da aplicação;
- segredo compartilhado para o webhook de logout.

A URL de callback normalmente é:

```text
https://seu-sistema.exemplo.br/sso/callback
```

Se a aplicação estiver em um subdiretório, ele faz parte da URL:

```text
https://seu-sistema.exemplo.br/minha-aplicacao/sso/callback
```

A URL cadastrada no Portal e `SSO_REDIRECT_URI` devem ser idênticas, incluindo
HTTPS, domínio, porta e subdiretório.

Antes de prosseguir, confirme também que:

- a aplicação usa HTTPS em produção;
- existe uma tabela `users`;
- sessão e cache do Laravel estão funcionando;
- o driver de cache oferece locks atômicos, como `database` ou `redis`.

Em ambientes com mais de uma instância da aplicação, todas devem usar o mesmo
cache compartilhado. Limpar o cache durante uma autenticação invalida apenas o
fluxo pendente; o usuário precisará iniciar o login novamente.

## 2. Instale o pacote

Na raiz da aplicação Laravel, execute:

```bash
composer require sistemas-eel/sso-client
php artisan vendor:publish --tag=sso-client-config
```

O Laravel descobre o provider do pacote automaticamente. Não é necessário
registrá-lo manualmente.

O pacote inclui uma migration que adiciona `codpes` à tabela `users`, caso a
coluna ainda não exista, e permite que `password` seja nulo. Revise as
migrations pendentes e execute:

```bash
php artisan migrate
```

Em produção, faça backup do banco e siga o procedimento normal de implantação
da sua instituição antes de executar migrations.

## 3. Configure o `.env`

Adicione e ajuste:

```env
SSO_SERVER_URL=https://portal.exemplo.br/portal-sistemas
SSO_CLIENT_ID=identificador-fornecido-pelo-portal
SSO_CLIENT_SECRET=segredo-fornecido-pelo-portal
SSO_REDIRECT_URI=https://seu-sistema.exemplo.br/sso/callback
SSO_WEBHOOK_SECRET=segredo-compartilhado-do-webhook

SSO_VERIFY_SSL=true
SESSION_SECURE_COOKIE=true
SSO_SYNC_PERMISSIONS=false

SSO_OAUTH_STATE_TTL=600
SSO_OAUTH_STATE_MAX_PENDING=10
SSO_OAUTH_ROUTE_LOCK_SECONDS=30
SSO_OAUTH_ROUTE_LOCK_WAIT_SECONDS=30
```

Cada tentativa de login cria um registro temporário no cache e um cookie
HTTP-only exclusivo. Esse vínculo permite concluir o callback mesmo que outra
aba já tenha regenerado a sessão do Laravel. O destino original também fica
associado ao fluxo, para que cada aba retorne à sua própria página.

O estado é descartado após o primeiro uso ou ao atingir o TTL. O cookie sozinho
não é suficiente: o registro correspondente também precisa existir no cache.

Use `SESSION_SECURE_COOKIE=true` somente quando a aplicação estiver publicada
em HTTPS. Em desenvolvimento HTTP local, mantenha essa opção ausente ou como
`false`.

Nunca envie o `.env`, `SSO_CLIENT_SECRET` ou `SSO_WEBHOOK_SECRET` para o Git.
Não use `SSO_VERIFY_SSL=false` em produção.

Depois de alterar o `.env`, limpe os caches:

```bash
php artisan optimize:clear
```

## 4. Confira o usuário local

Por padrão, o pacote usa `App\Models\User` e espera estes atributos na tabela
`users`:

- `id`;
- `codpes`;
- `name`;
- `email`;
- `email_verified_at`;
- `password` aceitando `null`.

No callback, o pacote procura primeiro pelo `codpes` recebido do Portal e,
quando necessário, tenta localizar o usuário pelo e-mail. Se não encontrar,
cria um usuário local.

Para usar outro model, configure, por exemplo:

```env
SSO_USER_MODEL=App\Models\Usuario
```

## 5. Registre o middleware de sessão

O middleware `CheckSSOSession` permite que a aplicação reconheça um logout
global ou uma sessão revogada no Portal.

### Laravel 11 ou superior

No `bootstrap/app.php`, adicione o middleware ao grupo `web` dentro da
configuração já existente:

```php
use Illuminate\Foundation\Configuration\Middleware;
use SistemasEel\SSOClient\Laravel\Http\Middleware\CheckSSOSession;

->withMiddleware(function (Middleware $middleware): void {
    $middleware->appendToGroup('web', [
        CheckSSOSession::class,
    ]);
})
```

### Laravel 8, 9 ou 10

No `app/Http/Kernel.php`, acrescente ao grupo `web`:

```php
protected $middlewareGroups = [
    'web' => [
        // Outros middlewares...
        \SistemasEel\SSOClient\Laravel\Http\Middleware\CheckSSOSession::class,
    ],
];
```

Depois, execute novamente:

```bash
php artisan optimize:clear
```

## 6. Confira as rotas automáticas

O pacote registra estas rotas:

| Método | Caminho | Nome | Finalidade |
| --- | --- | --- | --- |
| GET | `/login` | `login` | Inicia a autenticação |
| GET | `/logout` | `logout` | Encerra a sessão local |
| GET | `/sso/callback` | `sso.callback` | Recebe o retorno OAuth |
| POST | `/api/sso/webhook-logout` | `sso.webhook-logout` | Recebe logout global |

Confira com:

```bash
php artisan route:list --name=login
php artisan route:list --name=logout
php artisan route:list --name=sso.callback
php artisan route:list --name=sso.webhook-logout
```

As rotas automáticas de login e callback já bloqueiam requisições concorrentes
da mesma sessão. Isso evita perda de estados OAuth quando várias abas são
restauradas ao mesmo tempo.

## 7. Proteja as páginas da aplicação

Use o middleware normal `auth` do Laravel:

```php
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function (): void {
    Route::view('/home', 'home')->name('home');
});
```

Um visitante não autenticado será enviado para a rota nomeada `login`, que
iniciará o fluxo no Portal.

Na interface, um link simples pode encerrar a sessão:

```blade
<a href="{{ route('logout') }}">Sair</a>
```

## 8. Quando já existe outra rota de login

Algumas aplicações possuem outro pacote de autenticação que também registra
`login` ou `logout`. Primeiro, confirme o conflito com `route:list`.

Se a aplicação precisar controlar essas duas rotas, desabilite apenas o
registro automático delas:

```env
SSO_LOGIN_ROUTE_ENABLED=false
SSO_LOGOUT_ROUTE_ENABLED=false
```

Em `routes/web.php`, declare:

```php
use Illuminate\Support\Facades\Route;
use SistemasEel\SSOClient\Laravel\Http\Controllers\SSOController;

Route::get('/login', [SSOController::class, 'login'])
    ->block(30, 30)
    ->name('login');

Route::get('/logout', [SSOController::class, 'logout'])
    ->name('logout');
```

Não redeclare `/sso/callback` sem necessidade. O pacote continua registrando o
callback automaticamente, já com bloqueio de sessão. Se precisar sobrescrevê-lo,
preserve também `->block(30, 30)`.

## 9. Configure o logout global

No Portal de Sistemas, cadastre como destino do webhook:

```text
https://seu-sistema.exemplo.br/api/sso/webhook-logout
```

O segredo cadastrado no Portal deve ser o mesmo de `SSO_WEBHOOK_SECRET`.

O endpoint valida assinatura, horário e nonce. Não remova essas verificações e
não exponha o endpoint sem HTTPS.

## 10. Faça o primeiro teste

1. abra uma janela anônima do navegador;
2. acesse uma rota protegida por `auth`;
3. confirme o redirecionamento para o Portal;
4. autentique-se;
5. confirme o retorno à aplicação;
6. confira se o usuário foi criado ou atualizado em `users`;
7. teste `/logout`;
8. repita o acesso com duas abas para verificar fluxos sobrepostos.

Em seguida, verifique os logs da aplicação:

```bash
tail -n 100 storage/logs/laravel.log
```

## Checklist de conclusão

- [ ] O cliente foi cadastrado no Portal.
- [ ] A callback cadastrada é exatamente igual a `SSO_REDIRECT_URI`.
- [ ] O pacote foi instalado e a configuração foi publicada.
- [ ] As migrations foram revisadas e executadas.
- [ ] As credenciais estão somente no `.env`.
- [ ] `CheckSSOSession` foi adicionado ao grupo `web`.
- [ ] As quatro rotas do pacote aparecem em `route:list`.
- [ ] Rotas manuais de login/callback, se existirem, usam `block(30, 30)`.
- [ ] Uma rota protegida redireciona, autentica e retorna corretamente.
- [ ] O usuário local possui `codpes`, nome e e-mail esperados.
- [ ] O logout local funciona.
- [ ] O webhook de logout usa HTTPS e o segredo correto.
- [ ] Produção HTTPS usa `SESSION_SECURE_COOKIE=true`.
- [ ] Todas as instâncias usam o mesmo cache compartilhado.

## Problemas mais comuns

### `403 Estado OAuth inválido`

Não desative a validação de `state`. Confira:

- se a sessão foi preservada entre `/login` e `/sso/callback`;
- se o domínio, caminho, HTTPS e cookie de sessão estão corretos;
- se o callback ocorreu dentro de `SSO_OAUTH_STATE_TTL`;
- se rotas manuais de login/callback usam `block(30, 30)`;
- se sessão e cache não foram apagados durante o login;
- se uma URL antiga de callback foi recarregada;
- se o cache foi limpo entre o login e o callback;
- se todas as instâncias usam o mesmo armazenamento de cache;
- se o cookie `sso_oauth_state_*` foi enviado no callback;
- se produção HTTPS usa `SESSION_SECURE_COOKIE=true`.

Inicie um novo acesso pela rota protegida ou por `/login`; não reutilize uma
URL antiga de `/sso/callback`.

### O sistema usa a tela de login errada

Execute `php artisan route:list --name=login`. Outro pacote pode ter
sobrescrito a rota. Use a configuração e as rotas manuais da seção 8.

### O Portal informa callback inválida

Compare, caractere por caractere, a URL cadastrada no Portal com
`SSO_REDIRECT_URI`. Confira especialmente HTTPS e subdiretórios.

### Erro de coluna `codpes` ou `password`

Confira as migrations pendentes:

```bash
php artisan migrate:status
```

Revise a tabela `users` e execute a migration do pacote conforme o processo de
implantação da aplicação.

### Alterei o `.env`, mas nada mudou

Execute:

```bash
php artisan optimize:clear
```

Depois reinicie os processos persistentes da aplicação, se houver workers ou
servidores de longa duração.

## Permissões são opcionais

O login básico não exige `spatie/laravel-permission`. Quando a aplicação
estiver pronta para consumir as permissões enviadas pelo Portal, consulte a
seção de permissões do `README.md` e altere:

```env
SSO_SYNC_PERMISSIONS=true
```

Até lá, mantenha `SSO_SYNC_PERMISSIONS=false`.
