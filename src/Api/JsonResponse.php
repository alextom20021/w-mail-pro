<?php

declare(strict_types=1);

namespace MailAI\Api;

/**
 * JsonResponse
 *
 * Consistent JSON envelope for every API endpoint. Keeping this in one
 * place means every controller returns the same shape without each one
 * reinventing `header()` + `json_encode()` calls.
 */
final class JsonResponse
{
    public static function ok(array $data, int $status = 200): void
    {
        self::send($status, ['data' => $data]);
    }

    public static function error(string $message, int $status = 400, array $extra = []): void
    {
        self::send($status, array_merge(['error' => $message], $extra));
    }

    private static function send(int $status, array $body): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($body, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    }
}
