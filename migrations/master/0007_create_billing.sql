-- 主库迁移 0007：预付费计费表
-- 计费口径：
--   - 全局单价：每个平台账号每月单价，默认 50 RMB = 5000 分；
--   - 租户余额：超管后台手动预存；
--   - 使用判断：余额 >= 当前月预估费用 才允许租户侧进入系统。

CREATE TABLE IF NOT EXISTS `billing_settings` (
    `id`                              TINYINT      NOT NULL,
    `currency`                        VARCHAR(8)   NOT NULL DEFAULT 'RMB',
    `platform_account_unit_cents`      BIGINT       NOT NULL DEFAULT 5000 COMMENT '每个平台账号月单价，单位分',
    `billing_cycle`                   VARCHAR(16)  NOT NULL DEFAULT 'monthly' COMMENT '当前固定 monthly',
    `updated_at`                      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    CONSTRAINT `chk_billing_settings_id` CHECK (`id` = 1),
    CONSTRAINT `chk_billing_settings_unit` CHECK (`platform_account_unit_cents` >= 0)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci
  COMMENT = '计费全局设置（预付费单价）';

INSERT INTO `billing_settings`
    (`id`, `currency`, `platform_account_unit_cents`, `billing_cycle`)
VALUES
    (1, 'RMB', 5000, 'monthly')
ON DUPLICATE KEY UPDATE
    `currency` = VALUES(`currency`);

CREATE TABLE IF NOT EXISTS `tenant_billing_accounts` (
    `tenant_id`       BIGINT      NOT NULL,
    `balance_cents`   BIGINT      NOT NULL DEFAULT 0 COMMENT '预存余额，单位分',
    `updated_at`      DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`tenant_id`),
    CONSTRAINT `fk_tba_tenant`
        FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci
  COMMENT = '租户预付费账户';

INSERT IGNORE INTO `tenant_billing_accounts` (`tenant_id`, `balance_cents`)
SELECT `id`, 0 FROM `tenants`;

CREATE TABLE IF NOT EXISTS `tenant_billing_ledger` (
    `id`                    BIGINT       NOT NULL AUTO_INCREMENT,
    `tenant_id`             BIGINT       NOT NULL,
    `entry_type`            VARCHAR(24)  NOT NULL COMMENT 'recharge/adjustment/charge',
    `amount_cents`          BIGINT       NOT NULL COMMENT '正数为充值，负数为扣费',
    `balance_after_cents`   BIGINT       NOT NULL COMMENT '入账后的余额',
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
  COMMENT = '租户计费流水（预存、调整、扣费）';
