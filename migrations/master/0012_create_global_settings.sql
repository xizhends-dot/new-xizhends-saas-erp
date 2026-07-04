-- 主库迁移 0012：SaaS 平台全局设置
-- 归属超管：物流编号对照表、ShowAPI 配置、轮循代理。

CREATE TABLE IF NOT EXISTS `global_settings` (
    `setting_key`   VARCHAR(64) NOT NULL,
    `setting_value` MEDIUMTEXT  NOT NULL,
    `created_at`    DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`setting_key`),
    CONSTRAINT `chk_global_settings_json`
        CHECK (JSON_VALID(`setting_value`))
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci
  COMMENT = 'SaaS 超管全局设置';
