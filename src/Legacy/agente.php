<?php

use PortalSistemas\SSOClient\Agente\AgenteAuthException;
use PortalSistemas\SSOClient\Agente\AgenteEndpoint;
use PortalSistemas\SSOClient\Agente\AgentePayloadValidator;
use PortalSistemas\SSOClient\Agente\AgenteValidationException;
use PortalSistemas\SSOClient\Agente\SchemaValidator;
use PortalSistemas\SSOClient\Agente\TokenValidator;
use PortalSistemas\SSOClient\Cache\CacheHandlerInterface;

if (!function_exists('agente_request_from_globals')) {
    /**
     * @param array<string, mixed> $config
     */
    function agente_request_from_globals(array $config = [])
    {
        $cache = null;
        if (isset($config['cache']) && $config['cache'] instanceof CacheHandlerInterface) {
            $cache = $config['cache'];
        }

        $validator = new TokenValidator($config, $cache);
        $endpoint = AgenteEndpoint::fromGlobals();
        $debugEnabled = !empty($config['debug']);

        try {
            $endpoint->authenticate($validator);

            $schema = $config['schema'] ?? null;
            if (is_array($schema)) {
                $endpoint->validatePayload(new SchemaValidator($schema));
            }

            return $endpoint->request();
        } catch (AgenteAuthException $e) {
            $body = $e->toArray();
            if ($debugEnabled) {
                $authorization = (string) ($endpoint->request()->header('authorization') ?? '');
                $hasBearerPrefix = stripos($authorization, 'Bearer ') === 0;
                $tokenLength = $hasBearerPrefix ? strlen(trim(substr($authorization, 7))) : 0;

                $body['debug'] = [
                    'auth_header_present' => $authorization !== '',
                    'auth_header_has_bearer_prefix' => $hasBearerPrefix,
                    'bearer_token_length' => $tokenLength,
                    'introspection_endpoint' => (string) ($config['introspection_endpoint'] ?? ''),
                    'client_id_configured' => trim((string) ($config['client_id'] ?? '')) !== '',
                    'client_secret_configured' => !empty($config['client_secret']),
                    'required_scopes' => is_array($config['required_scopes'] ?? null) ? array_values($config['required_scopes']) : [],
                    'cache_ttl' => isset($config['cache_ttl']) ? (int) $config['cache_ttl'] : null,
                    'timeout' => isset($config['timeout']) ? (int) $config['timeout'] : null,
                ];
            }

            agente_log_event_if_available('warning', 'agente_auth_rejected', [
                'status' => $e->httpStatus(),
                'error' => $body['error'] ?? 'auth_error',
                'has_authorization_header' => $endpoint->request()->header('authorization') !== null,
            ]);
            agente_abort_json($e->httpStatus(), $body);
        } catch (AgenteValidationException $e) {
            $body = [
                'error' => 'unprocessable_entity',
                'message' => 'Payload v1 ou campos dinâmicos inválidos.',
                'details' => $e->errors(),
            ];

            agente_log_event_if_available('warning', 'agente_payload_rejected_by_library', [
                'status' => 422,
                'details' => $e->errors(),
            ]);
            agente_abort_json(422, $body);
        }

        agente_abort_json(500, [
            'error' => 'internal_error',
            'message' => 'Erro interno inesperado.',
        ]);
    }
}

if (!function_exists('agente_v1_request_from_globals')) {
    /**
     * Autentica a chamada, valida campos dinâmicos opcionais e valida o envelope v1.
     *
     * Configurações úteis:
     * - schema: schema de dados.campos
     * - acao: acao esperada, ex. abrir_chamado
     * - required_usuario: campos obrigatórios em usuário
     * - required_dados: campos obrigatórios em dados
     *
     * @param array<string, mixed> $config
     */
    function agente_v1_request_from_globals(array $config = [])
    {
        $request = agente_request_from_globals($config);

        try {
            (new AgentePayloadValidator([
                'origem' => $config['origem'] ?? 'agente_ia',
                'acao' => $config['acao'] ?? null,
                'schema_version' => $config['schema_version'] ?? 1,
                'payload_version' => $config['payload_version'] ?? 'v1',
                'required_usuario' => $config['required_usuario'] ?? ['nome', 'email'],
                'required_dados' => $config['required_dados'] ?? ['titulo', 'descricao'],
            ]))->validate($request);
        } catch (AgenteValidationException $e) {
            $body = [
                'error' => 'unprocessable_entity',
                'message' => 'Payload v1 ou campos dinâmicos inválidos.',
                'details' => $e->errors(),
            ];

            if (isset($config['request_id']) && is_scalar($config['request_id']) && (string) $config['request_id'] !== '') {
                $body['request_id'] = (string) $config['request_id'];
            }

            agente_log_event_if_available('warning', 'agente_payload_rejected_by_library', [
                'status' => 422,
                'details' => $e->errors(),
            ]);
            agente_abort_json(422, $body);
        }

        return $request;
    }
}

if (!function_exists('agente_log_event_if_available')) {
    /**
     * @param array<string, mixed> $context
     */
    function agente_log_event_if_available(string $level, string $event, array $context = []): void
    {
        if (!function_exists('agente_log_event')) {
            return;
        }

        try {
            agente_log_event($level, $event, $context);
        } catch (\Throwable $e) {
            // O endpoint do cliente não deve falhar por indisponibilidade de log.
        }
    }
}

if (!function_exists('agente_configure_log')) {
    /**
     * @param array<string, mixed> $config
     */
    function agente_configure_log(array $config): void
    {
        $current = is_array($GLOBALS['agente_log_config'] ?? null) ? $GLOBALS['agente_log_config'] : [];

        $GLOBALS['agente_log_config'] = array_merge($current, $config);
    }
}

if (!function_exists('agente_log_event')) {
    /**
     * Registra eventos JSONL do endpoint cliente.
     *
     * @param array<string, mixed> $context
     */
    function agente_log_event(string $level, string $event, array $context = []): void
    {
        $config = is_array($GLOBALS['agente_log_config'] ?? null) ? $GLOBALS['agente_log_config'] : [];
        $dir = isset($config['dir']) && is_scalar($config['dir'])
            ? rtrim((string) $config['dir'], DIRECTORY_SEPARATOR)
            : rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
        $prefix = isset($config['prefix']) && is_scalar($config['prefix']) && trim((string) $config['prefix']) !== ''
            ? trim((string) $config['prefix'])
            : 'agente-ia-cliente';
        $requestId = isset($config['request_id']) && is_scalar($config['request_id'])
            ? (string) $config['request_id']
            : (string) ($GLOBALS['requestId'] ?? '');

        $entry = [
            'ts' => date('c'),
            'level' => $level,
            'event' => $event,
            'request_id' => $requestId !== '' ? $requestId : null,
            'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? null,
            'context' => agente_sanitize_log_context($context),
        ];

        $json = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return;
        }

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        @file_put_contents($dir . DIRECTORY_SEPARATOR . $prefix . '-' . date('Y-m-d') . '.log', $json . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}

if (!function_exists('agente_is_sensitive_log_key')) {
    function agente_is_sensitive_log_key(string $key): bool
    {
        $key = strtolower($key);

        foreach (['authorization', 'cookie', 'token', 'secret', 'password', 'senha'] as $needle) {
            if (strpos($key, $needle) !== false) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('agente_sanitize_log_context')) {
    /**
     * @param mixed $value
     * @return mixed
     */
    function agente_sanitize_log_context($value)
    {
        if (is_array($value)) {
            $sanitized = [];

            foreach ($value as $key => $item) {
                $keyString = is_scalar($key) ? (string) $key : '';
                $sanitized[$key] = agente_is_sensitive_log_key($keyString)
                    ? '[REDACTED]'
                    : agente_sanitize_log_context($item);
            }

            return $sanitized;
        }

        if (is_string($value)) {
            if (function_exists('mb_check_encoding') && !mb_check_encoding($value, 'UTF-8')) {
                return '[INVALID_UTF8_REDACTED]';
            }

            return mb_strlen($value) > 2000 ? mb_substr($value, 0, 2000) . '...[truncated]' : $value;
        }

        return (is_scalar($value) || $value === null) ? $value : '[UNSUPPORTED_VALUE]';
    }
}

if (!function_exists('agente_field_error')) {
    /**
     * @return array{campo:string, codigo:string, mensagem:string}
     */
    function agente_field_error(string $campo, string $codigo, string $mensagem): array
    {
        return [
            'campo' => $campo,
            'codigo' => $codigo,
            'mensagem' => $mensagem,
        ];
    }
}

if (!function_exists('agente_abort_payload_invalido')) {
    /**
     * Registra a rejeição do payload pelo sistema cliente e encerra com HTTP 422.
     *
     * @param array<int, array<string, mixed>> $details
     * @param array<string, mixed> $payload
     */
    function agente_abort_payload_invalido(array $details, array $payload = [], ?string $requestId = null): void
    {
        agente_log_event_if_available('warning', 'agente_payload_rejected_by_client', [
            'status' => 422,
            'details' => $details,
            'solicitacao_id_origem' => $payload['solicitacao_id_origem'] ?? null,
        ]);

        $body = [
            'error' => 'unprocessable_entity',
            'message' => 'Payload v1 ou campos dinâmicos inválidos.',
            'details' => $details,
        ];

        if ($requestId !== null && $requestId !== '') {
            $body['request_id'] = $requestId;
        }

        agente_abort_json(422, $body);
    }
}

if (!function_exists('agente_emit_response')) {
    /**
     * @param array{status:int, body:array<string, mixed>} $resp
     */
    function agente_emit_response(array $resp, ?string $requestId = null): void
    {
        $body = $resp['body'];
        if ($requestId !== null && $requestId !== '' && !array_key_exists('request_id', $body)) {
            $body['request_id'] = $requestId;
        }

        if (!headers_sent()) {
            http_response_code((int) $resp['status']);
            header('Content-Type: application/json');
        }

        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

if (!function_exists('agente_emit_response_with_log')) {
    /**
     * @param array{status:int, body:array<string, mixed>} $resp
     * @param array<string, mixed> $context
     */
    function agente_emit_response_with_log(array $resp, string $level, string $event, array $context = [], ?string $requestId = null): void
    {
        agente_emit_response($resp, $requestId);

        agente_log_event_if_available($level, $event, array_merge([
            'status' => $resp['status'],
        ], $context));
    }
}

if (!function_exists('agente_mask_fields_by_schema')) {
    /**
     * @param array<string, mixed> $campos
     * @param array<int, array<string, mixed>> $schema
     * @return array<string, mixed>
     */
    function agente_mask_fields_by_schema(array $campos, array $schema): array
    {
        $resultado = $campos;

        foreach ($schema as $campo) {
            if (!is_array($campo)) {
                continue;
            }

            $chave = isset($campo['chave']) ? (string) $campo['chave'] : '';
            $sensivel = (bool) ($campo['sensivel'] ?? false);

            if ($chave !== '' && $sensivel && array_key_exists($chave, $resultado)) {
                $resultado[$chave] = '[REDACTED]';
            }
        }

        return $resultado;
    }
}

if (!function_exists('agente_mask_payload_by_schema')) {
    /**
     * @param array<string, mixed> $payload
     * @param array<int, array<string, mixed>> $schema
     * @return array<string, mixed>
     */
    function agente_mask_payload_by_schema(array $payload, array $schema): array
    {
        $resultado = $payload;
        $campos = is_array($resultado['dados']['campos'] ?? null) ? $resultado['dados']['campos'] : [];
        $resultado['dados']['campos'] = agente_mask_fields_by_schema($campos, $schema);

        return $resultado;
    }
}

if (!function_exists('agente_log_request_received')) {
    /**
     * @param mixed $request
     * @param array<string, mixed> $payload
     * @param array<int, array<string, mixed>> $schema
     * @param array<string, mixed> $context
     */
    function agente_log_request_received($request, array $payload, array $schema = [], array $context = []): void
    {
        $baseContext = [
            'payload_version' => is_object($request) && method_exists($request, 'payloadVersion') ? $request->payloadVersion() : null,
            'schema_version_header' => is_object($request) && method_exists($request, 'header') ? $request->header('x-agente-schema-version') : null,
            'solicitacao_id_origem' => $payload['solicitacao_id_origem'] ?? null,
            'payload' => agente_mask_payload_by_schema($payload, $schema),
        ];

        agente_log_event_if_available('info', 'agente_request_received', array_merge($context, $baseContext));
    }
}

if (!function_exists('agente_generate_request_id')) {
    /**
     * @return non-empty-string
     */
    function agente_generate_request_id(): string
    {
        $incoming = trim((string) ($_SERVER['HTTP_X_REQUEST_ID'] ?? ''));
        if ($incoming !== '' && preg_match('/^[A-Za-z0-9._-]{8,80}$/', $incoming) === 1) {
            return $incoming;
        }

        try {
            return bin2hex(random_bytes(8));
        } catch (\Throwable $e) {
            return str_replace('.', '', uniqid('agente', true));
        }
    }
}

if (!function_exists('agente_abort_http_error')) {
    function agente_abort_http_error(int $status, string $error, string $message, ?string $requestId = null): void
    {
        $body = [
            'error' => $error,
            'message' => $message,
        ];

        if ($requestId !== null && $requestId !== '') {
            $body['request_id'] = $requestId;
        }

        agente_log_event_if_available($status >= 500 ? 'error' : 'warning', 'agente_request_rejected_before_auth', [
            'status' => $status,
            'error' => $error,
        ]);

        agente_abort_json($status, $body);
    }
}

if (!function_exists('agente_guard_http_request')) {
    /**
     * Aplica guardas HTTP comuns para endpoints legados do Agente IA.
     *
     * @param array<string, mixed> $options
     * @return non-empty-string request_id
     */
    function agente_guard_http_request(array $options = []): string
    {
        $requestId = agente_generate_request_id();
        $GLOBALS['requestId'] = $requestId;

        if (!headers_sent()) {
            header('Content-Type: application/json');
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
            header('X-Request-Id: ' . $requestId);
        }

        $enabled = array_key_exists('enabled', $options) ? (bool) $options['enabled'] : true;
        if (!$enabled) {
            agente_abort_http_error(503, 'agente_disabled', 'Endpoint do Agente IA desabilitado neste sistema.', $requestId);
        }

        $allowedMethods = $options['methods'] ?? ['POST'];
        if (!is_array($allowedMethods) || $allowedMethods === []) {
            $allowedMethods = ['POST'];
        }
        $allowedMethods = array_values(array_map('strtoupper', array_map('strval', $allowedMethods)));
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? ''));
        if (!in_array($method, $allowedMethods, true)) {
            if (!headers_sent()) {
                header('Allow: ' . implode(', ', $allowedMethods));
            }
            agente_abort_http_error(405, 'method_not_allowed', 'Use ' . implode(', ', $allowedMethods) . ' para acionar o endpoint do Agente IA.', $requestId);
        }

        $maxContentLength = isset($options['max_content_length']) ? (int) $options['max_content_length'] : 1024 * 1024;
        $contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
        if ($maxContentLength > 0 && $contentLength > $maxContentLength) {
            agente_abort_http_error(413, 'payload_too_large', 'Payload excede o tamanho máximo permitido.', $requestId);
        }

        $requireJson = array_key_exists('require_json', $options) ? (bool) $options['require_json'] : true;
        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        if ($requireJson && $contentType !== '' && strpos($contentType, 'application/json') === false) {
            agente_abort_http_error(415, 'unsupported_media_type', 'Use Content-Type application/json.', $requestId);
        }

        return $requestId;
    }
}

if (!function_exists('agente_resolve_operation_mode')) {
    /**
     * @param array<string, mixed> $config
     * @return array{modo_operacao:string}
     */
    function agente_resolve_operation_mode(array $config): array
    {
        $modoOperacao = is_string($config['modo_operacao'] ?? null) ? strtolower(trim($config['modo_operacao'])) : 'preview';

        return [
            'modo_operacao' => $modoOperacao,
        ];
    }
}

if (!function_exists('agente_abort_json')) {
    /**
     * @param array<string, mixed> $body
     */
    function agente_abort_json(int $status, array $body): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json');
        }

        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
