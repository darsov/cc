-- Выполнить вручную в базе omniweb через рабочий PDO КЦ до передачи настроек из OMNI.
CREATE TABLE IF NOT EXISTS `cc_mindbox_settings` (
    `brand` VARCHAR(20) NOT NULL,
    `endpoint_id` VARCHAR(255) NOT NULL,
    `secret_encrypted` TEXT NOT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`brand`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
