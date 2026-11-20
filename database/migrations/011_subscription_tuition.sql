-- =============================================================================
-- Migration 011: Subscription Monthly Tuition & Tuition Payment Status
-- Target Engine: MySQL 8.0+ / 8.4+ / PostgreSQL 15+
-- =============================================================================

ALTER TABLE subscriptions
    ADD COLUMN monthly_fee INT NOT NULL DEFAULT 0 AFTER status,
    ADD COLUMN tuition_status VARCHAR(30) NOT NULL DEFAULT 'unpaid' AFTER monthly_fee,
    ADD COLUMN tuition_due_date DATE DEFAULT NULL AFTER tuition_status,
    ADD COLUMN last_payment_date DATE DEFAULT NULL AFTER tuition_due_date,
    ADD COLUMN tuition_notes TEXT DEFAULT NULL AFTER last_payment_date;

CREATE INDEX idx_subscriptions_tuition_status ON subscriptions (tuition_status);
