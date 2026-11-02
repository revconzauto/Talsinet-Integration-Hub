<?php

declare(strict_types=1);

namespace Talsinet\IntegrationHub\Services\WhatsApp;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Talsinet\IntegrationHub\Providers\SecurePhone\SecurePhoneClient;
use Throwable;

final class WhatsAppWebhookProcessor
{
    public function __construct(
        private PDO $database,
        private SecurePhoneClient $securePhone
    ) {
    }

    public function processPending(int $limit = 100): array
    {
        $statement = $this->database->prepare(
            'SELECT id
             FROM whatsapp_webhook_events
             WHERE processing_status IN (\'received\', \'failed\')
             ORDER BY processing_status, received_at, id
             LIMIT :event_limit'
        );
        $statement->bindValue(':event_limit', max(1, min($limit, 500)), PDO::PARAM_INT);
        $statement->execute();

        $result = ['processed' => 0, 'failed' => 0];

        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $eventId) {
            try {
                if ($this->processEvent((int) $eventId)) {
                    $result['processed']++;
                }
            } catch (Throwable $exception) {
                error_log(sprintf(
                    'WhatsApp webhook event %d processing failed: %s',
                    (int) $eventId,
                    $exception->getMessage()
                ));
                $result['failed']++;
            }
        }

        return $result;
    }

    private function processEvent(int $eventId): bool
    {
        $this->database->beginTransaction();

        try {
            $statement = $this->database->prepare(
                'SELECT payload, processing_status
                 FROM whatsapp_webhook_events
                 WHERE id = :id
                 FOR UPDATE'
            );
            $statement->execute(['id' => $eventId]);
            $event = $statement->fetch();

            if (!is_array($event) || $event['processing_status'] === 'processed') {
                $this->database->commit();
                return false;
            }

            $payload = json_decode((string) $event['payload'], true, 512, JSON_THROW_ON_ERROR);

            if (!is_array($payload)) {
                throw new RuntimeException('Stored webhook payload is invalid.');
            }

            $this->processPayload($payload, $eventId);

            $update = $this->database->prepare(
                'UPDATE whatsapp_webhook_events
                 SET processing_status = \'processed\',
                     processing_error = NULL,
                     processed_at = UTC_TIMESTAMP()
                 WHERE id = :id'
            );
            $update->execute(['id' => $eventId]);
            $this->database->commit();

            return true;
        } catch (Throwable $exception) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }

            $failed = $this->database->prepare(
                'UPDATE whatsapp_webhook_events
                 SET processing_status = \'failed\',
                     processing_error = :error
                 WHERE id = :id'
            );
            $failed->execute([
                'error' => substr($exception->getMessage(), 0, 4000),
                'id' => $eventId,
            ]);

            throw $exception;
        }
    }

    private function processPayload(array $payload, int $eventId): void
    {
        if (($payload['object'] ?? null) !== 'whatsapp_business_account') {
            throw new RuntimeException('Webhook payload is not a WhatsApp Business Account event.');
        }

        foreach (($payload['entry'] ?? []) as $entry) {
            foreach (($entry['changes'] ?? []) as $change) {
                $value = $change['value'] ?? [];
                $phoneNumberId = $value['metadata']['phone_number_id'] ?? null;

                if (!is_string($phoneNumberId) || $phoneNumberId === '') {
                    throw new RuntimeException('Webhook event does not identify a phone number.');
                }

                $channel = $this->findActiveChannel($phoneNumberId);

                if ($channel === null) {
                    throw new RuntimeException('Webhook phone number is not assigned to an active application channel.');
                }

                foreach (($value['messages'] ?? []) as $message) {
                    if (!is_array($message)) {
                        throw new RuntimeException('Webhook contains an invalid inbound message.');
                    }

                    $this->storeInboundMessage((int) $channel['id'], $message, $eventId);

                    if ($channel['application_code'] === 'SECUREPHONE') {
                        if ($this->isSupportedSecurePhoneType($message['type'] ?? null)) {
                            $this->securePhone->forwardWhatsAppMessage($message);
                        } else {
                            error_log(sprintf(
                                'SecurePhone message %s has unsupported type %s; it was stored but not forwarded.',
                                (string) ($message['id'] ?? 'unknown'),
                                (string) ($message['type'] ?? 'unknown')
                            ));
                        }
                    }
                }

                foreach (($value['statuses'] ?? []) as $status) {
                    $this->storeMessageStatus($status, $eventId);
                }
            }
        }
    }

    private function findActiveChannel(string $phoneNumberId): ?array
    {
        $statement = $this->database->prepare(
            'SELECT ac.id, a.application_code
             FROM application_channels ac
             INNER JOIN applications a ON a.id = ac.application_id
             INNER JOIN phone_numbers pn ON pn.id = ac.phone_number_id
             INNER JOIN whatsapp_business_accounts waba ON waba.id = pn.business_account_id
             WHERE pn.meta_phone_number_id = :phone_number_id
               AND ac.status = \'active\'
               AND a.status = \'active\'
               AND pn.status = \'active\'
               AND waba.status = \'active\'
             LIMIT 1'
        );
        $statement->execute(['phone_number_id' => $phoneNumberId]);
        $channel = $statement->fetch();

        return is_array($channel)
            ? [
                'id' => (int) $channel['id'],
                'application_code' => (string) $channel['application_code'],
            ]
            : null;
    }

    private function isSupportedSecurePhoneType(mixed $type): bool
    {
        return in_array($type, ['text', 'image', 'document', 'documents'], true);
    }

    private function storeInboundMessage(int $channelId, array $message, int $eventId): void
    {
        $sender = $message['from'] ?? null;
        $messageId = $message['id'] ?? null;
        $messageType = $message['type'] ?? null;

        if (
            !is_string($sender)
            || !is_string($messageId)
            || !is_string($messageType)
            || $sender === ''
            || $messageId === ''
        ) {
            throw new RuntimeException('Inbound message is missing its sender, message ID, or type.');
        }

        $messageAt = $this->timestamp($message['timestamp'] ?? null);
        $conversationId = $this->upsertConversation($channelId, $sender, $messageAt);
        $statement = $this->database->prepare(
            'INSERT INTO whatsapp_messages (
                conversation_id, source_webhook_event_id, meta_message_id,
                context_meta_message_id, direction, message_type, status,
                message_content, sent_at
             ) VALUES (
                :conversation_id, :event_id, :message_id, :context_message_id,
                \'inbound\', :message_type, \'received\', :content, :sent_at
             )
             ON DUPLICATE KEY UPDATE meta_message_id = VALUES(meta_message_id)'
        );
        $statement->execute([
            'conversation_id' => $conversationId,
            'event_id' => $eventId,
            'message_id' => $messageId,
            'context_message_id' => $message['context']['id'] ?? null,
            'message_type' => substr($messageType, 0, 32),
            'content' => $this->encode($message),
            'sent_at' => $messageAt,
        ]);
    }

    private function storeMessageStatus(array $status, int $eventId): void
    {
        $metaMessageId = $status['id'] ?? null;
        $statusName = $status['status'] ?? null;

        if (!is_string($metaMessageId) || !is_string($statusName) || $metaMessageId === '') {
            throw new RuntimeException('Message status is missing its message ID or status.');
        }

        if (!in_array($statusName, ['sent', 'delivered', 'read', 'failed'], true)) {
            throw new RuntimeException('Webhook contains an unsupported message status.');
        }

        $lookup = $this->database->prepare(
            'SELECT id FROM whatsapp_messages WHERE meta_message_id = :message_id LIMIT 1'
        );
        $lookup->execute(['message_id' => $metaMessageId]);
        $messageId = $lookup->fetchColumn();

        if ($messageId === false) {
            return;
        }

        $eventAt = $this->timestamp($status['timestamp'] ?? null);
        $errors = isset($status['errors']) ? $this->encode($status['errors']) : null;
        $insert = $this->database->prepare(
            'INSERT INTO whatsapp_message_statuses (
                message_id, webhook_event_id, status, recipient_wa_id, event_at, errors
             ) VALUES (
                :message_id, :event_id, :status, :recipient, :event_at, :errors
             )'
        );
        $insert->execute([
            'message_id' => (int) $messageId,
            'event_id' => $eventId,
            'status' => $statusName,
            'recipient' => $status['recipient_id'] ?? null,
            'event_at' => $eventAt,
            'errors' => $errors,
        ]);

        $latest = $this->database->prepare(
            'SELECT status
             FROM whatsapp_message_statuses
             WHERE message_id = :message_id
             ORDER BY event_at DESC, id DESC
             LIMIT 1'
        );
        $latest->execute(['message_id' => (int) $messageId]);
        $latestStatus = $latest->fetchColumn();

        if (is_string($latestStatus)) {
            $update = $this->database->prepare(
                'UPDATE whatsapp_messages SET status = :status WHERE id = :id'
            );
            $update->execute(['status' => $latestStatus, 'id' => (int) $messageId]);
        }
    }

    private function upsertConversation(int $channelId, string $contactWaId, ?string $messageAt): int
    {
        $statement = $this->database->prepare(
            'INSERT INTO whatsapp_conversations (
                application_channel_id, contact_wa_id, contact_phone_number, last_message_at
             ) VALUES (
                :channel_id, :contact_wa_id, :contact_phone_number, :last_message_at
             )
             ON DUPLICATE KEY UPDATE
                id = LAST_INSERT_ID(id),
                contact_phone_number = VALUES(contact_phone_number),
                last_message_at = CASE
                    WHEN VALUES(last_message_at) IS NULL THEN last_message_at
                    WHEN last_message_at IS NULL OR last_message_at < VALUES(last_message_at)
                        THEN VALUES(last_message_at)
                    ELSE last_message_at
                END'
        );
        $statement->execute([
            'channel_id' => $channelId,
            'contact_wa_id' => $contactWaId,
            'contact_phone_number' => $contactWaId,
            'last_message_at' => $messageAt,
        ]);

        return (int) $this->database->lastInsertId();
    }

    private function timestamp(mixed $value): ?string
    {
        if (!is_string($value) || !ctype_digit($value)) {
            return null;
        }

        return (new DateTimeImmutable('@' . $value))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }

    private function encode(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
