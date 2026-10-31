<?php

declare(strict_types=1);

namespace Talsinet\IntegrationHub\Providers;

interface PaymentProviderInterface
{
    public function initiatePayment(
        string $reference,
        string $phoneNumber,
        float $amount,
        string $currency
    ): array;

    public function checkPaymentStatus(
        string $providerReference
    ): array;

    public function verifyWebhook(
        array $headers,
        string $payload
    ): bool;
}