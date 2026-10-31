<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$databaseStatus = 'connected';
$httpStatus = 200;

try {
    require __DIR__ . '/../config/database.php';
} catch (PDOException $exception) {
    error_log('Database connection failed: ' . $exception->getMessage());
    $databaseStatus = 'unavailable';
    $httpStatus = 503;
}

http_response_code($httpStatus);

echo json_encode([
    'application' => 'Talsinet Integration Hub',
    'status' => $databaseStatus === 'connected' ? 'running' : 'degraded',
    'environment' => 'local-development',
    'version' => '0.1.0',
    'database' => [
        'name' => getenv('DB_DATABASE') ?: 'talsinet_hub',
        'status' => $databaseStatus,
    ],
    'timestamp' => gmdate('c'),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
