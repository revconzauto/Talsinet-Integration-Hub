<?php

declare(strict_types=1);

namespace Talsinet\IntegrationHub\Services\Payment;

use Talsinet\IntegrationHub\Providers\PaymentProviderInterface;

final class PaymentService
{
    public function __construct(
        private PaymentProviderInterface $provider
    ) {
    }

    public function initiate(
        string $reference,
        string $phoneNumber,
        float $amount,
        string $currency = 'SZL'
    ): array {
        return $this->provider->requestPayment(
            $reference,
            $phoneNumber,
            $amount,
            $currency
        );
    }
}