-- Execute once in CC's omniweb database before deploying the new bridge.
ALTER TABLE `cc_refund_tickets`
    ADD COLUMN `decision_reason` TEXT NULL AFTER `decision`,
    ADD COLUMN `payment_denial_reason` TEXT NULL AFTER `payment_status`;
