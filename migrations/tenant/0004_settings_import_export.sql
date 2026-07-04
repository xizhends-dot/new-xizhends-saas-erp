-- ============================================================================
-- 租户设置保存与导入导出日志
-- ============================================================================

CREATE TABLE IF NOT EXISTS `tenant_settings` (
    `id`            BIGINT       NOT NULL AUTO_INCREMENT,
    `setting_key`   VARCHAR(64)  NOT NULL COMMENT 'company/orders/profit/logistics/api_1688',
    `setting_value` JSON         NOT NULL COMMENT '结构化租户配置',
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_tenant_settings_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='租户级系统设置';

CREATE TABLE IF NOT EXISTS `import_export_logs` (
    `id`           BIGINT        NOT NULL AUTO_INCREMENT,
    `job_type`     VARCHAR(16)   NOT NULL DEFAULT 'import' COMMENT 'import/export',
    `job_name`     VARCHAR(128)  NOT NULL DEFAULT '',
    `status`       VARCHAR(32)   NOT NULL DEFAULT '',
    `file_name`    VARCHAR(255)  NOT NULL DEFAULT '',
    `row_count`    INT           NOT NULL DEFAULT 0,
    `message`      VARCHAR(512)  NOT NULL DEFAULT '',
    `preview_json` JSON          NULL COMMENT '导入预览前几行',
    `created_by`   VARCHAR(128)  NOT NULL DEFAULT '',
    `created_at`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_import_export_logs_type_time` (`job_type`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='导入导出任务日志';
