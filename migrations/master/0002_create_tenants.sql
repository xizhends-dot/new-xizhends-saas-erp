-- 主库迁移 0002：租户档案表 tenants
-- 对应 design.md 3.8：公司名 / 子域名 / 加密 DSN / 套餐 / 状态 / 员工数 / 创建时间
-- 驱动：超管后台「租户管理」「概览」；租户识别中间件按 subdomain 解析、按 db_dsn_enc 建池
-- Requirements: 10.3, 7.2

CREATE TABLE IF NOT EXISTS `tenants` (
    `id`           BIGINT       NOT NULL AUTO_INCREMENT,
    `company_name` VARCHAR(128) NOT NULL COMMENT '公司名',
    `subdomain`    VARCHAR(64)  NOT NULL COMMENT '子域名（唯一）',
    `db_dsn_enc`   TEXT         NOT NULL COMMENT '独立库 DSN（加密存储）',
    `plan`         VARCHAR(16)  NOT NULL DEFAULT 'basic' COMMENT '套餐 basic/pro/ent',
    `status`       VARCHAR(16)  NOT NULL DEFAULT 'active' COMMENT '状态 active/suspended',
    `staff_count`  INT          NOT NULL DEFAULT 0 COMMENT '员工数（缓存）',
    `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_tenants_subdomain` (`subdomain`),
    KEY `idx_tenants_status` (`status`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci
  COMMENT = '租户档案（多租户路由与建池来源）';
