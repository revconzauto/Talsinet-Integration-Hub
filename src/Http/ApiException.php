<?php

declare(strict_types=1);

namespace Talsinet\IntegrationHub\Http;

use RuntimeException;

final class ApiException extends RuntimeException
{
    public int $statusCode;

    public function __construct(
        int $statusCode,
        string $message
    ) {
        $this->statusCode = $statusCode;
        parent::__construct($message);
    }
}
