<?php

declare(strict_types=1);

namespace Talsinet\IntegrationHub\Http\Middleware;

use PDO;
use Talsinet\IntegrationHub\Http\ApiException;

final class ApiKeyMiddleware
{
    public function __construct(private PDO $database)
    {
    }

    public function authenticate(string $applicationCode, string $apiKey): array
    {
        if ($applicationCode === '' || $apiKey === '') {
            throw new ApiException(401, 'Application credentials are required.');
        }

        $statement = $this->database->prepare(
            'SELECT id, application_code, api_key_hash
             FROM applications
             WHERE application_code = :application_code
               AND status = \'active\'
             LIMIT 1'
        );
        $statement->execute(['application_code' => $applicationCode]);
        $application = $statement->fetch();

        if (
            !is_array($application)
            || !password_verify($apiKey, (string) $application['api_key_hash'])
        ) {
            throw new ApiException(401, 'Invalid application credentials.');
        }

        return [
            'id' => (int) $application['id'],
            'application_code' => (string) $application['application_code'],
        ];
    }
}
