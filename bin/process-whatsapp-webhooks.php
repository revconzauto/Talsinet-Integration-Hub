<?php

declare(strict_types=1);

use Talsinet\IntegrationHub\Database\Database;
use Talsinet\IntegrationHub\Services\WhatsApp\WhatsAppWebhookProcessor;

require_once dirname(__DIR__) . '/src/bootstrap.php';

try {
    $limit = isset($argv[1]) ? filter_var($argv[1], FILTER_VALIDATE_INT) : 100;

    if ($limit === false || $limit < 1) {
        throw new InvalidArgumentException('Limit must be a positive integer.');
    }

    $result = (new WhatsAppWebhookProcessor(Database::connection()))->processPending($limit);
    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR) . "\n");

    if ($result['failed'] > 0) {
        exit(1);
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'WhatsApp webhook processing failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
