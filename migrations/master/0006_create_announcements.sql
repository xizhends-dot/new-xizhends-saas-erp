-- 主库迁移 0006：系统公告表 announcements
-- 对应 design.md 超管后台「系统公告」：发布全局/指定租户公告（标题 / 类型 / 可见范围 / 内容），已发布列表
-- 可见范围 scope：global（全局）/ tenant（指定租户，由 tenant_id 限定）
-- Requirements: 10.3

CREATE TABLE IF NOT EXISTS `announcements` (
    `id`           BIGINT       NOT NULL AUTO_INCREMENT,
    `title`        VARCHAR(255) NOT NULL COMMENT '标题',
    `kind`         VARCHAR(32)  NOT NULL DEFAULT 'info' COMMENT '类型 info/warning/maintenance 等',
    `scope`        VARCHAR(16)  NOT NULL DEFAULT 'global' COMMENT '可见范围 global/tenant',
    `tenant_id`    BIGINT       NULL COMMENT 'scope=tenant 时指定的租户；global 时为 NULL',
    `content`      MEDIUMTEXT   NOT NULL COMMENT '公告内容',
    `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_announcements_scope` (`scope`),
    KEY `idx_announcements_tenant` (`tenant_id`),
    KEY `idx_announcements_created_at` (`created_at`),
    CONSTRAINT `fk_announcements_tenant`
        FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci
  COMMENT = '系统公告（超管发布全局/指定租户）';
