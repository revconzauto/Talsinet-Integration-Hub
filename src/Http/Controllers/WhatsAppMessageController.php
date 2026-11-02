<?php

declare(strict_types=1);

namespace Talsinet\IntegrationHub\Http\Controllers;

use PDO;
use RuntimeException;
use Throwable;
use Talsinet\IntegrationHub\Http\ApiException;
use Talsinet\IntegrationHub\Providers\Meta\MetaGraphClient;
use Talsinet\IntegrationHub\Services\WhatsApp\WhatsAppConfig;

final class WhatsAppMessageController
{
    public function __construct(
        private PDO $database,
        private WhatsAppConfig $config,
        private MetaGraphClient $meta
    ) {
    }

    public function send(array $application, array $input): array
    {
        $channelCode = $input['channel'] ?? null;
        $recipient = $input['to'] ?? null;
        $text = $input['text'] ?? null;

        if (!is_string($channelCode) || $channelCode === '') {
            throw new ApiException(422, 'The channel field is required.');
        }

        try {
            $channelConfig = $this->config->application($channelCode);
        } catch (RuntimeException) {
            throw new ApiException(422, 'Unknown WhatsApp channel.');
        }

        if ($channelConfig['application_code'] !== $application['application_code']) {
            throw new ApiException(403, 'This application cannot use the requested WhatsApp channel.');
        }

        if (
            !is_string($recipient)
            || !preg_match('/^\+?[1-9]\d{7,14}$/', $recipient)
        ) {
            throw new ApiException(422, 'The to field must be a valid international phone number.');
        }

        if (!is_string($text) || trim($text) === '' || mb_strlen($text) > 4096) {
            throw new ApiException(422, 'The text field must contain 1 to 4096 characters.');
        }

        $phoneNumberId = $this->config->require($channelConfig['phone_key']);
        $channel = $this->findApplicationChannel((int) $application['id'], $phoneNumberId);

        if ($channel === null) {
            throw new ApiException(503, 'The WhatsApp channel is not provisioned or active.');
        }

        $recipient = ltrim($recipient, '+');
        $content = json_encode(
            ['type' => 'text', 'text' => ['body' => $text]],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
        );
        $messageId = $this->createPendingMessage(
            (int) $channel['id'],
            $recipient,
            $content
        );

        try {
            $metaMessageId = $this->meta->sendText($phoneNumberId, $recipient, $text);
        } catch (RuntimeException $exception) {
            $this->markMessageFailed($messageId);
            error_log('WhatsApp outbound message failed: ' . $exception->getMessage());
            throw new ApiException(502, 'Meta could not accept the WhatsApp message.');
        }

        $update = $this->database->prepare(
            'UPDATE whatsapp_messages
             SET meta_message_id = :meta_message_id,
                 status = \'sent\',
                 sent_at = UTC_TIMESTAMP()
             WHERE id = :id'
        );
        $update->execute([
            'meta_message_id' => $metaMessageId,
            'id' => $messageId,
        ]);

        return [
            'status' => 'sent',
            'message_id' => $metaMessageId,
            'channel' => $channelCode,
        ];
    }

    public function inbox(array $application, int $afterId = 0, int $limit = 50): array
    {
        $statement = $this->database->prepare(
            'SELECT m.id, m.meta_message_id, m.message_type, m.status,
                    m.message_content, m.sent_at, m.created_at,
                    c.contact_wa_id, a.application_code
             FROM whatsapp_messages m
             INNER JOIN whatsapp_conversations c ON c.id = m.conversation_id
             INNER JOIN application_channels ac ON ac.id = c.application_channel_id
             INNER JOIN applications a ON a.id = ac.application_id
             WHERE ac.application_id = :application_id
               AND ac.status = \'active\'
               AND m.direction = \'inbound\'
               AND m.id > :after_id
             ORDER BY m.id
             LIMIT :message_limit'
        );
        $statement->bindValue(':application_id', (int) $application['id'], PDO::PARAM_INT);
        $statement->bindValue(':after_id', max(0, $afterId), PDO::PARAM_INT);
        $statement->bindValue(':message_limit', max(1, min($limit, 100)), PDO::PARAM_INT);
        $statement->execute();

        $channels = [];
        foreach ($this->config->applications() as $channelCode => $config) {
            $channels[$config['application_code']] = $channelCode;
        }

        $messages = [];
        foreach ($statement->fetchAll() as $row) {
            $messages[] = [
                'id' => (int) $row['id'],
                'meta_message_id' => $row['meta_message_id'],
                'channel' => $channels[$row['application_code']] ?? null,
                'from' => $row['contact_wa_id'],
                'type' => $row['message_type'],
                'status' => $row['status'],
                'content' => json_decode((string) $row['message_content'], true, 512, JSON_THROW_ON_ERROR),
                'received_at' => $row['sent_at'] ?? $row['created_at'],
            ];
        }

        return ['messages' => $messages];
    }

    private function findApplicationChannel(int $applicationId, string $phoneNumberId): ?array
    {
        $statement = $this->database->prepare(
            'SELECT ac.id
             FROM application_channels ac
             INNER JOIN phone_numbers pn ON pn.id = ac.phone_number_id
             INNER JOIN whatsapp_business_accounts waba ON waba.id = pn.business_account_id
             WHERE ac.application_id = :application_id
               AND pn.meta_phone_number_id = :phone_number_id
               AND ac.status = \'active\'
               AND pn.status = \'active\'
               AND waba.status = \'active\'
             LIMIT 1'
        );
        $statement->execute([
            'application_id' => $applicationId,
            'phone_number_id' => $phoneNumberId,
        ]);
        $channel = $statement->fetch();

        return is_array($channel) ? $channel : null;
    }

    private function createPendingMessage(int $channelId, string $recipient, string $content): int
    {
        $this->database->beginTransaction();

        try {
            $conversation = $this->database->prepare(
                'INSERT INTO whatsapp_conversations (
                    application_channel_id, contact_wa_id, contact_phone_number, last_message_at
                 ) VALUES (
                    :channel_id, :contact_wa_id, :contact_phone_number, UTC_TIMESTAMP()
                 )
                 ON DUPLICATE KEY UPDATE
                    id = LAST_INSERT_ID(id),
                    contact_phone_number = VALUES(contact_phone_number),
                    last_message_at = UTC_TIMESTAMP()'
            );
            $conversation->execute([
                'channel_id' => $channelId,
                'contact_wa_id' => $recipient,
                'contact_phone_number' => $recipient,
            ]);
            $conversationId = (int) $this->database->lastInsertId();
            $message = $this->database->prepare(
                'INSERT INTO whatsapp_messages (
                    conversation_id, direction, message_type, status, message_content
                 ) VALUES (
                    :conversation_id, \'outbound\', \'text\', \'pending\', :content
                 )'
            );
            $message->execute([
                'conversation_id' => $conversationId,
                'content' => $content,
            ]);
            $messageId = (int) $this->database->lastInsertId();
            $this->database->commit();

            return $messageId;
        } catch (Throwable $exception) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }

            throw $exception;
        }
    }

    private function markMessageFailed(int $messageId): void
    {
        $statement = $this->database->prepare(
            'UPDATE whatsapp_messages SET status = \'failed\' WHERE id = :id'
        );
        $statement->execute(['id' => $messageId]);
    }
}
