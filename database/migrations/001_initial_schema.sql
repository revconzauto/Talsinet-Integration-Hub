CREATE DATABASE IF NOT EXISTS talsinet_hub
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE talsinet_hub;


-- =========================================================
-- APPLICATIONS
-- =========================================================

CREATE TABLE IF NOT EXISTS applications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    application_code VARCHAR(50) NOT NULL UNIQUE,

    name VARCHAR(150) NOT NULL,

    description TEXT NULL,

    api_key_hash VARCHAR(255) NOT NULL,

    status ENUM(
        'active',
        'suspended',
        'revoked'
    ) NOT NULL DEFAULT 'active',

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP
);


-- =========================================================
-- CUSTOMERS
-- =========================================================

CREATE TABLE IF NOT EXISTS customers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    application_id BIGINT UNSIGNED NOT NULL,

    external_reference VARCHAR(100) NOT NULL,

    phone_number VARCHAR(30) NOT NULL,

    first_name VARCHAR(100) NULL,

    last_name VARCHAR(100) NULL,

    email VARCHAR(190) NULL,

    status ENUM(
        'active',
        'inactive'
    ) NOT NULL DEFAULT 'active',

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_customer_application
        FOREIGN KEY (application_id)
        REFERENCES applications(id),

    UNIQUE KEY uq_application_customer_reference
        (application_id, external_reference),

    INDEX idx_customer_phone
        (phone_number)
);


-- =========================================================
-- SUBSCRIPTIONS
-- =========================================================

CREATE TABLE IF NOT EXISTS subscriptions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    application_id BIGINT UNSIGNED NOT NULL,

    customer_id BIGINT UNSIGNED NOT NULL,

    external_reference VARCHAR(100) NOT NULL,

    plan_code VARCHAR(50) NOT NULL,

    amount DECIMAL(15,2) NOT NULL,

    currency CHAR(3) NOT NULL DEFAULT 'SZL',

    billing_period ENUM(
        'monthly',
        'annual',
        'once'
    ) NOT NULL DEFAULT 'annual',

    status ENUM(
        'pending',
        'active',
        'past_due',
        'cancelled',
        'expired'
    ) NOT NULL DEFAULT 'pending',

    started_at DATETIME NULL,

    expires_at DATETIME NULL,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_subscription_application
        FOREIGN KEY (application_id)
        REFERENCES applications(id),

    CONSTRAINT fk_subscription_customer
        FOREIGN KEY (customer_id)
        REFERENCES customers(id),

    UNIQUE KEY uq_application_subscription_reference
        (application_id, external_reference),

    INDEX idx_subscription_customer
        (customer_id),

    INDEX idx_subscription_status
        (status)
);


-- =========================================================
-- PAYMENTS
-- =========================================================

CREATE TABLE IF NOT EXISTS payments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    application_id BIGINT UNSIGNED NOT NULL,

    customer_id BIGINT UNSIGNED NULL,

    subscription_id BIGINT UNSIGNED NULL,

    internal_reference VARCHAR(100) NOT NULL UNIQUE,

    external_reference VARCHAR(100) NULL,

    provider_reference VARCHAR(150) NULL,

    provider VARCHAR(50) NOT NULL,

    payment_method VARCHAR(50) NOT NULL,

    purpose VARCHAR(50) NULL,

    amount DECIMAL(15,2) NOT NULL,

    currency CHAR(3) NOT NULL DEFAULT 'SZL',

    status ENUM(
        'initiated',
        'pending',
        'successful',
        'failed',
        'cancelled',
        'expired',
        'reversed'
    ) NOT NULL DEFAULT 'initiated',

    description VARCHAR(255) NULL,

    provider_response JSON NULL,

    initiated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    completed_at DATETIME NULL,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_payment_application
        FOREIGN KEY (application_id)
        REFERENCES applications(id),

    CONSTRAINT fk_payment_customer
        FOREIGN KEY (customer_id)
        REFERENCES customers(id),

    CONSTRAINT fk_payment_subscription
        FOREIGN KEY (subscription_id)
        REFERENCES subscriptions(id),

    INDEX idx_payment_status
        (status),

    INDEX idx_payment_provider_reference
        (provider_reference),

    INDEX idx_payment_external_reference
        (external_reference)
);


-- =========================================================
-- WEBHOOK EVENTS
-- =========================================================

CREATE TABLE IF NOT EXISTS webhook_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    provider VARCHAR(50) NOT NULL,

    event_type VARCHAR(100) NULL,

    external_event_id VARCHAR(150) NULL,

    payload JSON NOT NULL,

    signature_valid BOOLEAN NOT NULL DEFAULT FALSE,

    processed BOOLEAN NOT NULL DEFAULT FALSE,

    processing_error TEXT NULL,

    received_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    processed_at DATETIME NULL,

    UNIQUE KEY uq_webhook_event
        (provider, external_event_id),

    INDEX idx_webhook_processed
        (processed)
);


-- =========================================================
-- IDEMPOTENCY
-- =========================================================

CREATE TABLE IF NOT EXISTS idempotency_keys (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    application_id BIGINT UNSIGNED NOT NULL,

    idempotency_key VARCHAR(150) NOT NULL,

    request_hash CHAR(64) NOT NULL,

    response_status SMALLINT NULL,

    response_body JSON NULL,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_application_idempotency
        (application_id, idempotency_key),

    CONSTRAINT fk_idempotency_application
        FOREIGN KEY (application_id)
        REFERENCES applications(id)
);


-- =========================================================
-- AUDIT LOG
-- =========================================================

CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    application_id BIGINT UNSIGNED NULL,

    action VARCHAR(100) NOT NULL,

    resource_type VARCHAR(100) NULL,

    resource_id VARCHAR(100) NULL,

    request_id VARCHAR(100) NULL,

    ip_address VARCHAR(45) NULL,

    details JSON NULL,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_audit_application
        FOREIGN KEY (application_id)
        REFERENCES applications(id),

    INDEX idx_audit_request
        (request_id),

    INDEX idx_audit_resource
        (resource_type, resource_id)
);