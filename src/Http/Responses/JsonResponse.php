<?php

declare(strict_types=1);

namespace Talsinet\IntegrationHub\Http\Responses;

final class JsonResponse
{
    public static function send(array $payload, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}