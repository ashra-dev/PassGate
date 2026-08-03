-- PassGate Pro – PostgreSQL schema
-- Run: createdb passgate && psql -d passgate -f schema.sql

CREATE TABLE IF NOT EXISTS distributors (
    id          VARCHAR(50)  PRIMARY KEY,
    name        VARCHAR(255) NOT NULL,
    email       VARCHAR(255) NOT NULL UNIQUE,
    role        VARCHAR(50)  NOT NULL DEFAULT 'distributor',
    created_at  TIMESTAMPTZ  NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS events (
    id          SERIAL       PRIMARY KEY,
    name        VARCHAR(255) NOT NULL,
    created_at  TIMESTAMPTZ  NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS tiers (
    id          SERIAL         PRIMARY KEY,
    event_id    INTEGER        NOT NULL REFERENCES events(id) ON DELETE CASCADE,
    name        VARCHAR(255)   NOT NULL,
    price       DECIMAL(10, 2) NOT NULL DEFAULT 0,
    quantity    INTEGER        NOT NULL DEFAULT 0,
    UNIQUE (event_id, name)
);

CREATE TABLE IF NOT EXISTS benefits (
    id          SERIAL       PRIMARY KEY,
    tier_id     INTEGER      NOT NULL REFERENCES tiers(id) ON DELETE CASCADE,
    name        VARCHAR(255) NOT NULL,
    max_uses    INTEGER      NOT NULL DEFAULT 1 CHECK (max_uses > 0),
    UNIQUE (tier_id, name)
);

CREATE TABLE IF NOT EXISTS customers (
    id            SERIAL       PRIMARY KEY,
    email         VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    name          VARCHAR(255) DEFAULT '',
    created_at    TIMESTAMPTZ  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMPTZ  NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS tickets (
    id                          VARCHAR(100) PRIMARY KEY,
    physical_number             INTEGER      NOT NULL,
    event_id                    INTEGER      NOT NULL REFERENCES events(id) ON DELETE CASCADE,
    tier_id                     INTEGER      NOT NULL REFERENCES tiers(id) ON DELETE RESTRICT,
    status                      VARCHAR(50)  NOT NULL DEFAULT 'Active',
    allocated_distributor_id    VARCHAR(50)  REFERENCES distributors(id) ON DELETE SET NULL,
    allocated_distributor_name  VARCHAR(255) DEFAULT '',
    customer_id                 INTEGER      REFERENCES customers(id) ON DELETE SET NULL,
    purchased_at                TIMESTAMPTZ
);

CREATE TABLE IF NOT EXISTS customer_tickets (
    id           SERIAL       PRIMARY KEY,
    customer_id  INTEGER      NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
    ticket_id    VARCHAR(100) NOT NULL REFERENCES tickets(id) ON DELETE CASCADE,
    purchased_at TIMESTAMPTZ  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    payment_id   VARCHAR(255) DEFAULT '',
    UNIQUE (ticket_id)
);

CREATE TABLE IF NOT EXISTS stalls (
    id            SERIAL       PRIMARY KEY,
    name          VARCHAR(255) NOT NULL,
    email         VARCHAR(255) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at    TIMESTAMPTZ  NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS scans (
    id           SERIAL       PRIMARY KEY,
    ticket_id    VARCHAR(100) NOT NULL REFERENCES tickets(id) ON DELETE CASCADE,
    benefit_id   INTEGER      NOT NULL REFERENCES benefits(id) ON DELETE CASCADE,
    scanned_at   TIMESTAMPTZ  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    station_type VARCHAR(255) NOT NULL,
    stall_id     INTEGER      REFERENCES stalls(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS login_tokens (
    id              SERIAL       PRIMARY KEY,
    distributor_id  VARCHAR(50)  NOT NULL REFERENCES distributors(id) ON DELETE CASCADE,
    token           VARCHAR(64)  NOT NULL UNIQUE,
    expires_at      TIMESTAMPTZ  NOT NULL,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Indexes
CREATE INDEX IF NOT EXISTS idx_distributors_email      ON distributors (email);
CREATE INDEX IF NOT EXISTS idx_tiers_event_id          ON tiers (event_id);
CREATE INDEX IF NOT EXISTS idx_benefits_tier_id          ON benefits (tier_id);
CREATE INDEX IF NOT EXISTS idx_tickets_event_id        ON tickets (event_id);
CREATE INDEX IF NOT EXISTS idx_tickets_tier_id           ON tickets (tier_id);
CREATE INDEX IF NOT EXISTS idx_tickets_allocated_dist    ON tickets (allocated_distributor_id);
CREATE INDEX IF NOT EXISTS idx_scans_ticket_id         ON scans (ticket_id);
CREATE INDEX IF NOT EXISTS idx_scans_benefit_id          ON scans (benefit_id);
CREATE INDEX IF NOT EXISTS idx_scans_scanned_at          ON scans (scanned_at DESC);
CREATE INDEX IF NOT EXISTS idx_scans_stall_id            ON scans (stall_id);
CREATE INDEX IF NOT EXISTS idx_stalls_email              ON stalls (email);
CREATE INDEX IF NOT EXISTS idx_tickets_customer_id       ON tickets (customer_id);
CREATE INDEX IF NOT EXISTS idx_customers_email           ON customers (email);
CREATE INDEX IF NOT EXISTS idx_customer_tickets_customer ON customer_tickets (customer_id);
CREATE INDEX IF NOT EXISTS idx_customer_tickets_ticket   ON customer_tickets (ticket_id);
CREATE INDEX IF NOT EXISTS idx_login_tokens_token       ON login_tokens (token);
CREATE INDEX IF NOT EXISTS idx_login_tokens_expires_at   ON login_tokens (expires_at);
