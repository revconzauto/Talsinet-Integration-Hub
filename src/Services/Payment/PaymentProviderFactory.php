<?php

declare(strict_types=1);

namespace Talsinet\IntegrationHub\Services\Payment;

use InvalidArgumentException;
use Talsinet\IntegrationHub\Providers\PaymentProviderInterface;
use Talsinet\IntegrationHub\Providers\MTN\MtnMomoProvider;
use Talsinet\IntegrationHub\Providers\EMali\EMaliProvider;

final class PaymentProviderFactory
{
    public static function create(
        string $paymentMethod
    ): PaymentProviderInterface {
        return match (strtoupper($paymentMethod)) {

            'MTN_MOMO' =>
                new MtnMomoProvider(),

            'EMALI' =>
                new EMaliProvider(),

            default =>
                throw new InvalidArgumentException(
                    'Unsupported payment method.'
                ),
        };
    }
}