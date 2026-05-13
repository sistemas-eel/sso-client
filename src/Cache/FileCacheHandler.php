<?php

namespace PortalSistemas\SSOClient\Cache;

/**
 * Implementação de cache baseada em arquivos para PHP legado.
 *
 * Esta classe fornece uma implementação simples de cache usando arquivos,
 * ideal para aplicações que não possuem Redis, Memcached ou outros sistemas
 * de cache distribuído.
 *
 * ## Exemplo de Uso
 *
 * ```php
 * use PortalSistemas\SSOClient\Cache\FileCacheHandler;
 *
 * // Cria cache no diretório especificado
 * $cache = new FileCacheHandler('/tmp/sso-cache');
 *
 * // Armazena dado com TTL de 1 hora (3600 segundos)
 * $cache->put('user_session_123', ['codpes' => 12345], 3600);
 *
 * // Recupera dado
 * $data = $cache->get('user_session_123');
 *
 * // Remove dado
 * $cache->forget('user_session_123');
 * ```
 *
 * ## Uso com Logout Backchannel
 *
 * ```php
 * // Registra logout global
 * $cache->put('sso_global_logout_' . $codpes, time(), 86400); // 24h
 *
 * // Verifica se houve logout global
 * $globalLogoutTime = $cache->get('sso_global_logout_' . $codpes);
 * if ($globalLogoutTime && $globalLogoutTime > $loginTime) {
 *     // Força novo login
 * }
 * ```
 *
 * ## Notas
 *
 * - O diretório de cache deve ter permissão de escrita para o PHP
 * - Arquivos expirados são removidos automaticamente ao tentar acessar
 * - Use cleanExpired() periodicamente para limpar arquivos órfãos
 *
 * @package PortalSistemas\SSOClient\Cache
 * @author  Gustavo
 * @license MIT
 * @link    https://github.com/sistemas-eel/sso-client
 */
class FileCacheHandler implements CacheHandlerInterface
{
    /** @var string Diretório onde os arquivos de cache serão armazenados */
    private $cacheDir;

    /**
     * Inicializa o gerenciador de cache em arquivo.
     *
     * @param string|null $cacheDir Diretório personalizado para armazenamento do cache.
     *                              Se null, usa o diretório temporário do sistema.
     *
     * @throws \RuntimeException Se o diretório não puder ser criado
     *
     * @example
     * ```php
     * // Usa diretório temporário do sistema
     * $cache = new FileCacheHandler();
     *
     * // Usa diretório personalizado
     * $cache = new FileCacheHandler('/var/cache/sso');
     *
     * // Usa diretório relativo ao projeto
     * $cache = new FileCacheHandler(__DIR__ . '/../cache');
     * ```
     */
    public function __construct(?string $cacheDir = null)
    {
        $this->cacheDir = $cacheDir ?: sys_get_temp_dir() . '/sso-client-cache';

        if (!is_dir($this->cacheDir)) {
            if (!mkdir($this->cacheDir, 0755, true)) {
                throw new \RuntimeException("Não foi possível criar o diretório de cache: {$this->cacheDir}");
            }
        }
    }

    /**
     * {@inheritdoc}
     *
     * @example
     * ```php
     * // Armazena por 1 hora
     * $cache->put('user_token', $token, 3600);
     *
     * // Armazena por 24 horas (logout backchannel)
     * $cache->put('sso_global_logout_12345', time(), 86400);
     * ```
     */
    public function put(string $key, $value, int $ttl): bool
    {
        $file = $this->getCacheFile($key);
        $data = [
            'value' => $value,
            'expiration' => time() + $ttl,
        ];

        $result = file_put_contents($file, serialize($data), LOCK_EX);
        return $result !== false;
    }

    /**
     * {@inheritdoc}
     *
     * @example
     * ```php
     * // Recupera valor
     * $token = $cache->get('user_token');
     *
     * // Recupera com valor padrão
     * $logoutTime = $cache->get('sso_global_logout_12345', 0);
     *
     * // Verifica se existe
     * $data = $cache->get('user_token');
     * if ($data !== null) {
     *     // Token existe no cache
     * }
     * ```
     */
    public function get(string $key, $default = null)
    {
        $file = $this->getCacheFile($key);

        if (!file_exists($file)) {
            return $default;
        }

        $data = unserialize(file_get_contents($file));

        if ($data === false || time() > $data['expiration']) {
            $this->forget($key);
            return $default;
        }

        return $data['value'];
    }

    /**
     * {@inheritdoc}
     *
     * @example
     * ```php
     * // Remove entrada específica
     * $cache->forget('user_token');
     * ```
     */
    public function forget(string $key): bool
    {
        $file = $this->getCacheFile($key);

        if (file_exists($file)) {
            return @unlink($file);
        }

        return true;
    }

    /**
     * Limpa todos os itens expirados do cache.
     *
     * Este método deve ser chamado periodicamente (via cron ou similar)
     * para remover arquivos de cache que já expiraram e liberar espaço.
     *
     * @example
     * ```php
     * // Em um script de manutenção ou cron job
     * $cache = new FileCacheHandler('/var/cache/sso');
     * $cache->cleanExpired();
     * ```
     */
    public function cleanExpired(): void
    {
        $files = glob($this->cacheDir . '/sso_cache_*');

        foreach ($files as $file) {
            $data = @unserialize(file_get_contents($file));
            if ($data !== false && time() > $data['expiration']) {
                @unlink($file);
            }
        }
    }

    /**
     * Limpa todo o cache, incluindo itens não expirados.
     *
     * Atenção: Este método remove TODOS os arquivos de cache,
     * mesmo os que ainda não expiraram.
     *
     * @example
     * ```php
     * // Limpa cache completamente (cuidado!)
     * $cache->flush();
     * ```
     */
    public function flush(): void
    {
        $files = glob($this->cacheDir . '/sso_cache_*');
        foreach ($files as $file) {
            @unlink($file);
        }
    }

    /**
     * Obtém o caminho do arquivo de cache para uma chave.
     */
    private function getCacheFile(string $key): string
    {
        return $this->cacheDir . '/sso_cache_' . md5($key);
    }
}
