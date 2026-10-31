<?php

declare(strict_types=1);

namespace Talsinet\IntegrationHub\Http\Controllers;

final class PaymentController
{
    public function create(): array
    {
        return [
            'status' => 'not_implemented',
            'message' => 'Payment creation endpoint is ready for implementation.',
        ];
    }
}