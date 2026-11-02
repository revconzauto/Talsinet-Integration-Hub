<?php

declare(strict_types=1);

use Talsinet\IntegrationHub\Database\Database;
use Talsinet\IntegrationHub\Providers\Meta\MetaGraphClient;
use Talsinet\IntegrationHub\Services\WhatsApp\WhatsAppConfig;

require_once dirname(__DIR__) . '/src/bootstrap.php';

try {
    $config = new WhatsAppConfig();
    $config->require('META_APP_ID');
    $config->require('META_APP_SECRET');
    $config->require('META_WEBHOOK_VERIFY_TOKEN');
    $config->graphApiVersion();
    $config->require('WHATSAPP_ACCESS_TOKEN');
    $wabaId = $config->require('WHATSAPP_WABA_ID');

    if (!ctype_digit($wabaId)) {
        throw new RuntimeException('WHATSAPP_WABA_ID must contain only digits.');
    }

    $database = Database::connection();
    $phoneNumbers = (new MetaGraphClient($config))->listPhoneNumbers($wabaId);
    $registeredPhones = [];

    foreach ($phoneNumbers as $phone) {
        if (is_array($phone) && isset($phone['id']) && is_string($phone['id'])) {
            $registeredPhones[$phone['id']] = $phone;
        }
    }

    $database->beginTransaction();
    $wabaInsert = $database->prepare(
        'INSERT INTO whatsapp_business_accounts (meta_waba_id, name, status)
         VALUES (:waba_id, \'Talsinet WhatsApp Business Account\', \'active\')
         ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
    );
    $wabaInsert->execute(['waba_id' => $wabaId]);
    $wabaIdInternal = (int) $database->lastInsertId();

    if ($wabaIdInternal === 0) {
        $wabaLookup = $database->prepare(
            'SELECT id FROM whatsapp_business_accounts WHERE meta_waba_id = :waba_id'
        );
        $wabaLookup->execute(['waba_id' => $wabaId]);
        $wabaIdInternal = (int) $wabaLookup->fetchColumn();
    }

    if ($wabaIdInternal === 0) {
        throw new RuntimeException('Unable to resolve the WhatsApp Business Account record.');
    }

    $wabaStatus = $database->prepare(
        'SELECT status FROM whatsapp_business_accounts WHERE id = :id'
    );
    $wabaStatus->execute(['id' => $wabaIdInternal]);

    if ($wabaStatus->fetchColumn() !== 'active') {
        throw new RuntimeException('The configured WhatsApp Business Account is not active in the Hub.');
    }

    $keys = [];
    $configuredPhoneIds = [];

    foreach ($config->applications() as $channelCode => $applicationConfig) {
        $apiKey = $config->require($applicationConfig['api_key']);
        $phoneNumberId = $config->require($applicationConfig['phone_key']);

        if (strlen($apiKey) < 32) {
            throw new RuntimeException(sprintf(
                '%s must contain at least 32 characters.',
                $applicationConfig['api_key']
            ));
        }

        if (!ctype_digit($phoneNumberId)) {
            throw new RuntimeException(sprintf(
                '%s must contain only digits.',
                $applicationConfig['phone_key']
            ));
        }

        if (!isset($registeredPhones[$phoneNumberId])) {
            throw new RuntimeException(sprintf(
                'Phone number ID %s is not registered under the configured WABA.',
                $phoneNumberId
            ));
        }

        if (in_array($phoneNumberId, $configuredPhoneIds, true)) {
            throw new RuntimeException('Each application must use a distinct Meta phone number ID.');
        }
        $configuredPhoneIds[] = $phoneNumberId;

        if (in_array($apiKey, $keys, true)) {
            throw new RuntimeException('Each application must use a distinct API key.');
        }
        $keys[] = $apiKey;

        $applicationInsert = $database->prepare(
            'INSERT INTO applications (
                application_code, name, description, api_key_hash, status
             ) VALUES (
                :application_code, :name, :description, :api_key_hash, \'active\'
             )
             ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                description = VALUES(description),
                api_key_hash = VALUES(api_key_hash)'
        );
        $applicationInsert->execute([
            'application_code' => $applicationConfig['application_code'],
            'name' => $applicationConfig['name'],
            'description' => 'WhatsApp messaging channel ' . $channelCode,
            'api_key_hash' => password_hash($apiKey, PASSWORD_DEFAULT),
        ]);
        $applicationLookup = $database->prepare(
            'SELECT id FROM applications WHERE application_code = :application_code'
        );
        $applicationLookup->execute([
            'application_code' => $applicationConfig['application_code'],
        ]);
        $applicationId = (int) $applicationLookup->fetchColumn();
        $applicationStatus = $database->prepare(
            'SELECT status FROM applications WHERE id = :id'
        );
        $applicationStatus->execute(['id' => $applicationId]);

        if ($applicationStatus->fetchColumn() !== 'active') {
            throw new RuntimeException(sprintf(
                'Application %s is not active in the Hub.',
                $applicationConfig['application_code']
            ));
        }

        $existingPhone = $database->prepare(
            'SELECT id, business_account_id, status
             FROM phone_numbers
             WHERE meta_phone_number_id = :phone_number_id'
        );
        $existingPhone->execute(['phone_number_id' => $phoneNumberId]);
        $phone = $existingPhone->fetch();

        if (is_array($phone) && (int) $phone['business_account_id'] !== $wabaIdInternal) {
            throw new RuntimeException(sprintf(
                'Phone number ID %s is already assigned to a different WABA.',
                $phoneNumberId
            ));
        }

        if (is_array($phone) && $phone['status'] !== 'active') {
            throw new RuntimeException(sprintf(
                'Phone number ID %s is suspended in the Hub.',
                $phoneNumberId
            ));
        }

        $phoneMetadata = $registeredPhones[$phoneNumberId];
        $phoneInsert = $database->prepare(
            'INSERT INTO phone_numbers (
                business_account_id, meta_phone_number_id, display_phone_number, verified_name, status
             ) VALUES (
                :waba_id, :phone_number_id, :display_phone_number, :verified_name, \'active\'
             )
             ON DUPLICATE KEY UPDATE
                id = LAST_INSERT_ID(id),
                display_phone_number = VALUES(display_phone_number),
                verified_name = VALUES(verified_name)'
        );
        $phoneInsert->execute([
            'waba_id' => $wabaIdInternal,
            'phone_number_id' => $phoneNumberId,
            'display_phone_number' => (string) ($phoneMetadata['display_phone_number'] ?? ''),
            'verified_name' => $phoneMetadata['verified_name'] ?? null,
        ]);
        $phoneLookup = $database->prepare(
            'SELECT id FROM phone_numbers WHERE meta_phone_number_id = :phone_number_id'
        );
        $phoneLookup->execute(['phone_number_id' => $phoneNumberId]);
        $phoneInternalId = (int) $phoneLookup->fetchColumn();

        $suspendChannels = $database->prepare(
            'UPDATE application_channels
             SET status = \'suspended\'
             WHERE application_id = :application_id
               AND phone_number_id <> :phone_number_id'
        );
        $suspendChannels->execute([
            'application_id' => $applicationId,
            'phone_number_id' => $phoneInternalId,
        ]);
        $channelInsert = $database->prepare(
            'INSERT INTO application_channels (application_id, phone_number_id, status)
             VALUES (:application_id, :phone_number_id, \'active\')
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
        );
        $channelInsert->execute([
            'application_id' => $applicationId,
            'phone_number_id' => $phoneInternalId,
        ]);

        $channelStatus = $database->prepare(
            'SELECT status FROM application_channels
             WHERE application_id = :application_id
               AND phone_number_id = :phone_number_id'
        );
        $channelStatus->execute([
            'application_id' => $applicationId,
            'phone_number_id' => $phoneInternalId,
        ]);

        if ($channelStatus->fetchColumn() !== 'active') {
            throw new RuntimeException(sprintf(
                'Channel %s is suspended in the Hub.',
                $channelCode
            ));
        }
    }

    $database->commit();
    fwrite(STDOUT, "WhatsApp applications and channels provisioned successfully.\n");
} catch (Throwable $exception) {
    if (isset($database) && $database->inTransaction()) {
        $database->rollBack();
    }

    fwrite(STDERR, 'WhatsApp provisioning failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
