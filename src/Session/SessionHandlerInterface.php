<?php

namespace PortalSistemas\SSOClient\Session;

/**
 * Interface para manipulador de sessão framework-agnóstico.
 */
interface SessionHandlerInterface
{
    /**
     * Armazena um valor na sessão.
     *
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function set(string $key, $value): void;

    /**
     * Obtém um valor da sessão.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, $default = null);

    /**
     * Remove um valor da sessão e retorna seu valor.
     *
     * @param string $key
     * @return mixed
     */
    public function remove(string $key);

    /**
     * Verifica se uma chave existe na sessão.
     *
     * @param string $key
     * @return bool
     */
    public function has(string $key): bool;

    /**
     * Limpa todas as variáveis de sessão.
     *
     * @return void
     */
    public function clear(): void;

    /**
     * Destroi completamente a sessão.
     *
     * @return void
     */
    public function destroy(): void;

    /**
     * Regenera o ID da sessão para segurança.
     *
     * @return void
     */
    public function regenerate(): void;
}
