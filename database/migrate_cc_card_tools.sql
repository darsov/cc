-- Выполнить вручную в базе КЦ (omniweb) до выкладки новых PHP-файлов.
CREATE TABLE IF NOT EXISTS `cc_organizations` (
    `id` CHAR(36) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `cc_organizations` (`id`, `name`)
VALUES ('75fabd9c-421e-11f0-a6e2-a7bd3aad63e9', 'КАЛЦРУ ООО')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

CREATE TABLE IF NOT EXISTS `cc_shops` (
    `datareon_shop_id` VARCHAR(255) NOT NULL,
    `shop_id` VARCHAR(255) NULL,
    `organization_id` CHAR(36) NULL,
    `organization_name` VARCHAR(255) NULL,
    `synced_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`datareon_shop_id`), KEY `idx_cc_shops_organization` (`organization_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cc_card_organizations` (
    `card_number` VARCHAR(20) NOT NULL,
    `organization_id` CHAR(36) NULL,
    `checked_at` DATETIME NOT NULL,
    PRIMARY KEY (`card_number`), KEY `idx_cc_card_org_id` (`organization_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cc_user_actions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `action` VARCHAR(40) NOT NULL,
    `card_number` VARCHAR(20) NULL,
    `outcome` VARCHAR(120) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`), KEY `idx_cc_actions_user_date` (`user_id`, `created_at`),
    KEY `idx_cc_actions_card_date` (`card_number`, `created_at`),
    CONSTRAINT `fk_cc_actions_user` FOREIGN KEY (`user_id`) REFERENCES `cc_users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
