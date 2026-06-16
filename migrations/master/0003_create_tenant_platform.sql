-- 主库迁移 0003：租户↔平台授权表 tenant_platform
-- 对应 design.md 3.8：每租户每平台一条（enabled / locked）
-- 驱动：租户侧栏平台菜单的显示/锁定；超管后台「平台授权」开关
-- 渲染规则：enabled=true & locked=false → 正常可点；locked=true → 灰显锁定；enabled=false → 不出现
-- Requirements: 7.2

CREATE TABLE IF NOT EXISTS `tenant_platform` (
    `id`            BIGINT      NOT NULL AUTO_INCREMENT,
    `tenant_id`     BIGINT      NOT NULL COMMENT '租户 id',
    `platform_code` VARCHAR(16) NOT NULL COMMENT '平台代码（引用 platforms.code）',
    `enabled`       TINYINT(1)  NOT NULL DEFAULT 0 COMMENT '已开通',
    `locked`        TINYINT(1)  NOT NULL DEFAULT 0 COMMENT '锁定（灰显不可用）',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_tenant_platform` (`tenant_id`, `platform_code`),
    KEY `idx_tp_platform_code` (`platform_code`),
    CONSTRAINT `fk_tp_tenant`
        FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_tp_platform`
        FOREIGN KEY (`platform_code`) REFERENCES `platforms` (`code`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci
  COMMENT = '租户平台授权（驱动侧栏渲染三态）';
