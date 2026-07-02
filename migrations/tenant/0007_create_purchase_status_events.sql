-- 采购状态结构化事件表
-- 对齐老系统 ph_caigou_record：记录进入国内采购准备/已采购等关键状态时的快照，
-- 用于任意历史日期的采购状态分布回溯。

CREATE TABLE IF NOT EXISTS `purchase_status_events` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `platform` VARCHAR(32) NOT NULL DEFAULT '' COMMENT '平台短码，如 y/r/w/m/q/yp',
    `order_id` BIGINT NOT NULL COMMENT 'SaaS orders.id',
    `order_item_id` BIGINT NOT NULL COMMENT 'SaaS order_items.id',
    `platform_order_id` VARCHAR(128) NOT NULL DEFAULT '' COMMENT '旧 ph_caigou_record.order_id，对应平台订单号',
    `item_code` VARCHAR(255) NOT NULL DEFAULT '',
    `store_name` VARCHAR(255) NOT NULL DEFAULT '',
    `operator` VARCHAR(128) NOT NULL DEFAULT '' COMMENT '操作人',
    `user_type` VARCHAR(64) NOT NULL DEFAULT '',
    `buyer` VARCHAR(128) NOT NULL DEFAULT '' COMMENT 'caigou_user',
    `action_type` VARCHAR(32) NOT NULL COMMENT 'enter_prepare/complete_purchase',
    `old_status` VARCHAR(64) NOT NULL DEFAULT '',
    `new_status` VARCHAR(64) NOT NULL DEFAULT '',
    `source` VARCHAR(128) NOT NULL DEFAULT '' COMMENT '状态变更来源',
    `tabaono` VARCHAR(128) NOT NULL DEFAULT '',
    `cn_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT '采购金额快照',
    `caigou_time` DATETIME NULL COMMENT '采购时间快照',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_date` DATE NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_purchase_status_events_date` (`created_date`),
    KEY `idx_purchase_status_events_platform_date` (`platform`, `created_date`),
    KEY `idx_purchase_status_events_item_date` (`order_item_id`, `created_at`),
    KEY `idx_purchase_status_events_action` (`action_type`),
    CONSTRAINT `fk_purchase_status_events_order`
        FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_purchase_status_events_item`
        FOREIGN KEY (`order_item_id`) REFERENCES `order_items` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='采购状态结构化事件审计';
