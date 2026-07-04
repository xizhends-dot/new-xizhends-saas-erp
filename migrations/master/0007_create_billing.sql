-- 主库迁移 0007：租户积分计费表
-- 计费口径：
--   - 系统内部统一使用 pt，不在产品页面展示外部币种；
--   - 租户积分由超管后台手动充值或扣减；
--   - 当前默认规则：新增一个店铺扣除 50pt。
CREATE TABLE IF NOT EXISTS `billing_settings` (
    `id`                              TINYINT      NOT NULL,
    `currency`                        VARCHAR(8)   NOT NULL DEFAULT 'PT',
    `platform_account_unit_cents`      BIGINT       NOT NULL DEFAULT 5000 COMMENT '兼容旧字段：积分数量 x100',
    `billing_cycle`                   VARCHAR(16)  NOT NULL DEFAULT 'manual' COMMENT '当前固定 manual',
    `updated_at`                      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    CONSTRAINT `chk_billing_settings_id` CHECK (`id` = 1),
    CONSTRAINT `chk_billing_settings_unit` CHECK (`platform_account_unit_cents` >= 0)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci
  COMMENT = '计费全局设置（积分扣费）';

INSERT INTO `billing_settings`
    (`id`, `currency`, `platform_account_unit_cents`, `billing_cycle`)
VALUES
    (1, 'PT', 5000, 'manual')
ON DUPLICATE KEY UPDATE
    `currency` = VALUES(`currency`),
    `billing_cycle` = VALUES(`billing_cycle`);

CREATE TABLE IF NOT EXISTS `tenant_billing_accounts` (
    `tenant_id`        BIGINT      NOT NULL,
    `balance_cents`    BIGINT      NOT NULL DEFAULT 0 COMMENT '兼容旧字段：积分余额 x100',
    `updated_at`       DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`tenant_id`),
    CONSTRAINT `fk_tba_tenant`
        FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci
  COMMENT = '租户积分账户';

INSERT IGNORE INTO `tenant_billing_accounts` (`tenant_id`, `balance_cents`)
SELECT `id`, 0 FROM `tenants`;

CREATE TABLE IF NOT EXISTS `tenant_billing_ledger` (
    `id`                    BIGINT       NOT NULL AUTO_INCREMENT,
    `tenant_id`             BIGINT       NOT NULL,
    `entry_type`            VARCHAR(24)  NOT NULL COMMENT 'recharge/adjustment/charge',
    `amount_cents`          BIGINT       NOT NULL COMMENT '兼容旧字段：积分变动 x100，正数为充值，负数为扣费',
    `balance_after_cents`   BIGINT       NOT NULL COMMENT '兼容旧字段：入账后积分余额 x100',
    `note`                  VARCHAR(255) NULL,
    `created_at`            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_tbl_tenant_created` (`tenant_id`, `created_at`),
    CONSTRAINT `fk_tbl_tenant`
        FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci
  COMMENT = '租户积分流水（充值、调整、扣费）';
