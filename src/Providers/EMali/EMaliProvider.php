<?php

declare(strict_types=1);

namespace Talsinet\IntegrationHub\Providers\EMali;

use Talsinet\IntegrationHub\Providers\PaymentProviderInterface;

final class EMaliProvider implements PaymentProviderInterface
{
    public function initiatePayment(
        string $reference,
        string $phoneNumber,
        float $amount,
        string $currency
    ): array {
        /*
         * E-Mali API implementation will be added
         * using the official E-Mali integration specification.
         */

        return [
            'provider' => 'EMALI',
            'status' => 'not_configured',
            'reference' => $reference,
            'provider_reference' => null,
            'amount' => $amount,
            'currency' => $currency,
        ];
    }

    public function checkPaymentStatus(
        string $providerReference
    ): array {
        return [
            'provider' => 'EMALI',
            'status' => 'not_configured',
            'provider_reference' => $providerReference,
        ];
    }

    public function verifyWebhook(
        array $headers,
        string $payload
    ): bool {
        /*
         * Implement using the exact authentication/
         * signature mechanism supplied by E-Mali.
         */

        return false;
    }
}