<?php

namespace PortalSistemas\SSOClient\Cache;

/**
 * Interface para manipulador de cache framework-agnóstico.
 */
interface CacheHandlerInterface
{
    /**
     * Armazena um item no cache.
     *
     * @param string $key
     * @param mixed $value
     * @param int $ttl Tempo de vida em segundos
     * @return bool
     */
    public function put(string $key, $value, int $ttl): bool;

    /**
     * Obtém um item do cache.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, $default = null);

    /**
     * Remove um item do cache.
     *
     * @param string $key
     * @return bool
     */
    public function forget(string $key): bool;
}
