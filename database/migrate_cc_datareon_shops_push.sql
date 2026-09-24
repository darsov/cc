-- Выполнить один раз в базе omniweb до публикации datareon.php.
-- Существующие строки cc_shops и справочник организаций сохраняются.
ALTER TABLE `cc_shops`
    ADD COLUMN `shops_sap_id` VARCHAR(100) NULL,
    ADD COLUMN `shop_name` VARCHAR(500) NULL,
    ADD COLUMN `brand` VARCHAR(100) NULL,
    ADD COLUMN `address` TEXT NULL,
    ADD COLUMN `address_city` VARCHAR(255) NULL,
    ADD COLUMN `phone_number` VARCHAR(100) NULL,
    ADD COLUMN `openhours_json` JSON NULL,
    ADD COLUMN `source_updated_at` DATETIME(3) NULL,
    ADD COLUMN `payload_json` JSON NULL,
    ADD COLUMN `received_at` DATETIME(3) NULL,
    ADD KEY `idx_cc_shops_shop_id` (`shop_id`),
    ADD KEY `idx_cc_shops_source_updated` (`source_updated_at`);
