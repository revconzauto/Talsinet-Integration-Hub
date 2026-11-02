<?php

declare(strict_types=1);

namespace Talsinet\IntegrationHub\Providers\Meta;

use JsonException;
use RuntimeException;
use Talsinet\IntegrationHub\Services\WhatsApp\WhatsAppConfig;

final class MetaGraphClient
{
    public function __construct(private WhatsAppConfig $config)
    {
    }

    public function sendText(string $phoneNumberId, string $recipient, string $text): string
    {
        $url = sprintf(
            'https://graph.facebook.com/%s/%s/messages',
            $this->config->graphApiVersion(),
            rawurlencode($phoneNumberId)
        );
        $payload = json_encode(
            [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $recipient,
                'type' => 'text',
                'text' => ['body' => $text],
            ],
            JSON_THROW_ON_ERROR
        );
        $response = $this->request($url, 'POST', $payload);

        $messageId = $response['messages'][0]['id'] ?? null;

        if (!is_string($messageId) || $messageId === '') {
            throw new RuntimeException('Meta Graph API response did not contain a message ID.');
        }

        return $messageId;
    }

    public function listPhoneNumbers(string $wabaId): array
    {
        $url = sprintf(
            'https://graph.facebook.com/%s/%s/phone_numbers?fields=id,display_phone_number,verified_name&limit=100',
            $this->config->graphApiVersion(),
            rawurlencode($wabaId)
        );
        $response = $this->request($url, 'GET');
        $phoneNumbers = $response['data'] ?? null;

        if (!is_array($phoneNumbers)) {
            throw new RuntimeException('Meta Graph API response did not contain WABA phone numbers.');
        }

        return $phoneNumbers;
    }

    private function request(string $url, string $method, ?string $payload = null): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", [
                    'Authorization: Bearer ' . $this->config->require('WHATSAPP_ACCESS_TOKEN'),
                    'Content-Type: application/json',
                    'Accept: application/json',
                ]),
                'content' => $payload ?? '',
                'timeout' => 15,
                'ignore_errors' => true,
                'follow_location' => 0,
                'max_redirects' => 0,
            ],
        ]);
        set_error_handler(static function (int $severity, string $message): bool {
            throw new RuntimeException('Meta Graph API request failed before a response was received.');
        });

        try {
            $responseBody = file_get_contents($url, false, $context);
        } finally {
            restore_error_handler();
        }
        $responseHeaders = $http_response_header ?? [];
        $statusCode = $this->responseStatusCode($responseHeaders);

        if ($responseBody === false) {
            throw new RuntimeException('Meta Graph API request failed before a response was received.');
        }

        try {
            $response = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Meta Graph API returned an invalid response.', 0, $exception);
        }

        if (!is_array($response)) {
            throw new RuntimeException('Meta Graph API returned an invalid response shape.');
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            $error = $response['error'] ?? null;
            $errorCode = is_array($error) ? ($error['code'] ?? 'unknown') : 'unknown';
            if (!is_string($errorCode) && !is_int($errorCode)) {
                $errorCode = 'unknown';
            }
            throw new RuntimeException(sprintf('Meta Graph API rejected the message (code %s).', $errorCode));
        }

        return $response;
    }

    private function responseStatusCode(array $headers): int
    {
        $statusCode = 0;

        foreach ($headers as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches)) {
                $statusCode = (int) $matches[1];
            }
        }

        return $statusCode;
    }
}
