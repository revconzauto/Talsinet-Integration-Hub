<?php

declare(strict_types=1);

use Talsinet\IntegrationHub\Database\Database;

require_once dirname(__DIR__) . '/src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $db = Database::connection();

    $statement = $db->query('SELECT 1 AS database_connection');

    $result = $statement->fetch();

    echo json_encode(
        [
            'application' => 'Talsinet Integration Hub',
            'status' => 'healthy',
            'database' => 'connected',
            'database_test' => $result['database_connection'] === 1,
            'php_version' => PHP_VERSION,
            'timestamp' => gmdate('c'),
        ],
        JSON_PRETTY_PRINT
    );
} catch (Throwable $exception) {
    http_response_code(500);

    echo json_encode(
        [
            'application' => 'Talsinet Integration Hub',
            'status' => 'unhealthy',
            'database' => 'connection_failed',
            'timestamp' => gmdate('c'),
        ],
        JSON_PRETTY_PRINT
    );
}