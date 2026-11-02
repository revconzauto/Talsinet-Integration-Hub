<?php

declare(strict_types=1);

use Talsinet\IntegrationHub\Database\Database;
use Talsinet\IntegrationHub\Http\ApiException;
use Talsinet\IntegrationHub\Http\Controllers\MetaWebhookController;
use Talsinet\IntegrationHub\Http\Controllers\WhatsAppMessageController;
use Talsinet\IntegrationHub\Http\Middleware\ApiKeyMiddleware;
use Talsinet\IntegrationHub\Http\Responses\JsonResponse;
use Talsinet\IntegrationHub\Providers\Meta\MetaGraphClient;
use Talsinet\IntegrationHub\Services\WhatsApp\WhatsAppConfig;

require_once dirname(__DIR__) . '/src/bootstrap.php';

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($path === '/' || $path === '/health') {
    $databaseStatus = 'connected';
    $httpStatus = 200;

    try {
        Database::connection()->query('SELECT 1');
    } catch (Throwable $exception) {
        error_log('Database connection failed: ' . $exception->getMessage());
        $databaseStatus = 'unavailable';
        $httpStatus = 503;
    }

    http_response_code($httpStatus);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'application' => 'Talsinet Integration Hub',
        'status' => $databaseStatus === 'connected' ? 'running' : 'degraded',
        'environment' => $_ENV['APP_ENV'] ?? 'local-development',
        'version' => '0.1.0',
        'database' => [
            'name' => $_ENV['DB_DATABASE'] ?? 'talsinet_hub',
            'status' => $databaseStatus,
        ],
        'timestamp' => gmdate('c'),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if ($path === '/webhooks/meta') {
        $config = new WhatsAppConfig();
        $controller = new MetaWebhookController($config);

        if ($method === 'GET') {
            $controller->verify();
            exit;
        }

        if ($method !== 'POST') {
            header('Allow: GET, POST');
            throw new ApiException(405, 'Method not allowed.');
        }

        $rawBody = file_get_contents('php://input');
        $signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';

        JsonResponse::send($controller->receive(
            Database::connection(),
            $rawBody === false ? '' : $rawBody,
            is_string($signature) ? $signature : ''
        ));
        exit;
    }

    if ($path === '/api/whatsapp/messages') {
        if (!in_array($method, ['GET', 'POST'], true)) {
            header('Allow: GET, POST');
            throw new ApiException(405, 'Method not allowed.');
        }

        $database = Database::connection();
        $applicationCode = $_SERVER['HTTP_X_APPLICATION_CODE'] ?? '';
        $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
        $application = (new ApiKeyMiddleware($database))->authenticate(
            is_string($applicationCode) ? $applicationCode : '',
            is_string($apiKey) ? $apiKey : ''
        );
        $config = new WhatsAppConfig();

        if ($method === 'GET') {
            $afterInput = $_GET['after_id'] ?? 0;
            $limitInput = $_GET['limit'] ?? 50;

            if (
                (!is_string($afterInput) && !is_int($afterInput))
                || (!is_string($limitInput) && !is_int($limitInput))
            ) {
                throw new ApiException(422, 'after_id and limit must be valid non-negative and positive integers.');
            }

            $afterId = filter_var($afterInput, FILTER_VALIDATE_INT);
            $limit = filter_var($limitInput, FILTER_VALIDATE_INT);

            if ($afterId === false || $afterId < 0 || $limit === false || $limit < 1) {
                throw new ApiException(422, 'after_id and limit must be valid non-negative and positive integers.');
            }

            $controller = new WhatsAppMessageController(
                $database,
                $config,
                new MetaGraphClient($config)
            );
            JsonResponse::send($controller->inbox($application, $afterId, $limit));
            exit;
        }

        $rawBody = file_get_contents('php://input');
        if ($rawBody === false || strlen($rawBody) > 65_536) {
            throw new ApiException(413, 'Request body is too large or could not be read.');
        }

        try {
            $input = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ApiException(400, 'Request body must be valid JSON.');
        }

        if (!is_array($input)) {
            throw new ApiException(400, 'Request body must be a JSON object.');
        }

        $controller = new WhatsAppMessageController(
            $database,
            $config,
            new MetaGraphClient($config)
        );

        JsonResponse::send($controller->send($application, $input));
        exit;
    }

    throw new ApiException(404, 'Route not found.');
} catch (ApiException $exception) {
    JsonResponse::send(
        ['error' => $exception->getMessage()],
        $exception->statusCode
    );
} catch (JsonException $exception) {
    JsonResponse::send(['error' => 'Webhook body must be valid JSON.'], 400);
} catch (Throwable $exception) {
    error_log('Integration Hub request failed: ' . $exception->getMessage());
    JsonResponse::send(['error' => 'The request could not be completed.'], 500);
}
