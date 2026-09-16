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
