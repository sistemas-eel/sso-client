<?php

namespace PortalSistemas\SSOClient\Session;

/**
 * Gerenciador de sessão framework-agnóstico para aplicações PHP legadas.
 *
 * Esta classe fornece uma interface simples para gerenciar sessões PHP
 * nativas sem depender do Laravel, funcionando em qualquer aplicação PHP 7.4+.
 *
 * ## Exemplo de Uso
 *
 * ```php
 * use PortalSistemas\SSOClient\Session\SessionHandler;
 *
 * // Inicializa com prefixo 'sso_' para evitar conflitos
 * $session = new SessionHandler('sso_');
 *
 * // Armazena dados
 * $session->set('user_codpes', 12345);
 * $session->set('access_token', $token);
 *
 * // Recupera dados
 * $codpes = $session->get('user_codpes');
 * $token = $session->get('access_token');
 *
 * // Remove e retorna
 * $state = $session->remove('oauth_state');
 *
 * // Verifica existência
 * if ($session->has('user_authenticated')) {
 *     echo "Usuário autenticado";
 * }
 *
 * // Limpa apenas variáveis SSO
 * $session->clear();
 *
 * // Destrói sessão completamente
 * $session->destroy();
 * ```
 *
 * ## Notas de Segurança
 *
 * - O método `regenerate()` deve ser chamado após login para prevenir session fixation
 * - Use prefixos diferentes para diferentes aplicações no mesmo domínio
 * - `clear()` remove apenas variáveis com o prefixo, não afeta outras sessões
 *
 * @package PortalSistemas\SSOClient\Session
 * @author  Gustavo
 * @license MIT
 * @link    https://github.com/sistemas-eel/sso-client
 */
class SessionHandler implements SessionHandlerInterface
{
    /** @var string Prefixo das chaves de sessão para evitar conflitos */
    private $prefix;

    /**
     * Inicializa o gerenciador de sessão com prefixo personalizado.
     *
     * @param string $prefix Prefixo para chaves de sessão (padrão: 'sso_')
     *                       Evita conflitos com outras variáveis de sessão
     */
    public function __construct(string $prefix = 'sso_')
    {
        $this->prefix = $prefix;

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /**
     * Armazena um valor na sessão com o prefixo configurado.
     *
     * @param string $key   Chave de identificação (será prefixada automaticamente)
     * @param mixed  $value Valor a ser armazenado (qualquer tipo serializável)
     *
     * @example
     * ```php
     * $session->set('user_codpes', 12345);
     * $session->set('access_token', 'eyJhbGci...');
     * $session->set('login_timestamp', time());
     * ```
     */
    public function set(string $key, $value): void
    {
        $_SESSION[$this->prefix . $key] = $value;
    }

    /**
     * Obtém um valor da sessão.
     *
     * @param string $key     Chave de identificação (será prefixada automaticamente)
     * @param mixed  $default Valor retornado se a chave não existir (padrão: null)
     *
     * @return mixed O valor armazenado ou $default se não existir
     *
     * @example
     * ```php
     * $codpes = $session->get('user_codpes');           // Retorna valor ou null
     * $token = $session->get('access_token', 'guest');  // Retorna valor ou 'guest'
     * ```
     */
    public function get(string $key, $default = null)
    {
        $fullKey = $this->prefix . $key;
        return isset($_SESSION[$fullKey]) ? $_SESSION[$fullKey] : $default;
    }

    /**
     * Remove um valor da sessão e retorna seu valor.
     *
     * @param string $key Chave de identificação (será prefixada automaticamente)
     *
     * @return mixed O valor removido ou null se não existir
     *
     * @example
     * ```php
     * // Útil para one-time tokens como OAuth state
     * $state = $session->remove('oauth_state');
     * // Após esta chamada, 'oauth_state' não existe mais na sessão
     * ```
     */
    public function remove(string $key)
    {
        $fullKey = $this->prefix . $key;
        if (isset($_SESSION[$fullKey])) {
            $value = $_SESSION[$fullKey];
            unset($_SESSION[$fullKey]);
            return $value;
        }
        return null;
    }

    /**
     * Verifica se uma chave existe na sessão.
     *
     * @param string $key Chave de identificação (será prefixada automaticamente)
     *
     * @return bool True se a chave existe e tem valor, false caso contrário
     *
     * @example
     * ```php
     * if ($session->has('user_authenticated')) {
     *     echo "Usuário está autenticado";
     * } else {
     *     header('Location: /login.php');
     *     exit;
     * }
     * ```
     */
    public function has(string $key): bool
    {
        return isset($_SESSION[$this->prefix . $key]);
    }

    /**
     * Limpa todas as variáveis de sessão com o prefixo configurado.
     *
     * Este método remove apenas as variáveis que começam com o prefixo
     * definido no construtor, não afetando outras variáveis de sessão
     * da aplicação.
     *
     * @example
     * ```php
     * // Limpa apenas dados SSO, mantém outros dados de sessão
     * $session->clear();
     * ```
     */
    public function clear(): void
    {
        foreach ($_SESSION as $key => $value) {
            if (strpos($key, $this->prefix) === 0) {
                unset($_SESSION[$key]);
            }
        }
    }

    /**
     * Destrói completamente a sessão PHP.
     *
     * Atenção: Este método destrói TODA a sessão, não apenas variáveis SSO.
     * Use clear() se quiser manter outras variáveis de sessão.
     *
     * @example
     * ```php
     * // Logout completo
     * $session->destroy();
     * header('Location: /index.php');
     * exit;
     * ```
     */
    public function destroy(): void
    {
        session_destroy();
    }

    /**
     * Regenera o ID da sessão para prevenir ataques de session fixation.
     *
     * Deve ser chamado após autenticação bem-sucedida para garantir
     * que o ID da sessão seja novo e imprevisível.
     *
     * @example
     * ```php
     * // Após login bem-sucedido
     * $session->set('user_authenticated', true);
     * $session->set('user_codpes', $userInfo['codpes']);
     *
     * // Regenera ID para segurança
     * $session->regenerate();
     * ```
     */
    public function regenerate(): void
    {
        session_regenerate_id(true);
    }
}
