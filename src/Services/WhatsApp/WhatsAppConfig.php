<?php

declare(strict_types=1);

namespace Talsinet\IntegrationHub\Services\WhatsApp;

use RuntimeException;

final class WhatsAppConfig
{
    private const LEGACY_KEYS = [
        'META_APP_SECRET' => 'WHATSAPP_META_APP_SECRET',
        'META_WEBHOOK_VERIFY_TOKEN' => 'WHATSAPP_META_VERIFY_TOKEN',
        'META_GRAPH_API_VERSION' => 'WHATSAPP_META_GRAPH_VERSION',
        'WHATSAPP_ACCESS_TOKEN' => 'WHATSAPP_META_ACCESS_TOKEN',
    ];

    private const APPLICATIONS = [
        'SECUREPHONE_WA' => [
            'application_code' => 'SECUREPHONE',
            'name' => 'SecurePhone',
            'phone_key' => 'WHATSAPP_PHONE_NUMBER_ID_SECUREPHONE',
            'api_key' => 'SECUREPHONE_API_KEY',
        ],
        'CIVICPAY_WA' => [
            'application_code' => 'CIVICPAY',
            'name' => 'CivicPay AI',
            'phone_key' => 'WHATSAPP_PHONE_NUMBER_ID_CIVICPAY',
            'api_key' => 'CIVICPAY_API_KEY',
        ],
        'FIRMCASE_WA' => [
            'application_code' => 'FIRMCASE',
            'name' => 'FirmCase',
            'phone_key' => 'WHATSAPP_PHONE_NUMBER_ID_FIRMCASE',
            'api_key' => 'FIRMCASE_API_KEY',
        ],
    ];

    public function get(string $key): string
    {
        foreach ([$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)] as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        if (isset(self::LEGACY_KEYS[$key])) {
            $legacyKey = self::LEGACY_KEYS[$key];
            foreach ([$_ENV[$legacyKey] ?? null, $_SERVER[$legacyKey] ?? null, getenv($legacyKey)] as $value) {
                if (is_string($value) && trim($value) !== '') {
                    return trim($value);
                }
            }
        }

        return '';
    }

    public function require(string $key): string
    {
        $value = $this->get($key);

        if ($value === '') {
            throw new RuntimeException(sprintf('Missing required configuration: %s.', $key));
        }

        return $value;
    }

    public function graphApiVersion(): string
    {
        $version = $this->require('META_GRAPH_API_VERSION');

        if (!preg_match('/^v\d+\.\d+$/', $version)) {
            throw new RuntimeException('META_GRAPH_API_VERSION must look like vNN.N.');
        }

        return $version;
    }

    public function securePhoneBaseUrl(): string
    {
        $baseUrl = rtrim($this->require('SECUREPHONE_API_BASE_URL'), '/');
        $scheme = parse_url($baseUrl, PHP_URL_SCHEME);

        if (filter_var($baseUrl, FILTER_VALIDATE_URL) === false || $scheme !== 'https') {
            throw new RuntimeException('SECUREPHONE_API_BASE_URL must be an HTTPS URL.');
        }

        return $baseUrl;
    }

    public function application(string $channelCode): array
    {
        if (!isset(self::APPLICATIONS[$channelCode])) {
            throw new RuntimeException('Unknown WhatsApp channel.');
        }

        return self::APPLICATIONS[$channelCode];
    }

    public function applications(): array
    {
        return self::APPLICATIONS;
    }
}
