-- ============================================================================
-- 客服邮件中心 MySQL 表
-- 覆盖 MysqlStore 邮件中心读写的账号、文件夹、邮件、回复和过滤规则表。
-- ============================================================================

CREATE TABLE IF NOT EXISTS `ph_mail_account` (
    `id`           INT          NOT NULL AUTO_INCREMENT,
    `shop_dpqz`    VARCHAR(100) NOT NULL DEFAULT '' COMMENT '店铺缩写',
    `shop_name`    VARCHAR(255) NOT NULL DEFAULT '' COMMENT '店铺全称',
    `platform`     VARCHAR(8)   NOT NULL DEFAULT '' COMMENT '关联订单平台 w/r/y/m/yp，空=按后缀自动',
    `imap_host`    VARCHAR(255) NOT NULL DEFAULT '',
    `imap_port`    INT          NOT NULL DEFAULT 993,
    `imap_ssl`     TINYINT(1)   NOT NULL DEFAULT 1,
    `imap_user`    VARCHAR(255) NOT NULL DEFAULT '',
    `imap_pass`    VARCHAR(512) NOT NULL DEFAULT '',
    `smtp_host`    VARCHAR(255) NOT NULL DEFAULT '',
    `smtp_port`    INT          NOT NULL DEFAULT 465,
    `smtp_secure`  VARCHAR(10)  NOT NULL DEFAULT 'ssl',
    `smtp_user`    VARCHAR(255) NOT NULL DEFAULT '',
    `smtp_pass`    VARCHAR(512) NOT NULL DEFAULT '',
    `sent_folder`  VARCHAR(255) NOT NULL DEFAULT 'Sent' COMMENT '已发送文件夹',
    `enabled`      TINYINT(1)   NOT NULL DEFAULT 1,
    `sort`         INT          NOT NULL DEFAULT 0 COMMENT '显示排序',
    `last_sync_at` DATETIME     NULL,
    `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_mail_account_shop` (`shop_dpqz`),
    KEY `idx_mail_account_imap_user` (`imap_user`),
    KEY `idx_mail_account_sort` (`sort`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='客服邮件-店铺邮箱账号';

CREATE TABLE IF NOT EXISTS `ph_mail_folder` (
    `id`             INT          NOT NULL AUTO_INCREMENT,
    `account_id`     INT          NOT NULL,
    `shop_dpqz`      VARCHAR(100) NOT NULL DEFAULT '',
    `imap_path`      VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'IMAP 文件夹路径',
    `display_name`   VARCHAR(255) NOT NULL DEFAULT '' COMMENT '系统内显示名',
    `role`           VARCHAR(20)  NULL COMMENT 'inbox/sent/junk/inquiry/notice/custom',
    `sync_enabled`   TINYINT(1)   NOT NULL DEFAULT 0,
    `sort`           INT          NOT NULL DEFAULT 0,
    `last_uid`       BIGINT       NOT NULL DEFAULT 0 COMMENT '增量游标',
    `last_uidnext`   BIGINT       NOT NULL DEFAULT 0 COMMENT '上轮 STATUS 的 UIDNEXT',
    `last_exists`    INT          NOT NULL DEFAULT 0 COMMENT '上轮 STATUS 邮件总数',
    `uidvalidity`    BIGINT       NOT NULL DEFAULT 0 COMMENT 'UIDVALIDITY',
    `backfill_done`  TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '历史回填是否已到最旧',
    `msg_count`      INT          NOT NULL DEFAULT 0,
    `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_mail_folder_account_path` (`account_id`, `imap_path`(150)),
    KEY `idx_mail_folder_account` (`account_id`),
    KEY `idx_mail_folder_sync` (`sync_enabled`),
    KEY `idx_mail_folder_sort` (`account_id`, `sort`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='客服邮件-邮箱文件夹';

CREATE TABLE IF NOT EXISTS `ph_mail_message` (
    `id`             BIGINT        NOT NULL AUTO_INCREMENT,
    `account_id`     INT           NOT NULL,
    `shop_dpqz`      VARCHAR(100)  NOT NULL DEFAULT '',
    `folder_id`      INT           NOT NULL,
    `uid`            BIGINT        NOT NULL COMMENT 'IMAP UID(按文件夹)',
    `message_id`     VARCHAR(512)  NOT NULL DEFAULT '',
    `from_addr`      VARCHAR(320)  NOT NULL DEFAULT '',
    `from_name`      VARCHAR(320)  NOT NULL DEFAULT '',
    `to_addr`        TEXT          NULL,
    `cc_addr`        TEXT          NULL COMMENT '抄送地址，NULL=尚未解析',
    `subject`        VARCHAR(1000) NOT NULL DEFAULT '',
    `body_text`      MEDIUMTEXT    NULL,
    `body_html`      MEDIUMTEXT    NULL,
    `mail_date`      DATETIME      NULL,
    `seen`           TINYINT(1)    NOT NULL DEFAULT 0 COMMENT '原邮箱已读',
    `is_read`        TINYINT(1)    NOT NULL DEFAULT 0 COMMENT '系统内已读',
    `has_attachment` TINYINT(1)    NOT NULL DEFAULT 0,
    `attachments`    TEXT          NULL COMMENT '附件名 JSON',
    `body_loaded`    TINYINT(1)    NOT NULL DEFAULT 0 COMMENT '正文是否已拉取缓存',
    `is_important`   TINYINT(1)    NOT NULL DEFAULT 0 COMMENT '重要标记',
    `is_deleted`     TINYINT(1)    NOT NULL DEFAULT 0 COMMENT '系统内软删除',
    `replied`        TINYINT(1)    NOT NULL DEFAULT 0 COMMENT '是否已回复',
    `created_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_mail_message_folder_uid` (`folder_id`, `uid`),
    KEY `idx_mail_message_shop` (`shop_dpqz`),
    KEY `idx_mail_message_account` (`account_id`),
    KEY `idx_mail_message_date` (`mail_date`),
    KEY `idx_mail_message_read` (`is_read`),
    KEY `idx_mail_message_deleted` (`is_deleted`),
    KEY `idx_mail_message_count` (`is_deleted`, `folder_id`, `is_read`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='客服邮件-邮件';

CREATE TABLE IF NOT EXISTS `ph_mail_reply` (
    `id`          BIGINT        NOT NULL AUTO_INCREMENT,
    `message_id`  BIGINT        NOT NULL COMMENT '原邮件 ph_mail_message.id',
    `account_id`  INT           NOT NULL,
    `to_addr`     VARCHAR(512)  NOT NULL DEFAULT '',
    `cc_addr`     VARCHAR(512)  NOT NULL DEFAULT '' COMMENT '抄送地址',
    `bcc_addr`    VARCHAR(512)  NOT NULL DEFAULT '' COMMENT '密送地址',
    `subject`     VARCHAR(1000) NOT NULL DEFAULT '',
    `body`        MEDIUMTEXT    NULL,
    `operator`    VARCHAR(100)  NOT NULL DEFAULT '' COMMENT '操作客服',
    `success`     TINYINT(1)    NOT NULL DEFAULT 0,
    `error_msg`   TEXT          NULL,
    `appended`    TINYINT(1)    NOT NULL DEFAULT 0 COMMENT '是否写回 Sent',
    `has_attach`  TINYINT(1)    NOT NULL DEFAULT 0 COMMENT '是否带附件',
    `sent_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_mail_reply_message` (`message_id`),
    KEY `idx_mail_reply_account` (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='客服邮件-回复记录';

CREATE TABLE IF NOT EXISTS `ph_mail_rule` (
    `id`                 INT          NOT NULL AUTO_INCREMENT,
    `name`               VARCHAR(100) NOT NULL DEFAULT '' COMMENT '规则名',
    `account_id`         INT          NOT NULL DEFAULT 0 COMMENT '兼容旧字段，0=全部账号',
    `apply_all`          TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1=对全部账号生效',
    `priority`           INT          NOT NULL DEFAULT 0 COMMENT '排序，小的先匹配',
    `enabled`            TINYINT(1)   NOT NULL DEFAULT 1,
    `match_from`         VARCHAR(255) NOT NULL DEFAULT '' COMMENT '发件人包含',
    `match_subject`      VARCHAR(255) NOT NULL DEFAULT '' COMMENT '主题包含',
    `match_to`           VARCHAR(255) NOT NULL DEFAULT '' COMMENT '收件人包含',
    `platforms`          VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '适用平台，逗号分隔',
    `target_folder_id`   INT          NOT NULL DEFAULT 0 COMMENT '兼容旧字段',
    `target_folder_name` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '移动目标文件夹显示名',
    `auto_create_folder` TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '目标文件夹不存在时自动创建',
    `mark_read`          TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '命中标已读',
    `mark_important`     TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '命中标重要',
    `stop_on_match`      TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '命中后停止后续规则',
    `created_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`         DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_mail_rule_enabled` (`enabled`, `priority`, `id`),
    KEY `idx_mail_rule_account` (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='客服邮件-过滤规则';

CREATE TABLE IF NOT EXISTS `ph_mail_rule_account` (
    `rule_id`    INT NOT NULL,
    `account_id` INT NOT NULL,
    PRIMARY KEY (`rule_id`, `account_id`),
    KEY `idx_mail_rule_account_account` (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='客服邮件-规则适用账号';
