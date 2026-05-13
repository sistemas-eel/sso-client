<?php

return [
    /**
     * URL do Servidor SSO (Portal de Sistemas).
     */
    'server_url' => env('SSO_SERVER_URL', 'https://portalsistemas.unidade.usp.br/portal-sistemas'),

    /**
     * Credenciais do Cliente OAuth.
     */
    'client_id' => env('SSO_CLIENT_ID'),
    'client_secret' => env('SSO_CLIENT_SECRET'),

    /**
     * Model de usuário usado no callback OAuth.
     */
    'user_model' => env('SSO_USER_MODEL', \App\Models\User::class),

    /**
     * Autorização recebida no userinfo do SSO.
     *
     * sync_permissions: quando true, sincroniza as permissões vindas de
     *                    authorization.permissions no provider local de
     *                    permissões, se o model suportar givePermissionTo()
     *                    e revokePermissionTo().
     */
    'authorization' => [
        'sync_permissions' => env('SSO_SYNC_PERMISSIONS', true),
    ],

    /**
     * Segredo compartilhado para validação de webhooks de logout.
     */
    'webhook_secret' => env('SSO_WEBHOOK_SECRET'),

    /**
     * URL de redirecionamento (deve estar registrada no Portal).
     */
    'redirect_uri' => env('SSO_REDIRECT_URI', env('APP_URL') . '/sso/callback'),

    /**
     * Configurações de SSL.
     *
     * verify_ssl: Habilita verificação de certificado SSL (recomendado: true).
     *             Desabilitar apenas em ambientes de desenvolvimento internos.
     * ca_bundle: Caminho para arquivo CA bundle personalizado (opcional).
     *            Útil para servidores com certificados internos.
     */
    'verify_ssl' => env('SSO_VERIFY_SSL', true),
    'ca_bundle' => env('SSO_CA_BUNDLE'),

    /**
     * Caminho padrão após o login.
     */
    'home_path' => '/',

    /**
     * Controle da rota de login no Laravel.
     *
     * enabled: registra automaticamente uma rota nomeada `login` apontando
     *          para o fluxo SSO da biblioteca.
     * path: caminho HTTP usado para essa rota.
     * name: nome da rota usada pelo middleware `auth` do Laravel.
     */
    'login_route' => [
        'enabled' => env('SSO_LOGIN_ROUTE_ENABLED', true),
        'path' => env('SSO_LOGIN_ROUTE_PATH', '/login'),
        'name' => env('SSO_LOGIN_ROUTE_NAME', 'login'),
    ],

    /**
     * Controle da rota de logout no Laravel.
     *
     * enabled: registra automaticamente uma rota nomeada `logout` apontando
     *          para o fluxo de logout da biblioteca.
     * path: caminho HTTP usado para essa rota.
     * name: nome da rota usada pela aplicação para encerrar a sessão local.
     */
    'logout_route' => [
        'enabled' => env('SSO_LOGOUT_ROUTE_ENABLED', true),
        'path' => env('SSO_LOGOUT_ROUTE_PATH', '/logout'),
        'name' => env('SSO_LOGOUT_ROUTE_NAME', 'logout'),
    ],

    /**
     * Redirecionamento após logout local.
     */
    'logout_redirect' => '/',

    /**
     * Configurações de Middleware.
     */
    'middleware_group' => 'web',

    /**
     * Configuração para endpoints do Agente IA (server-to-server).
     */
    'agente' => [
        'enabled' => env('SSO_AGENTE_ENABLED', true),
        'required_scopes' => array_values(array_filter(array_map('trim', explode(',', (string) env('SSO_AGENTE_REQUIRED_SCOPES', 'agente:executar'))))),
        'token_validation' => env('SSO_AGENTE_TOKEN_VALIDATION', 'introspection'),
        'introspection_endpoint' => env('SSO_AGENTE_INTROSPECTION_ENDPOINT', env('SSO_SERVER_URL', 'https://portalsistemas.unidade.usp.br/portal-sistemas') . '/api/oauth/introspect'),
        'cache_ttl' => (int) env('SSO_AGENTE_TOKEN_CACHE_TTL', 60),
        'timeout' => (int) env('SSO_AGENTE_TIMEOUT', 5),
        'client_id' => env('SSO_AGENTE_CLIENT_ID', env('SSO_CLIENT_ID')),
        'expected_client_id' => env('SSO_AGENTE_EXPECTED_CLIENT_ID', env('SSO_AGENTE_CLIENT_ID', env('SSO_CLIENT_ID'))),
        'client_secret' => env('SSO_AGENTE_CLIENT_SECRET', env('SSO_CLIENT_SECRET')),
        'verify_ssl' => env('SSO_AGENTE_VERIFY_SSL', env('SSO_VERIFY_SSL', true)),
    ],
];
