-- Выполнить в MariaDB в базе omniweb до выкладки сайта.
-- Одна строка на номер карты; только наличие платёжных данных, без открытых ПДн.
USE `omniweb`;
CREATE TABLE IF NOT EXISTS `cc_refund_tickets` (
    `card_number` VARCHAR(100) NOT NULL,
    `request_number` VARCHAR(100) NOT NULL DEFAULT '',
    `client_contact_date` DATE NULL,
    `has_phone` TINYINT(1) NOT NULL DEFAULT 0,
    `has_email` TINYINT(1) NOT NULL DEFAULT 0,
    `has_full_name` TINYINT(1) NOT NULL DEFAULT 0,
    `has_bic` TINYINT(1) NOT NULL DEFAULT 0,
    `has_account` TINYINT(1) NOT NULL DEFAULT 0,
    `decision` VARCHAR(32) NOT NULL DEFAULT 'new',
    `decision_reason` TEXT NULL,
    `payment_status` VARCHAR(32) NOT NULL DEFAULT 'pending',
    `payment_denial_reason` TEXT NULL,
    `last_submitted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`card_number`),
    KEY `idx_cc_refund_tickets_date` (`client_contact_date`, `last_submitted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
