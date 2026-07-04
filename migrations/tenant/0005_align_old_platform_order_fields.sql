-- ============================================================================
-- Align platform order fields with /old ph_order* tables.
-- ============================================================================

ALTER TABLE `orders`
    ADD COLUMN IF NOT EXISTS `pay_charge` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'PayCharge 手续费' AFTER `postage_price`;

ALTER TABLE `order_items`
    ADD COLUMN IF NOT EXISTS `order_detail_id` VARCHAR(128) NOT NULL DEFAULT '' COMMENT 'orderDetailId' AFTER `order_id`,
    ADD COLUMN IF NOT EXISTS `line_id` VARCHAR(128) NOT NULL DEFAULT '' COMMENT 'LineId' AFTER `order_detail_id`,
    ADD COLUMN IF NOT EXISTS `lot_number` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'lotnumber' AFTER `item_code`,
    ADD COLUMN IF NOT EXISTS `item_management_id` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'ItemManagerId/itemManagementId' AFTER `lot_number`,
    ADD COLUMN IF NOT EXISTS `unit_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'UnitPrice/itemPrice' AFTER `material`,
    ADD COLUMN IF NOT EXISTS `postage_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'postagePrice/ShipCharge' AFTER `unit_price`,
    ADD COLUMN IF NOT EXISTS `pay_charge` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'PayCharge' AFTER `postage_price`,
    ADD COLUMN IF NOT EXISTS `line_total` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'totalItemPrice/TotalPrice' AFTER `pay_charge`,
    ADD COLUMN IF NOT EXISTS `item_comment` VARCHAR(1024) NOT NULL DEFAULT '' COMMENT 'comment' AFTER `amount`;
