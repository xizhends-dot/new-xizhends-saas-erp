-- 主库迁移 0010：租户积分账本
-- 计费单位统一为 pt，不在系统页面展示外部币种。
-- 当前规则：租户新增一个店铺扣除 50pt，超管可手动充值或扣减。

ALTER TABLE `tenant_billing_accounts`
    ADD COLUMN `balance_points` BIGINT NOT NULL DEFAULT 0 COMMENT '租户积分余额，单位 pt' AFTER `tenant_id`;

UPDATE `tenant_billing_accounts`
SET `balance_points` = FLOOR(`balance_cents` / 100)
WHERE `balance_points` = 0 AND `balance_cents` <> 0;

ALTER TABLE `tenant_billing_ledger`
    ADD COLUMN `amount_points` BIGINT NOT NULL DEFAULT 0 COMMENT '积分变动，正数为充值，负数为扣费' AFTER `entry_type`,
    ADD COLUMN `balance_after_points` BIGINT NOT NULL DEFAULT 0 COMMENT '入账后积分余额' AFTER `amount_points`,
    ADD COLUMN `operator` VARCHAR(80) NOT NULL DEFAULT '' COMMENT '操作人' AFTER `note`;

UPDATE `tenant_billing_ledger`
SET
    `amount_points` = FLOOR(`amount_cents` / 100),
    `balance_after_points` = FLOOR(`balance_after_cents` / 100)
WHERE `amount_points` = 0 AND (`amount_cents` <> 0 OR `balance_after_cents` <> 0);

-- 兼容列补默认值：pt 体系上线后代码只写 *_points 列，
-- 旧 cents 列若无默认值会在严格模式（5.7/8.0 默认）下报 1364 拒绝插入。
ALTER TABLE `tenant_billing_accounts`
    MODIFY `balance_cents` BIGINT NOT NULL DEFAULT 0 COMMENT '兼容旧字段：余额 x100';

ALTER TABLE `tenant_billing_ledger`
    MODIFY `amount_cents` BIGINT NOT NULL DEFAULT 0 COMMENT '兼容旧字段：积分变动 x100',
    MODIFY `balance_after_cents` BIGINT NOT NULL DEFAULT 0 COMMENT '兼容旧字段：入账后余额 x100';

CREATE TABLE IF NOT EXISTS `billing_charge_rules` (
    `rule_key` VARCHAR(64) NOT NULL,
    `title` VARCHAR(120) NOT NULL,
    `amount_points` BIGINT NOT NULL DEFAULT 0,
    `enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`rule_key`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci
  COMMENT = '积分扣费规则';

INSERT INTO `billing_charge_rules` (`rule_key`, `title`, `amount_points`, `enabled`)
VALUES ('store.add', '新增店铺', 50, 1)
ON DUPLICATE KEY UPDATE
    `title` = VALUES(`title`),
    `amount_points` = VALUES(`amount_points`),
    `enabled` = VALUES(`enabled`);
