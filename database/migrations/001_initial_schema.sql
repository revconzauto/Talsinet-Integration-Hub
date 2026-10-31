CREATE TABLE applications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    application_code VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    api_key_hash VARCHAR(255) NOT NULL,
    status ENUM('active', 'suspended', 'revoked') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE customers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    external_reference VARCHAR(100) NULL UNIQUE,
    first_name VARCHAR(100) NULL,
    last_name VARCHAR(100) NULL,
    phone_number VARCHAR(30) NOT NULL,
    email VARCHAR(190) NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_customers_phone (phone_number)
);

CREATE TABLE subscriptions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    application_id BIGINT UNSIGNED NOT NULL,
    customer_id BIGINT UNSIGNED NOT NULL,
    plan_code VARCHAR(50) NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'SZL',
    billing_period ENUM('monthly', 'annual', 'once') NOT NULL DEFAULT 'annual',
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

    CONSTRAINT fk_sub_application
        FOREIGN KEY (application_id)
        REFERENCES applications(id),

    CONSTRAINT fk_sub_customer
        FOREIGN KEY (customer_id)
        REFERENCES customers(id),

    INDEX idx_subscription_status (status),
    INDEX idx_subscription_customer (customer_id)
);

CREATE TABLE payments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    application_id BIGINT UNSIGNED NOT NULL,
    customer_id BIGINT UNSIGNED NULL,
    subscription_id BIGINT UNSIGNED NULL,

    internal_reference VARCHAR(100) NOT NULL UNIQUE,
    provider_reference VARCHAR(150) NULL,

    provider VARCHAR(50) NOT NULL,
    payment_method VARCHAR(50) NOT NULL,

    amount DECIMAL(15,2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'SZL',

    status ENUM(
        'initiated',
        'pending',
        'successful',
        'failed',
        'cancelled',
        'reversed'
    ) NOT NULL DEFAULT 'initiated',

    description VARCHAR(255) NULL,

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

    INDEX idx_payment_status (status),
    INDEX idx_payment_provider_reference (provider_reference),
    INDEX idx_payment_customer (customer_id)
);

CREATE TABLE webhook_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    provider VARCHAR(50) NOT NULL,
    event_type VARCHAR(100) NULL,

    external_event_id VARCHAR(150) NULL,

    payload JSON NOT NULL,

    signature_valid BOOLEAN NOT NULL DEFAULT FALSE,
    processed BOOLEAN NOT NULL DEFAULT FALSE,

    received_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME NULL,

    UNIQUE KEY uq_webhook_event (
        provider,
        external_event_id
    ),

    INDEX idx_webhook_processed (processed)
);

CREATE TABLE audit_logs (
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

    INDEX idx_audit_request (request_id),
    INDEX idx_audit_resource (resource_type, resource_id)
);

CREATE TABLE idempotency_keys (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    application_id BIGINT UNSIGNED NOT NULL,

    idempotency_key VARCHAR(150) NOT NULL,

    request_hash CHAR(64) NOT NULL,

    response_status SMALLINT NULL,
    response_body JSON NULL,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_application_idempotency (
        application_id,
        idempotency_key
    ),

    CONSTRAINT fk_idempotency_application
        FOREIGN KEY (application_id)
        REFERENCES applications(id)
);