-- Выполнить администратором MariaDB в базе omniweb до выкладки PHP-файлов.
-- Пользователь приложения должен иметь SELECT/INSERT/UPDATE для этих двух таблиц.

USE `omniweb`;

CREATE TABLE IF NOT EXISTS `cc_users` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `omni_user_id` BIGINT UNSIGNED NOT NULL,
    `email` VARCHAR(320) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `department_name` VARCHAR(120) NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `failed_login_attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `locked_until` DATETIME NULL,
    `synced_at` DATETIME NOT NULL,
    `last_login_at` DATETIME NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_cc_users_omni_user` (`omni_user_id`),
    UNIQUE KEY `uq_cc_users_email` (`email`),
    KEY `idx_cc_users_active` (`is_active`, `email`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cc_card_refund_queue` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `source_key` CHAR(64) NOT NULL,
    `payload_hash` CHAR(64) NOT NULL,
    `payload_encrypted` LONGTEXT NULL,
    `queue_status` ENUM('pending', 'exported') NOT NULL DEFAULT 'pending',
    `submitted_by_user_id` BIGINT UNSIGNED NULL,
    `submitted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `exported_at` DATETIME NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_cc_card_refund_source` (`source_key`),
    KEY `idx_cc_card_refund_queue` (`queue_status`, `id`),
    KEY `idx_cc_card_refund_submitter` (`submitted_by_user_id`),
    CONSTRAINT `fk_cc_card_refund_submitter`
        FOREIGN KEY (`submitted_by_user_id`) REFERENCES `cc_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
