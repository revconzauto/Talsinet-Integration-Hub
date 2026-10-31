<?php

declare(strict_types=1);

namespace Talsinet\IntegrationHub\Services\Payment;

use InvalidArgumentException;
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
        string $currency
    ): array {
        if ($reference === '') {
            throw new InvalidArgumentException(
                'Payment reference is required.'
            );
        }

        if ($phoneNumber === '') {
            throw new InvalidArgumentException(
                'Phone number is required.'
            );
        }

        if ($amount <= 0) {
            throw new InvalidArgumentException(
                'Payment amount must be greater than zero.'
            );
        }

        if ($currency !== 'SZL') {
            throw new InvalidArgumentException(
                'Only SZL is currently supported.'
            );
        }

        return $this->provider->initiatePayment(
            $reference,
            $phoneNumber,
            $amount,
            $currency
        );
    }
}