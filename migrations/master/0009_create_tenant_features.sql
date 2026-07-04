-- 主库迁移 0009：租户功能授权表 tenant_features
-- 用于 SaaS 超管按租户开关大功能 / 子功能。
-- 规则：超管关闭后，租户侧菜单不展示，直接访问对应路由也会被拦截。

CREATE TABLE IF NOT EXISTS `tenant_features` (
    `id`          BIGINT       NOT NULL AUTO_INCREMENT,
    `tenant_id`   BIGINT       NOT NULL COMMENT '租户 id',
    `feature_key` VARCHAR(64)  NOT NULL COMMENT '功能 key，例如 mail.center / analytics.profit',
    `enabled`     TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '是否开通',
    `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_tenant_feature` (`tenant_id`, `feature_key`),
    KEY `idx_tf_feature_key` (`feature_key`),
    CONSTRAINT `fk_tf_tenant`
        FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci
  COMMENT = '租户功能授权（SaaS 超管控制租户可用模块）';
