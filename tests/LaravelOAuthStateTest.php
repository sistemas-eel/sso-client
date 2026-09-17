<?php

namespace SistemasEel\SSOClient\Tests;

use Illuminate\Support\Carbon;
use Mockery;
use SistemasEel\SSOClient\Core\SSOClient;
use SistemasEel\SSOClient\Tests\Fakes\FakeUser;

class LaravelOAuthStateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        FakeUser::resetStore();
        config()->set('sso-client.user_model', FakeUser::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();

        parent::tearDown();
    }

    public function test_aceita_dois_fluxos_de_login_pendentes_na_mesma_sessao(): void
    {
        $mock = Mockery::mock(SSOClient::class);
        $mock->shouldReceive('getAuthorizationUrl')
            ->twice()
            ->andReturnUsing(
                fn (string $state): string => 'https://sso.example.test/oauth/authorize?state='.urlencode($state),
            );
        $mock->shouldReceive('exchangeCodeForToken')
            ->twice()
            ->andReturn([
                'access_token' => 'access-token',
                'refresh_token' => 'refresh-token',
                'expires_in' => 3600,
            ]);
        $mock->shouldReceive('getUserInfo')
            ->twice()
            ->andReturn([
                'codpes' => '123456',
                'name' => 'Usuário de teste',
                'email' => 'usuario@example.test',
            ]);

        $this->app->instance(SSOClient::class, $mock);

        $primeiroState = $this->stateDoRedirecionamento(
            $this->get('/login')->headers->get('Location'),
        );

        $segundoState = $this->stateDoRedirecionamento(
            $this->get('/login')->headers->get('Location'),
        );

        $this->assertNotSame($primeiroState, $segundoState);

        $this->get('/sso/callback?'.http_build_query([
            'state' => $primeiroState,
            'code' => 'primeiro-codigo',
        ]))->assertRedirect('/');

        $this->get('/sso/callback?'.http_build_query([
            'state' => $segundoState,
            'code' => 'segundo-codigo',
        ]))->assertRedirect('/');
    }

    public function test_callback_invalido_nao_consome_state_valido_pendente(): void
    {
        $mock = Mockery::mock(SSOClient::class);
        $mock->shouldReceive('getAuthorizationUrl')
            ->once()
            ->andReturnUsing(
                fn (string $state): string => 'https://sso.example.test/oauth/authorize?state='.urlencode($state),
            );
        $mock->shouldReceive('exchangeCodeForToken')
            ->once()
            ->andReturn([
                'access_token' => 'access-token',
                'refresh_token' => 'refresh-token',
                'expires_in' => 3600,
            ]);
        $mock->shouldReceive('getUserInfo')
            ->once()
            ->andReturn([
                'codpes' => '123456',
                'name' => 'Usuário de teste',
                'email' => 'usuario@example.test',
            ]);

        $this->app->instance(SSOClient::class, $mock);

        $stateValido = $this->stateDoRedirecionamento(
            $this->get('/login')->headers->get('Location'),
        );

        $this->get('/sso/callback?'.http_build_query([
            'state' => 'state-incorreto',
            'code' => 'codigo-incorreto',
        ]))->assertForbidden();

        $this->get('/sso/callback?'.http_build_query([
            'state' => $stateValido,
            'code' => 'codigo-valido',
        ]))->assertRedirect('/');
    }

    public function test_rejeita_reutilizacao_de_state_ja_consumido(): void
    {
        $mock = Mockery::mock(SSOClient::class);
        $mock->shouldReceive('getAuthorizationUrl')
            ->once()
            ->andReturnUsing(
                fn (string $state): string => 'https://sso.example.test/oauth/authorize?state='.urlencode($state),
            );
        $mock->shouldReceive('exchangeCodeForToken')
            ->once()
            ->andReturn([
                'access_token' => 'access-token',
                'refresh_token' => 'refresh-token',
                'expires_in' => 3600,
            ]);
        $mock->shouldReceive('getUserInfo')
            ->once()
            ->andReturn([
                'codpes' => '123456',
                'name' => 'Usuário de teste',
                'email' => 'usuario@example.test',
            ]);

        $this->app->instance(SSOClient::class, $mock);

        $state = $this->stateDoRedirecionamento(
            $this->get('/login')->headers->get('Location'),
        );

        $parametros = http_build_query([
            'state' => $state,
            'code' => 'codigo-valido',
        ]);

        $this->get('/sso/callback?'.$parametros)
            ->assertRedirect('/');

        $this->get('/sso/callback?'.$parametros)
            ->assertForbidden();
    }

    public function test_rejeita_state_expirado(): void
    {
        Carbon::setTestNow('2026-09-15 10:00:00');
        config()->set('sso-client.oauth_state.ttl_seconds', 600);

        $mock = Mockery::mock(SSOClient::class);
        $mock->shouldReceive('getAuthorizationUrl')
            ->once()
            ->andReturnUsing(
                fn (string $state): string => 'https://sso.example.test/oauth/authorize?state='.urlencode($state),
            );

        $this->app->instance(SSOClient::class, $mock);

        $state = $this->stateDoRedirecionamento(
            $this->get('/login')->headers->get('Location'),
        );

        Carbon::setTestNow('2026-09-15 10:10:01');

        $this->get('/sso/callback?'.http_build_query([
            'state' => $state,
            'code' => 'codigo-tardio',
        ]))->assertForbidden();
    }

    public function test_descarta_o_state_mais_antigo_ao_atingir_o_limite(): void
    {
        config()->set('sso-client.oauth_state.max_pending', 2);

        $mock = Mockery::mock(SSOClient::class);
        $mock->shouldReceive('getAuthorizationUrl')
            ->times(3)
            ->andReturnUsing(
                fn (string $state): string => 'https://sso.example.test/oauth/authorize?state='.urlencode($state),
            );
        $mock->shouldReceive('exchangeCodeForToken')
            ->twice()
            ->andReturn([
                'access_token' => 'access-token',
                'refresh_token' => 'refresh-token',
                'expires_in' => 3600,
            ]);
        $mock->shouldReceive('getUserInfo')
            ->twice()
            ->andReturn([
                'codpes' => '123456',
                'name' => 'Usuário de teste',
                'email' => 'usuario@example.test',
            ]);

        $this->app->instance(SSOClient::class, $mock);

        $primeiroState = $this->stateDoRedirecionamento(
            $this->get('/login')->headers->get('Location'),
        );
        $segundoState = $this->stateDoRedirecionamento(
            $this->get('/login')->headers->get('Location'),
        );
        $terceiroState = $this->stateDoRedirecionamento(
            $this->get('/login')->headers->get('Location'),
        );

        $this->get('/sso/callback?'.http_build_query([
            'state' => $primeiroState,
            'code' => 'primeiro-codigo',
        ]))->assertForbidden();

        $this->get('/sso/callback?'.http_build_query([
            'state' => $segundoState,
            'code' => 'segundo-codigo',
        ]))->assertRedirect('/');

        $this->get('/sso/callback?'.http_build_query([
            'state' => $terceiroState,
            'code' => 'terceiro-codigo',
        ]))->assertRedirect('/');
    }

    public function test_state_malformado_e_rejeitado_sem_consumir_o_valido(): void
    {
        $mock = Mockery::mock(SSOClient::class);
        $mock->shouldReceive('getAuthorizationUrl')
            ->once()
            ->andReturnUsing(
                fn (string $state): string => 'https://sso.example.test/oauth/authorize?state='.urlencode($state),
            );
        $mock->shouldReceive('exchangeCodeForToken')
            ->once()
            ->andReturn([
                'access_token' => 'access-token',
                'refresh_token' => 'refresh-token',
                'expires_in' => 3600,
            ]);
        $mock->shouldReceive('getUserInfo')
            ->once()
            ->andReturn([
                'codpes' => '123456',
                'name' => 'Usuário de teste',
                'email' => 'usuario@example.test',
            ]);

        $this->app->instance(SSOClient::class, $mock);

        $stateValido = $this->stateDoRedirecionamento(
            $this->get('/login')->headers->get('Location'),
        );

        $this->get('/sso/callback?state[]=malformado&code=codigo-incorreto')
            ->assertForbidden();

        $this->get('/sso/callback?'.http_build_query([
            'state' => $stateValido,
            'code' => 'codigo-valido',
        ]))->assertRedirect('/');
    }

    public function test_callback_preserva_estado_e_destino_apos_troca_da_sessao(): void
    {
        $mock = Mockery::mock(SSOClient::class);

        $mock->shouldReceive('getAuthorizationUrl')
            ->once()
            ->andReturnUsing(
                fn (string $state): string =>
                    'https://sso.example.test/oauth/authorize?state='
                    .urlencode($state),
            );

        $mock->shouldReceive('exchangeCodeForToken')
            ->once()
            ->andReturn([
                'access_token' => 'access-token',
                'refresh_token' => 'refresh-token',
                'expires_in' => 3600,
            ]);

        $mock->shouldReceive('getUserInfo')
            ->once()
            ->andReturn([
                'codpes' => '123456',
                'name' => 'Usuário de teste',
                'email' => 'usuario@example.test',
            ]);

        $this->app->instance(SSOClient::class, $mock);

        $this->withSession([
            'url.intended' => '/administracao/tipos-chamados/1/versoes/1',
        ]);

        $respostaLogin = $this->get('/login');

        $state = $this->stateDoRedirecionamento(
            $respostaLogin->headers->get('Location'),
        );

        $nomeCookieFluxo = 'sso_oauth_state_'.hash('sha256', $state);
        $cookieFluxo = $respostaLogin->getCookie($nomeCookieFluxo);

        $this->assertNotNull($cookieFluxo);

        session()->invalidate();
        session()->save();

        $this->withCookie(
            $nomeCookieFluxo,
            $cookieFluxo->getValue(),
        )->get('/sso/callback?'.http_build_query([
            'state' => $state,
            'code' => 'codigo-valido',
        ]))->assertRedirect(
            '/administracao/tipos-chamados/1/versoes/1',
        );
    }

    public function test_rejeita_estado_cacheado_sem_cookie_de_vinculo(): void
    {
        $mock = Mockery::mock(SSOClient::class);

        $mock->shouldReceive('getAuthorizationUrl')
            ->once()
            ->andReturnUsing(
                fn (string $state): string =>
                    'https://sso.example.test/oauth/authorize?state='
                    .urlencode($state),
            );

        $this->app->instance(SSOClient::class, $mock);

        $respostaLogin = $this->get('/login');

        $state = $this->stateDoRedirecionamento(
            $respostaLogin->headers->get('Location'),
        );

        session()->invalidate();
        session()->save();

        $this->get('/sso/callback?'.http_build_query([
            'state' => $state,
            'code' => 'codigo-invalido',
        ]))->assertForbidden();
    }

    public function test_cookie_incorreto_nao_consome_estado_cacheado_valido(): void
    {
        $mock = Mockery::mock(SSOClient::class);

        $mock->shouldReceive('getAuthorizationUrl')
            ->once()
            ->andReturnUsing(
                fn (string $state): string =>
                    'https://sso.example.test/oauth/authorize?state='
                    .urlencode($state),
            );

        $mock->shouldReceive('exchangeCodeForToken')
            ->once()
            ->andReturn([
                'access_token' => 'access-token',
                'refresh_token' => 'refresh-token',
                'expires_in' => 3600,
            ]);

        $mock->shouldReceive('getUserInfo')
            ->once()
            ->andReturn([
                'codpes' => '123456',
                'name' => 'Usuário de teste',
                'email' => 'usuario@example.test',
            ]);

        $this->app->instance(SSOClient::class, $mock);

        $respostaLogin = $this->get('/login');

        $state = $this->stateDoRedirecionamento(
            $respostaLogin->headers->get('Location'),
        );

        $nomeCookie = 'sso_oauth_state_'.hash('sha256', $state);
        $cookieCorreto = $respostaLogin->getCookie($nomeCookie);

        $this->assertNotNull($cookieCorreto);

        session()->invalidate();
        session()->save();

        $this->withCookie(
            $nomeCookie,
            'vinculo-incorreto',
        )->get('/sso/callback?'.http_build_query([
            'state' => $state,
            'code' => 'codigo-incorreto',
        ]))->assertForbidden();

        $this->withCookie(
            $nomeCookie,
            $cookieCorreto->getValue(),
        )->get('/sso/callback?'.http_build_query([
            'state' => $state,
            'code' => 'codigo-valido',
        ]))->assertRedirect('/');
    }

    public function test_estado_cacheado_nao_pode_ser_reutilizado(): void
    {
        $mock = Mockery::mock(SSOClient::class);

        $mock->shouldReceive('getAuthorizationUrl')
            ->once()
            ->andReturnUsing(
                fn (string $state): string =>
                    'https://sso.example.test/oauth/authorize?state='
                    .urlencode($state),
            );

        $mock->shouldReceive('exchangeCodeForToken')
            ->once()
            ->andReturn([
                'access_token' => 'access-token',
                'refresh_token' => 'refresh-token',
                'expires_in' => 3600,
            ]);

        $mock->shouldReceive('getUserInfo')
            ->once()
            ->andReturn([
                'codpes' => '123456',
                'name' => 'Usuário de teste',
                'email' => 'usuario@example.test',
            ]);

        $this->app->instance(SSOClient::class, $mock);

        $respostaLogin = $this->get('/login');

        $state = $this->stateDoRedirecionamento(
            $respostaLogin->headers->get('Location'),
        );

        $nomeCookie = 'sso_oauth_state_'.hash('sha256', $state);
        $cookie = $respostaLogin->getCookie($nomeCookie);

        $this->assertNotNull($cookie);

        session()->invalidate();
        session()->save();

        $parametros = http_build_query([
            'state' => $state,
            'code' => 'codigo-valido',
        ]);

        $this->withCookie(
            $nomeCookie,
            $cookie->getValue(),
        )->get('/sso/callback?'.$parametros)
            ->assertRedirect('/');

        $this->withCookie(
            $nomeCookie,
            $cookie->getValue(),
        )->get('/sso/callback?'.$parametros)
            ->assertForbidden();
    }

    public function test_fluxos_sobrepostos_preservam_seus_proprios_destinos(): void
    {
        $mock = Mockery::mock(SSOClient::class);

        $mock->shouldReceive('getAuthorizationUrl')
            ->twice()
            ->andReturnUsing(
                fn (string $state): string =>
                    'https://sso.example.test/oauth/authorize?state='
                    .urlencode($state),
            );

        $mock->shouldReceive('exchangeCodeForToken')
            ->twice()
            ->andReturn([
                'access_token' => 'access-token',
                'refresh_token' => 'refresh-token',
                'expires_in' => 3600,
            ]);

        $mock->shouldReceive('getUserInfo')
            ->twice()
            ->andReturn([
                'codpes' => '123456',
                'name' => 'Usuário de teste',
                'email' => 'usuario@example.test',
            ]);

        $this->app->instance(SSOClient::class, $mock);

        $this->withSession([
            'url.intended' => '/primeiro-destino',
        ]);

        $primeiroLogin = $this->get('/login');

        $primeiroState = $this->stateDoRedirecionamento(
            $primeiroLogin->headers->get('Location'),
        );

        $primeiroCookieNome = 'sso_oauth_state_'
            .hash('sha256', $primeiroState);
        $primeiroCookie = $primeiroLogin->getCookie(
            $primeiroCookieNome,
        );

        session()->put('url.intended', '/segundo-destino');

        $segundoLogin = $this->get('/login');

        $segundoState = $this->stateDoRedirecionamento(
            $segundoLogin->headers->get('Location'),
        );

        $segundoCookieNome = 'sso_oauth_state_'
            .hash('sha256', $segundoState);
        $segundoCookie = $segundoLogin->getCookie(
            $segundoCookieNome,
        );

        $this->assertNotNull($primeiroCookie);
        $this->assertNotNull($segundoCookie);

        session()->invalidate();
        session()->save();

        $this->withCookie(
            $primeiroCookieNome,
            $primeiroCookie->getValue(),
        )->get('/sso/callback?'.http_build_query([
            'state' => $primeiroState,
            'code' => 'primeiro-codigo',
        ]))->assertRedirect('/primeiro-destino');

        session()->invalidate();
        session()->save();

        $this->withCookie(
            $segundoCookieNome,
            $segundoCookie->getValue(),
        )->get('/sso/callback?'.http_build_query([
            'state' => $segundoState,
            'code' => 'segundo-codigo',
        ]))->assertRedirect('/segundo-destino');
    }

    public function test_estado_cacheado_expira_mesmo_com_cookie_presente(): void
    {
        Carbon::setTestNow('2026-09-17 10:00:00');

        config()->set('sso-client.oauth_state.ttl_seconds', 600);

        $mock = Mockery::mock(SSOClient::class);

        $mock->shouldReceive('getAuthorizationUrl')
            ->once()
            ->andReturnUsing(
                fn (string $state): string =>
                    'https://sso.example.test/oauth/authorize?state='
                    .urlencode($state),
            );

        $this->app->instance(SSOClient::class, $mock);

        $respostaLogin = $this->get('/login');

        $state = $this->stateDoRedirecionamento(
            $respostaLogin->headers->get('Location'),
        );

        $nomeCookie = 'sso_oauth_state_'.hash('sha256', $state);
        $cookie = $respostaLogin->getCookie($nomeCookie);

        $this->assertNotNull($cookie);

        session()->invalidate();
        session()->save();

        Carbon::setTestNow('2026-09-17 10:10:01');

        $this->withCookie(
            $nomeCookie,
            $cookie->getValue(),
        )->get('/sso/callback?'.http_build_query([
            'state' => $state,
            'code' => 'codigo-expirado',
        ]))->assertForbidden();
    }

    public function test_destino_externo_do_fluxo_oauth_e_descartado(): void
    {
        $mock = Mockery::mock(SSOClient::class);

        $mock->shouldReceive('getAuthorizationUrl')
            ->once()
            ->andReturnUsing(
                fn (string $state): string =>
                    'https://sso.example.test/oauth/authorize?state='
                    .urlencode($state),
            );

        $mock->shouldReceive('exchangeCodeForToken')
            ->once()
            ->andReturn([
                'access_token' => 'access-token',
                'refresh_token' => 'refresh-token',
                'expires_in' => 3600,
            ]);

        $mock->shouldReceive('getUserInfo')
            ->once()
            ->andReturn([
                'codpes' => '123456',
                'name' => 'Usuário de teste',
                'email' => 'usuario@example.test',
            ]);

        $this->app->instance(SSOClient::class, $mock);

        $this->withSession([
            'url.intended' => 'https://dominio-externo.example/phishing',
        ]);

        $respostaLogin = $this->get('/login');

        $state = $this->stateDoRedirecionamento(
            $respostaLogin->headers->get('Location'),
        );

        $nomeCookie = 'sso_oauth_state_'.hash('sha256', $state);
        $cookie = $respostaLogin->getCookie($nomeCookie);

        $this->assertNotNull($cookie);

        session()->invalidate();
        session()->save();

        $this->withCookie(
            $nomeCookie,
            $cookie->getValue(),
        )->get('/sso/callback?'.http_build_query([
            'state' => $state,
            'code' => 'codigo-valido',
        ]))->assertRedirect('/');
    }

    public function test_preserva_destino_absoluto_da_mesma_aplicacao(): void
    {
        config()->set(
            'app.url',
            'https://app.example.test/chamados',
        );

        $destino = 'https://app.example.test/chamados/'
            .'administracao/tipos-chamados/1/versoes/1?aba=fluxo';

        $mock = Mockery::mock(SSOClient::class);

        $mock->shouldReceive('getAuthorizationUrl')
            ->once()
            ->andReturnUsing(
                fn (string $state): string =>
                    'https://sso.example.test/oauth/authorize?state='
                    .urlencode($state),
            );

        $mock->shouldReceive('exchangeCodeForToken')
            ->once()
            ->andReturn([
                'access_token' => 'access-token',
                'refresh_token' => 'refresh-token',
                'expires_in' => 3600,
            ]);

        $mock->shouldReceive('getUserInfo')
            ->once()
            ->andReturn([
                'codpes' => '123456',
                'name' => 'Usuário de teste',
                'email' => 'usuario@example.test',
            ]);

        $this->app->instance(SSOClient::class, $mock);

        $this->withSession([
            'url.intended' => $destino,
        ]);

        $respostaLogin = $this->get('/login');

        $state = $this->stateDoRedirecionamento(
            $respostaLogin->headers->get('Location'),
        );

        $nomeCookie = 'sso_oauth_state_'.hash('sha256', $state);
        $cookie = $respostaLogin->getCookie($nomeCookie);

        $this->assertNotNull($cookie);

        session()->invalidate();
        session()->save();

        $this->withCookie(
            $nomeCookie,
            $cookie->getValue(),
        )->get('/sso/callback?'.http_build_query([
            'state' => $state,
            'code' => 'codigo-valido',
        ]))->assertRedirect($destino);
    }

    private function stateDoRedirecionamento(?string $localizacao): string
    {
        $this->assertNotNull($localizacao);

        parse_str(
            (string) parse_url($localizacao, PHP_URL_QUERY),
            $parametros,
        );

        $this->assertArrayHasKey('state', $parametros);

        return $parametros['state'];
    }
}
