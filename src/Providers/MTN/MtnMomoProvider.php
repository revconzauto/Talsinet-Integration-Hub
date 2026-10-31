<?php

declare(strict_types=1);

namespace Talsinet\IntegrationHub\Providers\MTN;

use Talsinet\IntegrationHub\Providers\PaymentProviderInterface;

final class MtnMomoProvider implements PaymentProviderInterface
{
    public function requestPayment(
        string $reference,
        string $phoneNumber,
        float $amount,
        string $currency
    ): array {
        return [
            'status' => 'not_configured',
            'provider' => 'mtn_momo',
            'reference' => $reference,
            'message' => 'MTN MoMo credentials are not configured.',
        ];
    }

    public function checkPaymentStatus(
        string $providerReference
    ): array {
        return [
            'status' => 'not_configured',
            'provider' => 'mtn_momo',
            'provider_reference' => $providerReference,
        ];
    }
}