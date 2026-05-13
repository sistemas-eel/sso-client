<?php

namespace PortalSistemas\SSOClient\Agente;

class AgenteResponse
{
    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>|\Illuminate\Http\JsonResponse
     */
    public static function success(string $protocolo, ?string $respostaDireta = null, array $extra = [], int $status = 201)
    {
        $body = array_merge([
            'protocolo' => $protocolo,
        ], $extra);

        if ($respostaDireta !== null) {
            $body['resposta_direta'] = $respostaDireta;
        }

        return self::format($body, $status);
    }

    /**
     * @param array<string, mixed> $details
     * @return array<string, mixed>|\Illuminate\Http\JsonResponse
     */
    public static function error(string $message, int $status = 422, array $details = [])
    {
        $body = ['error' => $message];

        if (!empty($details)) {
            $body['details'] = $details;
        }

        return self::format($body, $status);
    }

    /**
     * @return array<string, mixed>|\Illuminate\Http\JsonResponse
     */
    public static function accepted(string $protocolo, ?string $respostaDireta = null)
    {
        return self::success($protocolo, $respostaDireta, [], 202);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>|\Illuminate\Http\JsonResponse
     */
    private static function format(array $body, int $status)
    {
        if (self::canUseLaravelResponse()) {
            return response()->json($body, $status);
        }

        return [
            'status' => $status,
            'body' => $body,
        ];
    }

    private static function canUseLaravelResponse(): bool
    {
        if (!class_exists('Illuminate\\Http\\JsonResponse')) {
            return false;
        }

        if (!function_exists('app') || !function_exists('response')) {
            return false;
        }

        try {
            $container = app();

            return is_object($container)
                && method_exists($container, 'bound')
                && $container->bound('Illuminate\\Contracts\\Routing\\ResponseFactory');
        } catch (\Throwable $e) {
            return false;
        }
    }
}
