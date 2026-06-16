-- 主库迁移 0004：超级管理员表 admins
-- 对应 design.md 认证小节：超级管理员数据源（主库 admins，tenant_id 为 NULL），
-- 超管后台登录页校验账号 + 口令（Argon2id 哈希）
-- Requirements: 2.2

CREATE TABLE IF NOT EXISTS `admins` (
    `id`             BIGINT       NOT NULL AUTO_INCREMENT,
    `username`       VARCHAR(64)  NOT NULL COMMENT '登录账号（唯一）',
    `password_hash`  VARCHAR(255) NOT NULL COMMENT 'Argon2id 口令哈希',
    `display_name`   VARCHAR(64)  NULL COMMENT '显示名',
    `status`         VARCHAR(16)  NOT NULL DEFAULT 'active' COMMENT '状态 active/disabled',
    `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_login_at`  DATETIME     NULL COMMENT '最近登录时间',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_admins_username` (`username`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci
  COMMENT = '超级管理员（超管后台主体）';
