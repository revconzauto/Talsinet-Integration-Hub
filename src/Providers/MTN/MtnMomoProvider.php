<?php

declare(strict_types=1);

namespace Talsinet\IntegrationHub\Providers\MTN;

use Talsinet\IntegrationHub\Providers\PaymentProviderInterface;

final class MtnMomoProvider implements PaymentProviderInterface
{
    public function initiatePayment(
        string $reference,
        string $phoneNumber,
        float $amount,
        string $currency
    ): array {
        /*
         * MTN MoMo API integration will be implemented
         * once production/sandbox credentials are available.
         */

        return [
            'provider' => 'MTN_MOMO',
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
            'provider' => 'MTN_MOMO',
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
         * signature mechanism supplied by MTN.
         */

        return false;
    }
}