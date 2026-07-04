-- 主库迁移 0011：店铺月费订阅与欠费停用规则
-- 规则：
--   - 新增店铺立即扣除 50pt；
--   - 从下个月同日开始，每个店铺每月扣除 50pt；
--   - 月费允许余额扣为负数；
--   - 余额达到 -300pt 时自动停用租户。

INSERT INTO `billing_charge_rules` (`rule_key`, `title`, `amount_points`, `enabled`)
VALUES
    ('store.monthly', '店铺月费', 50, 1),
    ('tenant.debt_suspend', '租户欠费停用线', -300, 1)
ON DUPLICATE KEY UPDATE
    `title` = VALUES(`title`),
    `amount_points` = VALUES(`amount_points`),
    `enabled` = VALUES(`enabled`);

CREATE TABLE IF NOT EXISTS `tenant_billing_subscriptions` (
    `id`              BIGINT       NOT NULL AUTO_INCREMENT,
    `tenant_id`       BIGINT       NOT NULL,
    `store_id`        BIGINT       NOT NULL,
    `store_name`      VARCHAR(180) NOT NULL DEFAULT '',
    `amount_points`   BIGINT       NOT NULL DEFAULT 50,
    `cycle`           VARCHAR(16)  NOT NULL DEFAULT 'monthly',
    `billing_day`     TINYINT      NOT NULL DEFAULT 1,
    `next_charge_at`  DATE         NOT NULL,
    `last_charge_at`  DATE         NULL,
    `status`          VARCHAR(16)  NOT NULL DEFAULT 'active',
    `note`            VARCHAR(255) NOT NULL DEFAULT '',
    `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_tbs_tenant_store` (`tenant_id`, `store_id`),
    KEY `idx_tbs_due` (`status`, `next_charge_at`),
    CONSTRAINT `fk_tbs_tenant`
        FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `chk_tbs_amount` CHECK (`amount_points` >= 0),
    CONSTRAINT `chk_tbs_day` CHECK (`billing_day` BETWEEN 1 AND 31)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci
  COMMENT = '租户店铺月费订阅';
