-- ============================================================================
-- 承接旧系统 ph_user / ph_userlevel / ph_img 的租户侧能力
-- ============================================================================

ALTER TABLE `stores`
    ADD COLUMN IF NOT EXISTS `legacy_dpid` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '旧系统 dpid',
    ADD COLUMN IF NOT EXISTS `api_config` JSON NULL COMMENT '店铺级 API 配置，承接 dpapi_config',
    ADD COLUMN IF NOT EXISTS `profit_deduction` DECIMAL(5,2) NOT NULL DEFAULT 70.00 COMMENT '店铺利润扣点百分比',
    ADD COLUMN IF NOT EXISTS `hidden_reason` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '隐藏店铺原因';

ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `display_name` VARCHAR(128) NOT NULL DEFAULT '' COMMENT '员工显示名',
    ADD COLUMN IF NOT EXISTS `preference_module` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '首选入口页',
    ADD COLUMN IF NOT EXISTS `api_1688_config` JSON NULL COMMENT '采购账号 1688 API 配置',
    ADD COLUMN IF NOT EXISTS `password_reset_at` DATETIME NULL COMMENT '最近重置密码时间',
    ADD COLUMN IF NOT EXISTS `last_login_at` DATETIME NULL COMMENT '最近登录时间';

CREATE TABLE IF NOT EXISTS `buyer_support_assignments` (
    `id`              BIGINT      NOT NULL AUTO_INCREMENT,
    `buyer_user_id`   BIGINT      NOT NULL COMMENT '采购/品检用户',
    `support_user_id` BIGINT      NOT NULL COMMENT '客服/店铺用户',
    `created_at`      DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_buyer_support` (`buyer_user_id`, `support_user_id`),
    KEY `idx_buyer_support_buyer` (`buyer_user_id`),
    KEY `idx_buyer_support_support` (`support_user_id`),
    CONSTRAINT `fk_buyer_support_buyer`
        FOREIGN KEY (`buyer_user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_buyer_support_support`
        FOREIGN KEY (`support_user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='采购/品检与客服店铺分配';

CREATE TABLE IF NOT EXISTS `order_attachments` (
    `id`              BIGINT        NOT NULL AUTO_INCREMENT,
    `order_id`        BIGINT        NOT NULL,
    `order_item_id`   BIGINT        NULL,
    `attachment_type` VARCHAR(32)   NOT NULL DEFAULT '附件' COMMENT '订单图片/采购凭证/客服截图/品检照片/附件',
    `title`           VARCHAR(255)  NOT NULL DEFAULT '',
    `path`            VARCHAR(1024) NOT NULL DEFAULT '',
    `source`          VARCHAR(64)   NOT NULL DEFAULT '手工登记',
    `uploaded_by`     VARCHAR(128)  NOT NULL DEFAULT '',
    `mime_type`       VARCHAR(128)  NOT NULL DEFAULT '',
    `width`           INT           NOT NULL DEFAULT 0,
    `height`          INT           NOT NULL DEFAULT 0,
    `size_bytes`      BIGINT        NOT NULL DEFAULT 0,
    `size_label`      VARCHAR(64)   NOT NULL DEFAULT '',
    `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `deleted_at`      DATETIME      NULL,
    PRIMARY KEY (`id`),
    KEY `idx_order_attachments_order` (`order_id`),
    KEY `idx_order_attachments_item` (`order_item_id`),
    KEY `idx_order_attachments_type` (`attachment_type`),
    CONSTRAINT `fk_order_attachments_order`
        FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_order_attachments_item`
        FOREIGN KEY (`order_item_id`) REFERENCES `order_items` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='订单图片与附件，承接旧 ph_img';
