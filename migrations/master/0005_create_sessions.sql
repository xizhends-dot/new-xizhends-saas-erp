-- 主库迁移 0005：会话表 sessions
-- 对应 design.md 认证小节会话定义：
--   principal_kind  super_admin / company_admin / employee
--   principal_id    对应主库 admins.id 或租户 users.id（多态，不设外键）
--   tenant_id       员工/公司管理员所属租户；超管为 NULL
--   token           随机高熵（存哈希更佳）
--   created_at / last_seen_at（滑动续期） / expires_at（如 7 天）
--   ip / user_agent 审计
-- Requirements: 2.2

CREATE TABLE IF NOT EXISTS `sessions` (
    `id`             BIGINT       NOT NULL AUTO_INCREMENT,
    `principal_kind` VARCHAR(16)  NOT NULL COMMENT '主体类型 super_admin/company_admin/employee',
    `principal_id`   BIGINT       NOT NULL COMMENT '主库 admins.id 或租户 users.id（多态）',
    `tenant_id`      BIGINT       NULL COMMENT '所属租户；超管为 NULL',
    `token`          VARCHAR(255) NOT NULL COMMENT '随机高熵会话令牌（建议存哈希）',
    `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_seen_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '滑动续期最近活跃时间',
    `expires_at`     DATETIME     NOT NULL COMMENT '过期时间',
    `ip`             VARCHAR(45)  NULL COMMENT '审计：客户端 IP（兼容 IPv6）',
    `user_agent`     VARCHAR(512) NULL COMMENT '审计：User-Agent',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_sessions_token` (`token`),
    KEY `idx_sessions_principal` (`principal_kind`, `principal_id`),
    KEY `idx_sessions_tenant` (`tenant_id`),
    KEY `idx_sessions_expires_at` (`expires_at`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci
  COMMENT = '服务端会话（多主体登录态与审计）';
