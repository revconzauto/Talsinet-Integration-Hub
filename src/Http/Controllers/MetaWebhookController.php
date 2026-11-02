<?php

declare(strict_types=1);

namespace Talsinet\IntegrationHub\Http\Controllers;

use PDO;
use Talsinet\IntegrationHub\Http\ApiException;
use Talsinet\IntegrationHub\Services\WhatsApp\WhatsAppConfig;

final class MetaWebhookController
{
    public function __construct(
        private WhatsAppConfig $config
    ) {
    }

    public function verify(): void
    {
        $mode = $_GET['hub.mode'] ?? $_GET['hub_mode'] ?? '';
        $token = $_GET['hub.verify_token'] ?? $_GET['hub_verify_token'] ?? '';
        $challenge = $_GET['hub.challenge'] ?? $_GET['hub_challenge'] ?? '';
        $expectedToken = $this->config->require('META_WEBHOOK_VERIFY_TOKEN');

        if ($mode !== 'subscribe' || !is_string($token) || !hash_equals($expectedToken, $token)) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Forbidden';
            return;
        }

        http_response_code(200);
        header('Content-Type: text/plain; charset=utf-8');
        echo is_string($challenge) ? $challenge : '';
    }

    public function receive(PDO $database, string $rawBody, string $signature): array
    {
        if ($rawBody === '' || strlen($rawBody) > 1_048_576) {
            throw new ApiException($rawBody === '' ? 400 : 413, 'Invalid webhook request body.');
        }

        $appSecret = $this->config->require('META_APP_SECRET');
        $expectedSignature = 'sha256=' . hash_hmac('sha256', $rawBody, $appSecret);

        if ($signature === '' || !hash_equals($expectedSignature, $signature)) {
            throw new ApiException(401, 'Invalid Meta webhook signature.');
        }

        $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($payload)) {
            throw new ApiException(400, 'Webhook body must be a JSON object.');
        }

        if (
            ($payload['object'] ?? null) !== 'whatsapp_business_account'
            || !is_array($payload['entry'] ?? null)
        ) {
            throw new ApiException(400, 'Webhook body is not a WhatsApp Business Account event.');
        }

        $statement = $database->prepare(
            'INSERT IGNORE INTO whatsapp_webhook_events (
                payload_sha256, event_type, payload, signature_valid, processing_status
             ) VALUES (
                :payload_sha256, :event_type, :payload, 1, \'received\'
             )'
        );
        $statement->execute([
            'payload_sha256' => hash('sha256', $rawBody),
            'event_type' => isset($payload['object']) && is_string($payload['object'])
                ? $payload['object']
                : null,
            'payload' => $rawBody,
        ]);

        return ['status' => 'accepted'];
    }
}
