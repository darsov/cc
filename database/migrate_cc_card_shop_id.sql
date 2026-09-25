-- Выполнить один раз в базе omniweb КЦ до обновления card_services.php.
-- Существующие значения организации и даты проверки сохраняются.
ALTER TABLE `cc_card_organizations`
    ADD COLUMN `shop_id` VARCHAR(100) NULL AFTER `organization_id`,
    ADD KEY `idx_cc_card_shop_id` (`shop_id`);
