<?php

declare(strict_types=1);

namespace Talsinet\IntegrationHub\Providers\SecurePhone;

use JsonException;
use RuntimeException;
use Talsinet\IntegrationHub\Services\WhatsApp\WhatsAppConfig;

final class SecurePhoneClient
{
    private const MESSAGE_TYPES = ['text', 'image', 'documents'];

    public function __construct(private WhatsAppConfig $config)
    {
    }

    public function forwardWhatsAppMessage(array $message): void
    {
        $type = $message['type'] ?? null;

        if (!is_string($type)) {
            throw new RuntimeException('SecurePhone message is missing its Meta message type.');
        }

        if ($type === 'document') {
            $message['type'] = 'documents';
            $type = 'documents';
        }

        if (!in_array($type, self::MESSAGE_TYPES, true)) {
            throw new RuntimeException(sprintf(
                'SecurePhone does not support WhatsApp message type "%s".',
                $type
            ));
        }

        $baseUrl = $this->config->securePhoneBaseUrl();
        $url = $baseUrl . '/api/v1/integrations/talsinet/whatsapp/messages';
        $this->request($url, 'POST', json_encode($message, JSON_THROW_ON_ERROR));
    }

    public function readiness(): array
    {
        $url = $this->config->securePhoneBaseUrl() . '/api/v1/integrations/talsinet';
        return $this->request($url, 'GET');
    }

    private function request(string $url, string $method, ?string $body = null): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", [
                    'Authorization: Bearer ' . $this->config->require('TALSINET_API_KEY'),
                    'Content-Type: application/json',
                    'Accept: application/json',
                ]),
                'content' => $body ?? '',
                'timeout' => 10,
                'ignore_errors' => true,
                'follow_location' => 0,
                'max_redirects' => 0,
            ],
        ]);
        set_error_handler(static function (int $severity, string $message): bool {
            throw new RuntimeException('SecurePhone API request failed before a response was received.');
        });

        try {
            $responseBody = file_get_contents($url, false, $context);
        } finally {
            restore_error_handler();
        }

        $statusCode = $this->responseStatusCode($http_response_header ?? []);

        if ($responseBody === false) {
            throw new RuntimeException('SecurePhone API returned no response body.');
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new RuntimeException(sprintf('SecurePhone API returned HTTP %d.', $statusCode));
        }

        if ($responseBody === '' && $method === 'POST') {
            return [];
        }

        try {
            $response = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('SecurePhone API returned invalid JSON.', 0, $exception);
        }

        if (!is_array($response)) {
            throw new RuntimeException('SecurePhone API returned an invalid response shape.');
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
