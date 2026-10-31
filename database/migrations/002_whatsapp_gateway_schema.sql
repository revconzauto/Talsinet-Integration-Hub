-- WhatsApp gateway tables for the existing talsinet_hub database.
-- Select the target database before running this migration.

CREATE TABLE IF NOT EXISTS meta_business_portfolios (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    meta_business_id VARCHAR(64) NOT NULL,
    name VARCHAR(191) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_meta_business_portfolio_id (meta_business_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS whatsapp_business_accounts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    portfolio_id BIGINT UNSIGNED NULL,
    meta_waba_id VARCHAR(64) NOT NULL,
    name VARCHAR(191) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_whatsapp_business_account_meta_id (meta_waba_id),
    KEY idx_whatsapp_business_account_portfolio (portfolio_id),

    CONSTRAINT fk_whatsapp_business_account_portfolio
        FOREIGN KEY (portfolio_id)
        REFERENCES meta_business_portfolios(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS phone_numbers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    business_account_id BIGINT UNSIGNED NOT NULL,
    meta_phone_number_id VARCHAR(64) NOT NULL,
    display_phone_number VARCHAR(32) NOT NULL,
    verified_name VARCHAR(191) NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_whatsapp_phone_meta_id (meta_phone_number_id),
    KEY idx_whatsapp_phone_business_account (business_account_id),

    CONSTRAINT fk_whatsapp_phone_business_account
        FOREIGN KEY (business_account_id)
        REFERENCES whatsapp_business_accounts(id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS application_channels (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    application_id BIGINT UNSIGNED NOT NULL,
    phone_number_id BIGINT UNSIGNED NOT NULL,
    status ENUM('active', 'suspended') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_application_whatsapp_channel
        (application_id, phone_number_id),
    KEY idx_application_channel_phone_number (phone_number_id),

    CONSTRAINT fk_application_channel_application
        FOREIGN KEY (application_id)
        REFERENCES applications(id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_application_channel_phone_number
        FOREIGN KEY (phone_number_id)
        REFERENCES phone_numbers(id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS whatsapp_conversations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    application_channel_id BIGINT UNSIGNED NOT NULL,
    contact_wa_id VARCHAR(64) NOT NULL,
    contact_phone_number VARCHAR(32) NULL,
    status ENUM('open', 'closed') NOT NULL DEFAULT 'open',
    last_message_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_whatsapp_conversation_contact
        (application_channel_id, contact_wa_id),
    KEY idx_whatsapp_conversation_status (status),
    KEY idx_whatsapp_conversation_last_message (last_message_at),

    CONSTRAINT fk_whatsapp_conversation_channel
        FOREIGN KEY (application_channel_id)
        REFERENCES application_channels(id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS whatsapp_webhook_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payload_sha256 CHAR(64) NOT NULL,
    event_type VARCHAR(100) NULL,
    payload JSON NOT NULL,
    signature_valid BOOLEAN NOT NULL DEFAULT FALSE,
    processing_status ENUM('received', 'processed', 'failed')
        NOT NULL DEFAULT 'received',
    processing_error TEXT NULL,
    received_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME NULL,

    UNIQUE KEY uq_whatsapp_webhook_payload_hash (payload_sha256),
    KEY idx_whatsapp_webhook_processing (processing_status, received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS whatsapp_messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversation_id BIGINT UNSIGNED NOT NULL,
    source_webhook_event_id BIGINT UNSIGNED NULL,
    meta_message_id VARCHAR(191) NULL,
    context_meta_message_id VARCHAR(191) NULL,
    direction ENUM('inbound', 'outbound') NOT NULL,
    message_type VARCHAR(32) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'received',
    message_content JSON NOT NULL,
    sent_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_whatsapp_message_meta_id (meta_message_id),
    KEY idx_whatsapp_message_conversation_time (conversation_id, created_at),
    KEY idx_whatsapp_message_status_time (status, created_at),
    KEY idx_whatsapp_message_source_event (source_webhook_event_id),

    CONSTRAINT fk_whatsapp_message_conversation
        FOREIGN KEY (conversation_id)
        REFERENCES whatsapp_conversations(id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_whatsapp_message_source_event
        FOREIGN KEY (source_webhook_event_id)
        REFERENCES whatsapp_webhook_events(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS whatsapp_message_statuses (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message_id BIGINT UNSIGNED NOT NULL,
    webhook_event_id BIGINT UNSIGNED NULL,
    status VARCHAR(32) NOT NULL,
    recipient_wa_id VARCHAR(64) NULL,
    event_at DATETIME NULL,
    errors JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_whatsapp_message_status_message_time (message_id, event_at),
    KEY idx_whatsapp_message_status_event (webhook_event_id),

    CONSTRAINT fk_whatsapp_message_status_message
        FOREIGN KEY (message_id)
        REFERENCES whatsapp_messages(id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_whatsapp_message_status_event
        FOREIGN KEY (webhook_event_id)
        REFERENCES whatsapp_webhook_events(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS whatsapp_message_templates (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    business_account_id BIGINT UNSIGNED NOT NULL,
    meta_template_id VARCHAR(180) NULL,
    name VARCHAR(100) NOT NULL,
    language VARCHAR(10) NOT NULL,
    category VARCHAR(32) NULL,
    status VARCHAR(32) NOT NULL,
    components JSON NULL,
    last_synced_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_whatsapp_template_name_language
        (business_account_id, name, language),
    UNIQUE KEY uq_whatsapp_template_meta_id
        (business_account_id, meta_template_id),

    CONSTRAINT fk_whatsapp_template_business_account
        FOREIGN KEY (business_account_id)
        REFERENCES whatsapp_business_accounts(id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
